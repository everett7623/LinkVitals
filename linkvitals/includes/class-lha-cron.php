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

    private const NOTIFICATION_BASELINE_TRANSIENT = 'lha_pre_scan_broken_count';
    private const NOTIFICATION_LOCK_OPTION = 'lha_notification_lock';

    /**
     * Constructor.
     *
     * Registers the cron_schedules filter for custom intervals
     * and hooks the event handlers for queue processing and scheduled scans.
     */
    public function __construct() {
        add_filter( 'cron_schedules', array( self::class, 'add_schedules' ) );
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
    public static function add_schedules( array $schedules ): array {
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

        self::begin_notification_tracking();

        // Process batch via scanner (scanner handles reset_stuck internally).
        $scanner = new LHA_Scanner();
        $result  = $scanner->process_queue_batch();

        // If scan completed, send notification if configured.
        if ( isset( $result['status'] ) && 'completed' === $result['status'] ) {
            self::complete_notification_tracking();
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

        self::begin_notification_tracking( true );
        $scanner = new LHA_Scanner();
        $result  = $scanner->start_incremental_scan();

        if ( 'started' !== ( $result['status'] ?? '' ) ) {
            self::clear_notification_tracking();
            return;
        }

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

    /** Capture the actionable issue total before a scan starts. */
    public static function begin_notification_tracking( bool $force = false ): void {
        $settings = get_option( 'lha_settings', array() );
        if ( empty( $settings['email_notifications'] ) ) {
            self::clear_notification_tracking();
            return;
        }

        if ( ! $force && false !== get_transient( self::NOTIFICATION_BASELINE_TRANSIENT ) ) {
            return;
        }

        $stats = LHA_DB::get_stats();
        set_transient(
            self::NOTIFICATION_BASELINE_TRANSIENT,
            LHA_DB::get_issue_total_from_stats( $stats ),
            7 * DAY_IN_SECONDS
        );
    }

    /** Remove an unused baseline when a scan did not start. */
    public static function clear_notification_tracking(): void {
        delete_transient( self::NOTIFICATION_BASELINE_TRANSIENT );
    }

    /** Clear notification state when scans are explicitly stopped or reset. */
    public static function reset_notification_tracking(): void {
        self::clear_notification_tracking();
        delete_option( self::NOTIFICATION_LOCK_OPTION );
    }

    /** Send one notification after a scan completes with newly found issues. */
    public static function complete_notification_tracking(): void {
        if ( ! self::acquire_notification_lock() ) {
            return;
        }

        try {
            $pre_scan_count = get_transient( self::NOTIFICATION_BASELINE_TRANSIENT );
            if ( false === $pre_scan_count ) {
                return;
            }
            self::clear_notification_tracking();

            $settings = get_option( 'lha_settings', array() );
            if ( empty( $settings['email_notifications'] ) ) {
                return;
            }

            $email = ! empty( $settings['notification_email'] ) ? $settings['notification_email'] : get_option( 'admin_email' );
            if ( empty( $email ) || ! is_email( $email ) ) {
                return;
            }

            $stats = LHA_DB::get_stats();
            $current_broken_count = LHA_DB::get_issue_total_from_stats( $stats );
            if ( $current_broken_count <= (int) $pre_scan_count ) {
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

            if ( wp_mail( $email, $subject, $body ) ) {
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
        } finally {
            delete_option( self::NOTIFICATION_LOCK_OPTION );
        }
    }

    /** Acquire a short-lived option lock so AJAX and WP-Cron cannot both email. */
    private static function acquire_notification_lock(): bool {
        $now = time();
        if ( add_option( self::NOTIFICATION_LOCK_OPTION, $now, '', false ) ) {
            return true;
        }

        $locked_at = (int) get_option( self::NOTIFICATION_LOCK_OPTION, 0 );
        if ( $locked_at <= 0 || $locked_at < $now - ( 5 * MINUTE_IN_SECONDS ) ) {
            delete_option( self::NOTIFICATION_LOCK_OPTION );
            return add_option( self::NOTIFICATION_LOCK_OPTION, $now, '', false );
        }

        return false;
    }
}
