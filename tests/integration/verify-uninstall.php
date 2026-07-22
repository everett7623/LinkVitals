<?php
/**
 * Verify state after wp plugin uninstall removes the temporary plugin copy.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "WordPress must be loaded before verifying uninstall.\n" );
    exit( 1 );
}

/** Fail when uninstall left plugin state behind. */
function lha_integration_assert_removed( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

global $wpdb;

foreach ( array( 'links', 'occurrences', 'queue', 'logs', 'repairs' ) as $suffix ) {
    $table = $wpdb->prefix . 'lha_' . $suffix;
    $found = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
    );
    lha_integration_assert_removed( null === $found, "Uninstall left {$table} behind." );
}

foreach (
    array(
        'lha_settings',
        'lha_version',
        'lha_scan_status',
        'lha_last_scan_time',
        'lha_scan_started_at',
        'lha_scan_type',
        'lha_content_scan_cursor',
        'lha_notification_lock',
    ) as $option_name
) {
    lha_integration_assert_removed( false === get_option( $option_name, false ), "Uninstall left {$option_name} behind." );
}

foreach (
    array(
        'lha_broken_notice_shown',
        'lha_notice_check',
        'lha_pre_scan_broken_count',
        'lha_ai_job_integration',
    ) as $transient_name
) {
    lha_integration_assert_removed( false === get_transient( $transient_name ), "Uninstall left {$transient_name} behind." );
}

lha_integration_assert_removed( false === wp_next_scheduled( 'lha_process_queue' ), 'Deactivation left the queue event scheduled.' );

echo "LinkVitals uninstall integration checks passed.\n";
