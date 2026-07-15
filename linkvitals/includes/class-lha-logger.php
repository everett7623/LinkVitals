<?php
/**
 * Logger class
 *
 * Records audit trail entries for scan events, repairs, and other actions
 * to the wp_lha_logs table.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Logger {

    /**
     * Valid action types for log entries.
     *
     * @var array<string>
     */
    private const ACTION_TYPES = array(
        'scan_started',
        'scan_completed',
        'url_replaced',
        'link_unlinked',
        'repair_rolled_back',
        'repair_history_purged',
        'link_ignored',
        'link_unignored',
        'bulk_replace',
        'scheduled_scan',
        'notification',
    );

    /**
     * Write a log entry to the audit trail.
     *
     * @param string $action_type Type of action (must be one of the valid action types).
     * @param string $url         Affected URL.
     * @param string $old_value   Previous value (for replacements).
     * @param string $new_value   New value (for replacements).
     * @param array  $object_ids  Array of affected WordPress object IDs.
     * @param string $message     Human-readable description.
     */
    public static function log(
        string $action_type,
        string $url = '',
        string $old_value = '',
        string $new_value = '',
        array $object_ids = array(),
        string $message = ''
    ): void {
        global $wpdb;

        $table = LHA_DB::table( 'logs' );

        $wpdb->insert(
            $table,
            array(
                'action_type' => sanitize_key( $action_type ),
                'url'         => esc_url_raw( $url ),
                'old_value'   => $old_value,
                'new_value'   => $new_value,
                'object_ids'  => wp_json_encode( array_map( 'absint', $object_ids ) ),
                'message'     => sanitize_text_field( $message ),
                'user_id'     => get_current_user_id(),
                'created_at'  => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * Get paginated log entries in reverse chronological order.
     *
     * @param int $page     Page number (1-based).
     * @param int $per_page Number of entries per page. Default 50.
     * @return array{items: array, total: int} Paginated result with items and total count.
     */
    public static function get_logs( int $page = 1, int $per_page = 50 ): array {
        global $wpdb;

        $table    = LHA_DB::table( 'logs' );
        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    /**
     * Get valid action types.
     *
     * @return array<string> List of supported action type constants.
     */
    public static function get_action_types(): array {
        return self::ACTION_TYPES;
    }

    /**
     * Clear old log entries (retention policy).
     *
     * @param int $days Keep logs for this many days.
     * @return int Number of deleted entries.
     */
    public static function cleanup( int $days = 90 ): int {
        global $wpdb;

        $table  = LHA_DB::table( 'logs' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoff
            )
        );

        return (int) $result;
    }
}
