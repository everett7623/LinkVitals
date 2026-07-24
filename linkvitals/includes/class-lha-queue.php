<?php
/**
 * Queue management class
 *
 * Manages the scan queue for batch processing.
 * Supports pending, processing, done, failed, and paused states.
 *
 * @package LinkVitals
 * @requires PHP 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Queue {

    /**
     * Maximum retry attempts before marking an item as failed.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Minutes after which a processing item is considered stuck.
     */
    private const STUCK_THRESHOLD_MINUTES = 10;

    /**
     * Maximum rows written by one queue INSERT statement.
     */
    private const INSERT_BATCH_SIZE = 100;

    /**
     * Fully-qualified queue table name.
     */
    private string $table;

    public function __construct() {
        $this->table = LHA_DB::table( 'queue' );
    }

    /**
     * Add an item to the queue.
     *
     * Checks for an existing pending/processing entry for the same object
     * to avoid duplicates. If one exists, returns its ID instead of inserting.
     *
     * @param string $object_type Content object type (post, page, nav_menu_item, term).
     * @param int    $object_id   WordPress object ID.
     * @param string $object_url  Permalink or URL of the object.
     * @param int    $priority    Priority 0-9, lower = higher priority.
     * @param bool   $check_existing Whether to check for active duplicate work.
     * @return int|false Inserted/existing queue item ID, or false on failure.
     */
    public function add( string $object_type, int $object_id, string $object_url = '', int $priority = 5, bool $check_existing = true ): int|false {
        global $wpdb;

        $now = current_time( 'mysql' );

        if ( $check_existing ) {
            // Avoid duplicates when adding work to an active queue.
            $existing = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT id FROM {$this->table} WHERE object_type = %s AND object_id = %d AND status IN ('pending', 'processing')",
                    $object_type,
                    $object_id
                )
            );

            if ( $existing ) {
                return (int) $existing;
            }
        }

        $result = $wpdb->insert(
            $this->table,
            array(
                'object_type' => $object_type,
                'object_id'   => $object_id,
                'object_url'  => $object_url,
                'status'      => 'pending',
                'priority'    => $priority,
                'attempts'    => 0,
                'last_error'  => '',
                'claim_token' => '',
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Add multiple queue items with bounded multi-row inserts.
     *
     * Duplicate-sensitive callers retain the existing per-item lookup. A full
     * scan can skip it after clearing the queue and write each chunk at once.
     *
     * @param array $items Queue rows containing object_type and object_id.
     * @param bool  $check_existing Whether to check for active duplicate work.
     * @return int Number of items accepted or inserted.
     */
    public function add_many( array $items, bool $check_existing = true ): int {
        global $wpdb;

        $items = array_values( array_filter( $items, 'is_array' ) );
        if ( empty( $items ) ) {
            return 0;
        }

        if ( $check_existing ) {
            $accepted = 0;
            foreach ( $items as $item ) {
                $result = $this->add(
                    (string) ( $item['object_type'] ?? '' ),
                    (int) ( $item['object_id'] ?? 0 ),
                    (string) ( $item['object_url'] ?? '' ),
                    (int) ( $item['priority'] ?? 5 )
                );

                if ( false !== $result ) {
                    $accepted++;
                }
            }

            return $accepted;
        }

        $inserted = 0;
        $now      = current_time( 'mysql' );

        foreach ( array_chunk( $items, self::INSERT_BATCH_SIZE ) as $chunk ) {
            $placeholders = array();
            $values       = array();

            foreach ( $chunk as $item ) {
                $placeholders[] = '(%s, %d, %s, %s, %d, %d, %s, %s, %s, %s)';
                array_push(
                    $values,
                    (string) ( $item['object_type'] ?? '' ),
                    (int) ( $item['object_id'] ?? 0 ),
                    (string) ( $item['object_url'] ?? '' ),
                    'pending',
                    (int) ( $item['priority'] ?? 5 ),
                    0,
                    '',
                    '',
                    $now,
                    $now
                );
            }

            $query = "INSERT INTO {$this->table}
                (object_type, object_id, object_url, status, priority, attempts, last_error, claim_token, created_at, updated_at)
                VALUES " . implode( ', ', $placeholders );

            $result = $wpdb->query( $wpdb->prepare( $query, ...$values ) );
            if ( false !== $result ) {
                $inserted += (int) $result;
            }
        }

        return $inserted;
    }

    /**
     * Retrieve pending items and atomically mark them as processing.
     *
     * Items are ordered by priority ASC (lower number = higher priority),
     * then by created_at ASC (FIFO within same priority).
     *
     * @param int $batch_size Number of items to retrieve (default 20).
     * @return array Array of queue item rows (ARRAY_A).
     */
    public function get_pending( int $batch_size = 20 ): array {
        global $wpdb;

        $batch_size  = max( 1, min( 100, $batch_size ) );
        $now         = current_time( 'mysql' );
        $claim_token = wp_generate_uuid4();

        // A single ordered UPDATE prevents concurrent workers from claiming
        // the same rows. The token then identifies only this worker's batch.
        $claimed = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = 'processing', claim_token = %s, updated_at = %s
                 WHERE status = 'pending'
                 ORDER BY priority ASC, created_at ASC, id ASC
                 LIMIT %d",
                $claim_token,
                $now,
                $batch_size
            )
        );

        if ( ! $claimed ) {
            return array();
        }

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = 'processing' AND claim_token = %s
                 ORDER BY priority ASC, created_at ASC, id ASC",
                $claim_token
            ),
            ARRAY_A
        );

        return $items ?: array();
    }

    /**
     * Update the status of a queue item.
     *
     * @param int    $id          Queue item ID.
     * @param string $status      New status (pending, processing, done, failed, paused).
     * @param string $claim_token Claim token returned when this item was claimed.
     * @return bool True on success, false on failure.
     */
    public function update_status( int $id, string $status, string $claim_token ): bool {
        global $wpdb;

        // A reclaimed item must only be completed by the worker that owns it.
        return (bool) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = %s, claim_token = '', updated_at = %s
                 WHERE id = %d AND status = 'processing' AND claim_token = %s",
                $status,
                current_time( 'mysql' ),
                $id,
                $claim_token
            )
        );
    }

    /**
     * Increment the attempt counter for a queue item and handle failure threshold.
     *
     * If attempts reach MAX_ATTEMPTS (3), the item is permanently marked as 'failed'.
     * Otherwise, it is returned to 'pending' for retry.
     *
     * @param int    $id          Queue item ID.
     * @param string $error       Error message to record.
     * @param string $claim_token Claim token returned when this item was claimed.
     * @return bool True when this worker recorded the retry, false when it lost its claim.
     */
    public function increment_attempts( int $id, string $error, string $claim_token ): bool {
        global $wpdb;

        $now = current_time( 'mysql' );

        // This fenced transition prevents a stale worker from overwriting a
        // newer claim, and avoids a read/update race around the attempt limit.
        return (bool) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table}
                 SET status = CASE WHEN attempts + 1 >= %d THEN 'failed' ELSE 'pending' END,
                     attempts = attempts + 1,
                     last_error = %s,
                     claim_token = '',
                     updated_at = %s
                 WHERE id = %d AND status = 'processing' AND claim_token = %s",
                self::MAX_ATTEMPTS,
                $error,
                $now,
                $id,
                $claim_token
            )
        );
    }

    /**
     * Get counts of queue items grouped by status.
     *
     * @return array Associative array with keys: pending, processing, done, failed, paused.
     */
    public function get_counts(): array {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
            ARRAY_A
        );

        $counts = array(
            'pending'    => 0,
            'processing' => 0,
            'done'       => 0,
            'failed'     => 0,
            'paused'     => 0,
        );

        if ( $results ) {
            foreach ( $results as $row ) {
                if ( array_key_exists( $row['status'], $counts ) ) {
                    $counts[ $row['status'] ] = (int) $row['count'];
                }
            }
        }

        return $counts;
    }

    /**
     * Clear the entire queue table.
     *
     * Used when starting a full scan to reset the queue completely.
     *
     * @return bool True on success, false on failure.
     */
    public function clear(): bool {
        global $wpdb;

        $result = $wpdb->query( "TRUNCATE TABLE {$this->table}" );

        return false !== $result;
    }

    /**
     * Reset items stuck in 'processing' state back to 'pending'.
     *
     * An item is considered stuck if it has been in 'processing' status
     * for longer than the stuck threshold (default 10 minutes).
     *
     * @param int $minutes Number of minutes after which an item is considered stuck.
     * @return int Number of items reset.
     */
    public function reset_stuck( int $minutes = self::STUCK_THRESHOLD_MINUTES ): int {
        global $wpdb;

        $now    = current_time( 'mysql' );
        $cutoff = gmdate( 'Y-m-d H:i:s', strtotime( $now ) - ( $minutes * 60 ) );

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET status = 'pending', claim_token = '', updated_at = %s WHERE status = 'processing' AND updated_at < %s",
                $now,
                $cutoff
            )
        );

        return (int) $result;
    }

    /**
     * Get the currently processing item (for progress display).
     *
     * @return array|null Queue item row or null if none processing.
     */
    public function get_current(): ?array {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY updated_at DESC LIMIT 1",
                'processing'
            ),
            ARRAY_A
        );

        return $result ?: null;
    }
}
