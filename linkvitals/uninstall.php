<?php
/**
 * Uninstall handler for LinkVitals
 *
 * Fired when the plugin is deleted via WordPress admin.
 * Respects user setting for data deletion.
 * Handles both single-site and multisite installations.
 *
 * @package LinkVitals
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove all plugin data for the current site.
 *
 * Drops custom tables, deletes options, and clears transients.
 */
function lha_uninstall_site_data() {
    global $wpdb;

    // Drop custom tables.
    $tables = array(
        $wpdb->prefix . 'lha_links',
        $wpdb->prefix . 'lha_occurrences',
        $wpdb->prefix . 'lha_queue',
        $wpdb->prefix . 'lha_logs',
        $wpdb->prefix . 'lha_repairs',
    );

    foreach ( $tables as $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    // Delete all lha_ prefixed options.
    delete_option( 'lha_settings' );
    delete_option( 'lha_version' );
    delete_option( 'lha_scan_status' );
    delete_option( 'lha_last_scan_time' );
    delete_option( 'lha_scan_started_at' );
    delete_option( 'lha_scan_type' );
    delete_option( 'lha_content_scan_cursor' );
    delete_option( 'lha_notification_lock' );

    // Delete transients.
    delete_transient( 'lha_broken_notice_shown' );
    delete_transient( 'lha_notice_check' );
    delete_transient( 'lha_pre_scan_broken_count' );

    // Delete dynamic AI job, active-job, and per-user index transients.
    $transient_prefix = '_transient_lha_ai_';
    $timeout_prefix   = '_transient_timeout_lha_ai_';
    $option_names     = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like( $transient_prefix ) . '%',
            $wpdb->esc_like( $timeout_prefix ) . '%'
        )
    );
    foreach ( $option_names ?: array() as $option_name ) {
        $prefix = str_starts_with( $option_name, $timeout_prefix ) ? '_transient_timeout_' : '_transient_';
        delete_transient( substr( $option_name, strlen( $prefix ) ) );
    }
}

// Load settings to check if user wants data deleted.
$settings    = get_option( 'lha_settings', array() );
$delete_data = isset( $settings['delete_data_on_uninstall'] ) ? (bool) $settings['delete_data_on_uninstall'] : false;

// Handle multisite: loop through all sites.
if ( is_multisite() ) {
    global $wpdb;

    // Get all site IDs in the network.
    $site_ids = get_sites( array(
        'fields' => 'ids',
        'number' => 0,
    ) );

    foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );

        // Check per-site setting as well.
        $site_settings    = get_option( 'lha_settings', array() );
        $site_delete_data = isset( $site_settings['delete_data_on_uninstall'] ) ? (bool) $site_settings['delete_data_on_uninstall'] : false;

        if ( $site_delete_data ) {
            lha_uninstall_site_data();
        }

        restore_current_blog();
    }
} else {
    // Single site: clean up directly.
    if ( $delete_data ) {
        lha_uninstall_site_data();
    }
}
