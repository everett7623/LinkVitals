<?php
/**
 * Plugin activation handler
 *
 * Creates database tables and sets default options on activation.
 * Handles both single-site and multisite network activation.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Activator {

    /**
     * Run activation routines.
     *
     * Handles multisite network activation by looping through all sites.
     *
     * @param bool $network_wide Whether the plugin is being activated network-wide.
     */
    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            self::activate_network();
        } else {
            self::activate_single_site();
        }
    }

    /**
     * Activate plugin across all sites in a multisite network.
     */
    private static function activate_network(): void {
        global $wpdb;

        // Get all site IDs in the network.
        $site_ids = get_sites( array(
            'fields'  => 'ids',
            'number'  => 0,
        ) );

        foreach ( $site_ids as $site_id ) {
            switch_to_blog( $site_id );
            self::activate_single_site();
            restore_current_blog();
        }
    }

    /**
     * Run activation routines for a single site.
     */
    private static function activate_single_site(): void {
        self::create_tables();
        self::set_default_options();
        self::schedule_cron();

        // Store version.
        update_option( 'lha_version', LHA_VERSION );

        // Set scan status to idle if not already set.
        if ( false === get_option( 'lha_scan_status' ) ) {
            add_option( 'lha_scan_status', 'idle' );
        }
    }

    /**
     * Create custom database tables via LHA_DB.
     */
    private static function create_tables(): void {
        LHA_DB::create_tables();
    }

    /**
     * Set default plugin settings.
     */
    private static function set_default_options(): void {
        $defaults = array(
            'auto_scan'              => 0,
            'scan_frequency'         => 'weekly',
            'batch_size'             => 20,
            'http_timeout'           => 8,
            'max_redirects'          => 5,
            'check_external'         => 1,
            'check_images'           => 1,
            'check_media'            => 1,
            'check_anchors'          => 0,
            'check_nofollow'         => 0,
            'ignore_domains'         => '',
            'ignore_patterns'        => '',
            'email_notifications'    => 0,
            'notification_email'     => get_option( 'admin_email' ),
            'delete_data_on_uninstall' => 0,
            'repair_history_retention_days' => 180,
            'language'               => 'auto',
            'language_manually_selected' => 0,
        );

        $existing = get_option( 'lha_settings' );

        if ( false === $existing ) {
            add_option( 'lha_settings', $defaults );
            return;
        }

        if ( is_array( $existing ) ) {
            $merged = array_merge( $defaults, $existing );
            if ( $merged !== $existing ) {
                update_option( 'lha_settings', $merged );
            }
        }
    }

    /**
     * Schedule cron events.
     */
    private static function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'lha_process_queue' ) ) {
            wp_schedule_event( time(), 'lha_every_5_minutes', 'lha_process_queue' );
        }
    }
}
