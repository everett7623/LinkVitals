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
    public static function deactivate(): void {
        // Clear all scheduled cron events
        wp_clear_scheduled_hook( 'lha_process_queue' );
        wp_clear_scheduled_hook( 'lha_scheduled_scan' );
        wp_clear_scheduled_hook( LHA_AI_Jobs::HOOK );

        // Stop any running scan by setting status to idle
        update_option( 'lha_scan_status', 'idle' );
    }
}
