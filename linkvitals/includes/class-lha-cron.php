<?php
/**
 * Cron class
 *
 * Manages WP-Cron scheduled events for:
 * - Processing the scan queue in batches (every 5 minutes)
 * - Running scheduled automatic scans (daily/weekly/monthly)
 * - Scheduling and unscheduling events based on settings
 *
 * @package LinkVitals
 * @requires PHP 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Cron {

    /**
     * Constructor.
     *
     * Registers the cron_schedules filter for custom intervals
     * and hooks the event handlers for queue processing and scheduled scans.
     */
    public function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
        add_action( 'lha_process_queue', array( $this, 'process_queue' ) );
        add_action( 'lha_scheduled_scan', array( $this, 'run_scheduled_scan' ) );
    }

    /**
     * Register custom cron intervals.
     *
     * Adds the lha_every_5_minutes interval (300 seconds) used for
     * queue batch processing.
     *
     * @param array $schedules Existing cron schedules.
     * @return array Modified schedules with LHA intervals added.
     */
    public function add_schedules( array $schedules ): array {
        $schedules['lha_every_5_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes (LHA)', 'linkvitals' ),
        );

        $schedules['lha_daily'] = array(
            'interval' => DAY_IN_SECONDS,
            'display'  => __( 'Daily (LHA)', 'linkvitals' ),
        );

        $schedules['lha_weekly'] = array(
            'interval' => WEEK_IN_SECONDS,
            'display'  => __( 'Weekly (LHA)', 'linkvitals' ),
        );

        $schedules['lha_monthly'] = array(
            'interval' => MONTH_IN_SECONDS,
            'display'  => __( 'Monthly (LHA)', 'linkvitals' ),
        );

        return $schedules;
    }

    /**
     * Process queue batch handler.
     *
     * Hooked to lha_process_queue (fires every 5 minutes).
     * Only processes when scan status is 'running'.
     * Resets stuck items, then delegates to Scanner::process_queue_batch().
     * Sends notification email if scan completes.
     */
    public function process_queue(): void {
        $status = get_option( 'lha_scan_status', 'idle' );

        if ( 'running' !== $status ) {
            return;
        }

        // Save pre-scan broken link count so notifications only fire for NEW broken links.
        if ( false === get_transient( 'lha_pre_scan_broken_count' ) ) {
            $stats = LHA_DB::get_stats();
            $pre_scan_count = LHA_DB::get_issue_total_from_stats( $stats );
            set_transient( 'lha_pre_scan_broken_count', $pre_scan_count, DAY_IN_SECONDS );
        }

        // Process batch via scanner (scanner handles reset_stuck internally).
        $scanner = new LHA_Scanner();
        $result  = $scanner->process_queue_batch();

        // If scan completed, send notification if configured.
        if ( isset( $result['status'] ) && 'completed' === $result['status'] ) {
            $this->maybe_send_notification();
        }
    }

    /**
     * Run scheduled automatic scan handler.
     *
     * Hooked to lha_scheduled_scan (fires at configured frequency).
     * Initiates an incremental scan of new/updated content (Req 10.2).
     * Only runs if auto_scan is enabled in settings.
     */
    public function run_scheduled_scan(): void {
        $settings = get_option( 'lha_settings', array() );

        if ( empty( $settings['auto_scan'] ) ) {
            return;
        }

        $scanner = new LHA_Scanner();
        $scanner->start_incremental_scan();

        LHA_Logger::log(
            'scheduled_scan',
            '',
            '',
            '',
            array(),
            __( 'Scheduled incremental scan started', 'linkvitals' )
        );
    }

    /**
     * Schedule or unschedule cron events based on current settings.
     *
     * Call this method when settings change (auto_scan toggle or scan_frequency).
     * It unschedules any existing lha_scheduled_scan event and, if auto_scan
     * is enabled, schedules a new event with the configured frequency.
     *
     * The lha_process_queue event is always kept running (scheduled on activation).
     *
     * @param array|null $settings Optional settings array. If null, loads from database.
     */
    public function schedule_events( ?array $settings = null ): void {
        if ( null === $settings ) {
            $settings = get_option( 'lha_settings', array() );
        }

        // Always unschedule the existing scheduled scan first (handles frequency changes).
        wp_clear_scheduled_hook( 'lha_scheduled_scan' );

        // Schedule new event only if auto_scan is enabled (Req 10.1, 10.3, 10.4).
        if ( ! empty( $settings['auto_scan'] ) ) {
            $frequency  = $settings['scan_frequency'] ?? 'weekly';
            $recurrence = 'lha_' . $frequency;

            // Schedule to first fire after one interval from now.
            wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, 'lha_scheduled_scan' );
        }
    }

    /**
     * Send email notification if new broken links were found.
     *
     * Called after a scan completes. Checks if email notifications are enabled
     * and if there are broken links to report.
     */
    private function maybe_send_notification(): void {
        $settings = get_option( 'lha_settings', array() );

        if ( empty( $settings['email_notifications'] ) ) {
            delete_transient( 'lha_pre_scan_broken_count' );
            return;
        }

        $email = ! empty( $settings['notification_email'] ) ? $settings['notification_email'] : get_option( 'admin_email' );
        if ( empty( $email ) || ! is_email( $email ) ) {
            delete_transient( 'lha_pre_scan_broken_count' );
            return;
        }

        $stats = LHA_DB::get_stats();

        $current_broken_count = LHA_DB::get_issue_total_from_stats( $stats );

        // Only send notification if there are NEW broken links compared to pre-scan count.
        $pre_scan_count = (int) get_transient( 'lha_pre_scan_broken_count' );
        delete_transient( 'lha_pre_scan_broken_count' );

        if ( $current_broken_count <= $pre_scan_count ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $subject   = sprintf(
            /* translators: %s: site name */
            __( '[%s] Link Health Audit Report', 'linkvitals' ),
            $site_name
        );

        $report_url = admin_url( 'tools.php?page=lha-dashboard&tab=report&link_status=issues' );

        $body = sprintf(
            /* translators: 1: site name, 2: scan time, 3: broken count, 4: 404 count, 5: 5xx count, 6: server error count, 7: timeout count, 8: SSL error count, 9: DNS error count, 10: forbidden count, 11: report URL */
            __( "Link Health Audit Report for %1\$s\n\nScan completed: %2\$s\n\nBroken Links: %3\$d\n404 Errors: %4\$d\n5xx Errors: %5\$d\nServer Errors: %6\$d\nTimeouts: %7\$d\nSSL Errors: %8\$d\nDNS Errors: %9\$d\nForbidden: %10\$d\n\nView full report: %11\$s", 'linkvitals' ),
            $site_name,
            current_time( 'mysql' ),
            $stats['broken'] ?? 0,
            $stats['code_404'] ?? 0,
            $stats['code_5xx'] ?? 0,
            $stats['server_error'] ?? 0,
            $stats['timeout'] ?? 0,
            $stats['ssl_error'] ?? 0,
            $stats['dns_error'] ?? 0,
            $stats['forbidden'] ?? 0,
            $report_url
        );

        wp_mail( $email, $subject, $body );

        LHA_Logger::log(
            'notification',
            '',
            '',
            '',
            array(),
            sprintf(
                /* translators: %s: email address */
                __( 'Email notification sent to %s', 'linkvitals' ),
                $email
            )
        );
    }
}
