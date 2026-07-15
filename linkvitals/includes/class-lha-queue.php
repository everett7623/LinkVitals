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
     * @return int|false Inserted/existing queue item ID, or false on failure.
     */
    public function add( string $object_type, int $object_id, string $object_url = '', int $priority = 5 ): int|false {
        global $wpdb;

        $now = current_time( 'mysql' );

        // Avoid duplicates: check for existing pending or processing item.
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
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
        );

        return $result ? (int) $wpdb->insert_id : false;
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

        $now = current_time( 'mysql' );

        // Select pending items ordered by priority and creation time.
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY priority ASC, created_at ASC LIMIT %d",
                'pending',
                $batch_size
            ),
            ARRAY_A
        );

        if ( empty( $items ) ) {
            return array();
        }

        // Atomically mark retrieved items as 'processing'.
        $ids         = array_column( $items, 'id' );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET status = 'processing', updated_at = %s WHERE id IN ({$placeholders})",
                $now,
                ...$ids
            )
        );

        // Update the status in returned items to reflect the transition.
        foreach ( $items as &$item ) {
            $item['status']     = 'processing';
            $item['updated_at'] = $now;
        }
        unset( $item );

        return $items;
    }

    /**
     * Update the status of a queue item.
     *
     * @param int    $id     Queue item ID.
     * @param string $status New status (pending, processing, done, failed, paused).
     * @return bool True on success, false on failure.
     */
    public function update_status( int $id, string $status ): bool {
        global $wpdb;

        return (bool) $wpdb->update(
            $this->table,
            array(
                'status'     => $status,
                'updated_at' => current_time( 'mysql' ),
            ),
            array( 'id' => $id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Increment the attempt counter for a queue item and handle failure threshold.
     *
     * If attempts reach MAX_ATTEMPTS (3), the item is permanently marked as 'failed'.
     * Otherwise, it is returned to 'pending' for retry.
     *
     * @param int    $id    Queue item ID.
     * @param string $error Error message to record.
     * @return void
     */
    public function increment_attempts( int $id, string $error = '' ): void {
        global $wpdb;

        $now = current_time( 'mysql' );

        // Increment attempts and set error.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET attempts = attempts + 1, last_error = %s, updated_at = %s WHERE id = %d",
                $error,
                $now,
                $id
            )
        );

        // Get current attempts count.
        $attempts = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attempts FROM {$this->table} WHERE id = %d",
                $id
            )
        );

        // Mark as failed if threshold reached, otherwise return to pending.
        if ( $attempts >= self::MAX_ATTEMPTS ) {
            $wpdb->update(
                $this->table,
                array(
                    'status'     => 'failed',
                    'updated_at' => $now,
                ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $wpdb->update(
                $this->table,
                array(
                    'status'     => 'pending',
                    'updated_at' => $now,
                ),
                array( 'id' => $id ),
                array( '%s', '%s' ),
                array( '%d' )
            );
        }
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
                "UPDATE {$this->table} SET status = 'pending', updated_at = %s WHERE status = 'processing' AND updated_at < %s",
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
