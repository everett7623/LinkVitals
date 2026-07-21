<?php
/**
 * Plugin deactivation handler
 *
 * Cleans up scheduled events on deactivation.
 * Does NOT delete data - that happens only on uninstall.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Deactivator {

    /**
     * Run deactivation routines
     *
     * Clears all scheduled cron hooks and stops any running scan.
     * Does NOT delete data — that only happens via uninstall.php.
     */
    public static function deactivate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            $site_ids = get_sites(
                array(
                    'fields' => 'ids',
                    'number' => 0,
                )
            );

            foreach ( $site_ids as $site_id ) {
                switch_to_blog( (int) $site_id );
                try {
                    self::deactivate_single_site();
                } finally {
                    restore_current_blog();
                }
            }
            return;
        }

        self::deactivate_single_site();
    }

    /** Stop plugin work for the current site without deleting stored data. */
    private static function deactivate_single_site(): void {
        // Clear all scheduled cron events.
        wp_clear_scheduled_hook( 'lha_process_queue' );
        wp_clear_scheduled_hook( 'lha_scheduled_scan' );
        wp_unschedule_hook( LHA_AI_Jobs::HOOK );

        // Stop any running scan by setting status to idle.
        update_option( 'lha_scan_status', 'idle' );
        LHA_Cron::reset_notification_tracking();
    }
}
