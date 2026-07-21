<?php
/**
 * Verify network deactivation stopped work on every site.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "WordPress must be loaded before verifying deactivation.\n" );
    exit( 1 );
}

/** Fail when network deactivation leaves active site state behind. */
function lha_multisite_assert_deactivated( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

/** Check every argument variant for a hook in the current site's Cron array. */
function lha_multisite_has_scheduled_hook( string $hook ): bool {
    foreach ( _get_cron_array() as $timestamp_events ) {
        if ( isset( $timestamp_events[ $hook ] ) ) {
            return true;
        }
    }

    return false;
}

$expectations = get_site_option( 'lha_integration_multisite_expectations', array() );
$site_ids = array_merge( $expectations['delete'] ?? array(), $expectations['preserve'] ?? array() );
lha_multisite_assert_deactivated( count( $site_ids ) >= 3, 'Multisite expectations are missing.' );

foreach ( $site_ids as $site_id ) {
    switch_to_blog( (int) $site_id );
    try {
        lha_multisite_assert_deactivated( 'idle' === get_option( 'lha_scan_status' ), "Site {$site_id} scan did not stop." );
        lha_multisite_assert_deactivated( false === wp_next_scheduled( 'lha_process_queue' ), "Site {$site_id} queue Cron remains." );
        lha_multisite_assert_deactivated( false === wp_next_scheduled( 'lha_scheduled_scan' ), "Site {$site_id} scheduled scan remains." );
        lha_multisite_assert_deactivated( ! lha_multisite_has_scheduled_hook( 'lha_process_ai_orphan_job' ), "Site {$site_id} AI Cron remains." );
        lha_multisite_assert_deactivated( false === get_option( 'lha_notification_lock', false ), "Site {$site_id} notification lock remains." );
        lha_multisite_assert_deactivated( false === get_transient( 'lha_pre_scan_broken_count' ), "Site {$site_id} notification baseline remains." );
        lha_multisite_assert_deactivated( false !== get_transient( 'lha_ai_job_multisite' ), "Site {$site_id} AI fixture disappeared before uninstall." );
    } finally {
        restore_current_blog();
    }
}

echo "LinkVitals multisite deactivation checks passed.\n";
