<?php
/**
 * Verify network activation and new-site provisioning before deactivation.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "WordPress must be loaded before running multisite tests.\n" );
    exit( 1 );
}

/** Fail the multisite integration run when a contract is not satisfied. */
function lha_multisite_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

lha_multisite_assert( is_multisite(), 'The integration installation is not multisite.' );

$sites = get_sites(
    array(
        'number'  => 0,
        'orderby' => 'id',
        'order'   => 'ASC',
    )
);
lha_multisite_assert( count( $sites ) >= 3, 'Expected the main site and two subsite fixtures.' );

$expectations = array(
    'delete'   => array(),
    'preserve' => array(),
);

foreach ( $sites as $site ) {
    switch_to_blog( (int) $site->blog_id );
    try {
        global $wpdb;

        lha_multisite_assert( get_option( 'lha_version' ) === LHA_VERSION, "Site {$site->blog_id} is missing the plugin version." );
        lha_multisite_assert( is_array( get_option( 'lha_settings', false ) ), "Site {$site->blog_id} is missing settings." );
        lha_multisite_assert( false !== wp_next_scheduled( 'lha_process_queue' ), "Site {$site->blog_id} is missing queue Cron." );

        foreach ( array( 'links', 'occurrences', 'queue', 'logs', 'repairs' ) as $suffix ) {
            $table = $wpdb->prefix . 'lha_' . $suffix;
            $found = $wpdb->get_var(
                $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
            );
            lha_multisite_assert( $found === $table, "Site {$site->blog_id} is missing {$table}." );
        }

        $preserve = '/before-activation/' === $site->path;
        $settings = get_option( 'lha_settings', array() );
        $settings['delete_data_on_uninstall'] = $preserve ? 0 : 1;
        update_option( 'lha_settings', $settings );
        update_option( 'lha_scan_status', 'running' );
        update_option( 'lha_notification_lock', time() );
        wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'lha_scheduled_scan' );
        wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'lha_process_ai_orphan_job', array( 'integration-job' ) );
        set_transient( 'lha_pre_scan_broken_count', 1, HOUR_IN_SECONDS );
        set_transient( 'lha_ai_job_multisite', array( 'site_id' => (int) $site->blog_id ), HOUR_IN_SECONDS );

        $bucket = $preserve ? 'preserve' : 'delete';
        $expectations[ $bucket ][] = (int) $site->blog_id;
    } finally {
        restore_current_blog();
    }
}

lha_multisite_assert( 1 === count( $expectations['preserve'] ), 'Exactly one site must preserve plugin data.' );
lha_multisite_assert( count( $expectations['delete'] ) >= 2, 'The main and post-activation sites must delete plugin data.' );
update_site_option( 'lha_integration_multisite_expectations', $expectations );

echo "LinkVitals multisite activation checks passed.\n";
