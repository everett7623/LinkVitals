<?php
/**
 * Admin class
 *
 * Handles admin menus, pages, assets, and AJAX endpoints.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'admin_notices', array( $this, 'maybe_show_notice' ) );
        add_action( 'wp_ajax_lha_start_scan', array( $this, 'ajax_start_scan' ) );
        add_action( 'wp_ajax_lha_scan_progress', array( $this, 'ajax_scan_progress' ) );
        add_action( 'wp_ajax_lha_process_batch', array( $this, 'ajax_process_batch' ) );
        add_action( 'wp_ajax_lha_pause_scan', array( $this, 'ajax_pause_scan' ) );
        add_action( 'wp_ajax_lha_resume_scan', array( $this, 'ajax_resume_scan' ) );
        add_action( 'wp_ajax_lha_recheck_link', array( $this, 'ajax_recheck_link' ) );
        add_action( 'wp_ajax_lha_ignore_link', array( $this, 'ajax_ignore_link' ) );
        add_action( 'wp_ajax_lha_unignore_link', array( $this, 'ajax_unignore_link' ) );
        add_action( 'wp_ajax_lha_export_csv', array( $this, 'ajax_export_csv' ) );
        add_action( 'wp_ajax_lha_replace_url', array( $this, 'ajax_replace_url' ) );
        add_action( 'wp_ajax_lha_unlink', array( $this, 'ajax_unlink' ) );
        add_action( 'wp_ajax_lha_rollback_repair', array( $this, 'ajax_rollback_repair' ) );
        add_action( 'wp_ajax_lha_get_replace_preview', array( $this, 'ajax_get_replace_preview' ) );
        add_action( 'wp_ajax_lha_ai_analyze', array( $this, 'ajax_ai_analyze' ) );
        add_action( 'wp_ajax_lha_ai_test', array( $this, 'ajax_ai_test' ) );
        add_action( 'wp_ajax_lha_cleanup_orphans', array( $this, 'ajax_cleanup_orphans' ) );
        add_action( 'wp_ajax_lha_purge_logs', array( $this, 'ajax_purge_logs' ) );
        add_action( 'wp_ajax_lha_purge_repairs', array( $this, 'ajax_purge_repairs' ) );
        add_action( 'wp_ajax_lha_reset_data', array( $this, 'ajax_reset_data' ) );
    }

    /**
     * Show admin notice when link issues are found (only on non-plugin pages)
     */
    public function maybe_show_notice(): void {
        // Only show on non-plugin admin pages to avoid clutter
        $screen = get_current_screen();
        if ( ! $screen || str_contains( $screen->id, 'lha-dashboard' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Only check once per day using transient
        $notice_check = get_transient( 'lha_notice_check' );
        if ( $notice_check !== false ) {
            return;
        }

        $stats = LHA_DB::get_stats();
        $broken_total = LHA_DB::get_issue_total_from_stats( $stats );

        if ( $broken_total > 0 ) {
            $url = admin_url( 'tools.php?page=lha-dashboard&tab=report&link_status=issues' );
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                sprintf(
                    /* translators: %d: number of link issues */
                    esc_html__( 'LinkVitals: %d link issue(s) detected on your site.', 'linkvitals' ),
                    $broken_total
                ),
                esc_url( $url ),
                esc_html__( 'View Report', 'linkvitals' )
            );
        }

        // Cache for 24 hours
        set_transient( 'lha_notice_check', '1', DAY_IN_SECONDS );
    }

    /**
     * Register admin menu under Tools
     *
     * Creates one top-level entry "LinkVitals" under Tools,
     * with sub-pages rendered as tabs within the plugin interface.
     */
    public function add_menu(): void {
        add_management_page(
            __( 'LinkVitals', 'linkvitals' ),
            __( 'LinkVitals', 'linkvitals' ),
            'manage_options',
            'lha-dashboard',
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue admin CSS and JS on plugin pages only
     */
    public function enqueue_assets( string $hook ): void {
        // Only load on our page
        if ( $hook !== 'tools_page_lha-dashboard' ) {
            return;
        }

        wp_enqueue_style(
            'lha-admin-css',
            LHA_PLUGIN_URL . 'assets/css/admin.css',
            array( 'dashicons' ),
            LHA_VERSION
        );

        wp_enqueue_script(
            'lha-admin-js',
            LHA_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            LHA_VERSION,
            true
        );

        wp_localize_script( 'lha-admin-js', 'lhaAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'lha_ajax_nonce' ),
            'i18n'    => array(
                'scanning'    => __( 'Scanning...', 'linkvitals' ),
                'paused'      => __( 'Paused', 'linkvitals' ),
                'completed'   => __( 'Scan completed!', 'linkvitals' ),
                'error'       => __( 'An error occurred.', 'linkvitals' ),
                'confirm'     => __( 'Are you sure?', 'linkvitals' ),
                'request_failed' => __( 'Request failed', 'linkvitals' ),
                'original_url_missing' => __( 'Original URL missing', 'linkvitals' ),
                'enter_url' => __( 'Please enter a URL', 'linkvitals' ),
                'url_same' => __( 'URL is the same', 'linkvitals' ),
                'recheck_failed' => __( 'Recheck failed', 'linkvitals' ),
                'invalid_url' => __( 'Invalid URL', 'linkvitals' ),
                'saving' => __( 'Saving...', 'linkvitals' ),
                'save_failed' => __( 'Save failed', 'linkvitals' ),
                'fixed' => __( 'Fixed', 'linkvitals' ),
                'done_count' => __( 'Done (%d)', 'linkvitals' ),
                'replaced_count' => __( 'Replaced %d occurrence(s)', 'linkvitals' ),
                'unlink_done_count' => __( 'Unlinked (%d)', 'linkvitals' ),
                'unlink_failed' => __( 'Unlink failed', 'linkvitals' ),
                'rollback_confirm' => __( 'Are you sure you want to roll back this repair?', 'linkvitals' ),
                'rolling_back' => __( 'Rolling back...', 'linkvitals' ),
                'rollback_failed' => __( 'Rollback failed', 'linkvitals' ),
                'rolled_back' => __( 'Rolled back', 'linkvitals' ),
                'save' => __( 'Save', 'linkvitals' ),
                'maintenance_running' => __( 'Running...', 'linkvitals' ),
                'maintenance_failed' => __( 'Maintenance action failed', 'linkvitals' ),
                'purge_logs_confirm' => __( 'Purge logs older than 90 days?', 'linkvitals' ),
                'purge_repairs_confirm' => __( 'Purge old rolled-back repair history according to the retention setting?', 'linkvitals' ),
                'reset_data_prompt' => __( 'Type RESET to permanently delete all plugin scan data, logs, and repair history.', 'linkvitals' ),
                'reset_data_invalid' => __( 'Reset cancelled. You must type RESET exactly.', 'linkvitals' ),
            ),
        ) );
    }

    /**
     * Main page router - renders tab navigation and delegates to tab handler
     */
    public function render_page(): void {
        if ( ! LHA_Security::check_permission() ) {
            wp_die( esc_html__( 'Permission denied.', 'linkvitals' ) );
        }

        // Handle bulk action confirmation pages.
        if ( isset( $_GET['lha_bulk_action'] ) ) {
            $this->render_bulk_confirm();
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $tabs = array(
            'dashboard' => __( 'Dashboard', 'linkvitals' ),
            'report'    => __( 'Links Report', 'linkvitals' ),
            'internal'  => __( 'Internal Links', 'linkvitals' ),
            'seo'       => __( 'SEO Check', 'linkvitals' ),
            'settings'  => __( 'Settings', 'linkvitals' ),
            'logs'      => __( 'Logs', 'linkvitals' ),
        );

        $report_list_table = null;
        if ( 'report' === $tab ) {
            $report_list_table = new LHA_List_Table();
            $report_list_table->process_bulk_action();
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LinkVitals', 'linkvitals' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=' . $tab_key ) ); ?>"
                       class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $tab_label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="lha-tab-content">
                <?php
                switch ( $tab ) {
                    case 'report':
                        $this->render_report( $report_list_table );
                        break;
                    case 'internal':
                        $this->render_internal();
                        break;
                    case 'seo':
                        $this->render_seo();
                        break;
                    case 'settings':
                        $this->render_settings();
                        break;
                    case 'logs':
                        $this->render_logs();
                        break;
                    default:
                        $this->render_dashboard();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Dashboard page
     */
    public function render_dashboard(): void {
        $stats = LHA_DB::get_stats();
        $scanner = new LHA_Scanner();
        $progress = $scanner->get_progress();
        $last_scan = get_option( 'lha_last_scan_time', '' );
        $next_scan = wp_next_scheduled( 'lha_scheduled_scan' );

        ?>
        <div id="lha-dashboard">
                <!-- Stats Cards -->
                <div class="lha-stats-grid">
                    <div class="lha-stat-card">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Total Links', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['internal'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Internal', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['external'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'External', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-danger">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['broken'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Broken', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-danger">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['code_404'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( '404 Errors', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-danger">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['code_5xx'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( '5xx Errors', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-danger">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['server_error'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Server Errors', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-warning">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['redirect'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Redirects', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-warning">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['timeout'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Timeouts', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-warning">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['ssl_error'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'SSL Errors', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-danger">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['dns_error'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'DNS Errors', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card lha-stat-warning">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['forbidden'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Forbidden', 'linkvitals' ); ?></span>
                    </div>
                    <div class="lha-stat-card">
                        <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $stats['ignored'] ) ); ?></span>
                        <span class="lha-stat-label"><?php esc_html_e( 'Ignored', 'linkvitals' ); ?></span>
                    </div>
                </div>

                <!-- Scan Info -->
                <div class="lha-scan-info">
                    <p>
                        <strong><?php esc_html_e( 'Last Scan:', 'linkvitals' ); ?></strong>
                        <?php echo $last_scan ? esc_html( $last_scan ) : esc_html__( 'Never', 'linkvitals' ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Next Scheduled Scan:', 'linkvitals' ); ?></strong>
                        <?php echo $next_scan ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scan ) ) ) : esc_html__( 'Not scheduled', 'linkvitals' ); ?>
                    </p>
                </div>

                <!-- Scan Progress -->
                <div id="lha-scan-progress" class="lha-progress-wrap" style="<?php echo $progress['status'] === 'running' ? '' : 'display:none;'; ?>">
                    <div class="lha-progress-bar">
                        <div class="lha-progress-fill" style="width: <?php echo esc_attr( $progress['percentage'] ); ?>%"></div>
                    </div>
                    <p class="lha-progress-text">
                        <span id="lha-progress-percentage"><?php echo esc_html( $progress['percentage'] ); ?></span>% -
                        <span id="lha-progress-done"><?php echo esc_html( $progress['done'] ); ?></span> / <span id="lha-progress-total"><?php echo esc_html( $progress['total'] ); ?></span>
                    </p>
                </div>

                <!-- Action Buttons -->
                <div class="lha-actions">
                    <button type="button" class="button button-primary" id="lha-btn-full-scan">
                        <?php esc_html_e( 'Start Full Scan', 'linkvitals' ); ?>
                    </button>
                    <button type="button" class="button" id="lha-btn-incremental-scan">
                        <?php esc_html_e( 'Scan New / Updated Content', 'linkvitals' ); ?>
                    </button>
                    <button type="button" class="button" id="lha-btn-recheck-broken">
                        <?php esc_html_e( 'Recheck Link Issues', 'linkvitals' ); ?>
                    </button>
                    <button type="button" class="button" id="lha-btn-pause" style="<?php echo $progress['status'] === 'running' ? '' : 'display:none;'; ?>">
                        <?php esc_html_e( 'Pause Scan', 'linkvitals' ); ?>
                    </button>
                    <button type="button" class="button" id="lha-btn-resume" style="<?php echo $progress['status'] === 'paused' ? '' : 'display:none;'; ?>">
                        <?php esc_html_e( 'Resume Scan', 'linkvitals' ); ?>
                    </button>
                    <a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=lha_export_csv&nonce=' . wp_create_nonce( 'lha_ajax_nonce' ) ) ); ?>" class="button">
                        <?php esc_html_e( 'Export CSV', 'linkvitals' ); ?>
                    </a>
                </div>

                <!-- Maintenance Tools -->
                <div class="lha-maintenance" style="margin-top:30px;padding-top:20px;border-top:1px solid #ccd0d4;">
                    <h3><?php esc_html_e( 'Maintenance', 'linkvitals' ); ?></h3>
                    <p class="description"><?php esc_html_e( 'Database maintenance tools for the plugin data.', 'linkvitals' ); ?></p>
                    <div style="margin-top:10px;">
                        <button type="button" class="button" id="lha-btn-cleanup-orphans">
                            <?php esc_html_e( 'Clean Orphaned Links', 'linkvitals' ); ?>
                        </button>
                        <button type="button" class="button" id="lha-btn-purge-logs">
                            <?php esc_html_e( 'Purge Old Logs', 'linkvitals' ); ?>
                        </button>
                        <button type="button" class="button" id="lha-btn-purge-repairs">
                            <?php esc_html_e( 'Purge Old Repair History', 'linkvitals' ); ?>
                        </button>
                        <button type="button" class="button button-link-delete" id="lha-btn-reset-data">
                            <?php esc_html_e( 'Reset All Data', 'linkvitals' ); ?>
                        </button>
                    </div>
                    <p id="lha-maintenance-result" style="margin-top:10px;"></p>
                </div>
            </div>
        <?php
    }

    /**
     * Render Report page (Links list table)
     */
    public function render_report( ?LHA_List_Table $list_table = null ): void {
        $list_table = $list_table ?: new LHA_List_Table();
        $list_table->prepare_items();

        ?>
        <div id="lha-report">

            <!-- Filter links -->
            <ul class="subsubsub">
                <?php
                $filters = array(
                    ''         => __( 'All', 'linkvitals' ),
                    'issues'   => __( 'Issues', 'linkvitals' ),
                    'broken'   => __( 'Broken', 'linkvitals' ),
                    '404'      => __( '404', 'linkvitals' ),
                    '5xx'      => __( '5xx', 'linkvitals' ),
                    'server_error' => __( 'Server Errors', 'linkvitals' ),
                    'redirect' => __( 'Redirects', 'linkvitals' ),
                    'timeout'  => __( 'Timeout', 'linkvitals' ),
                    'ssl_error' => __( 'SSL Error', 'linkvitals' ),
                    'dns_error' => __( 'DNS Error', 'linkvitals' ),
                    'forbidden' => __( 'Forbidden', 'linkvitals' ),
                    'internal' => __( 'Internal', 'linkvitals' ),
                    'external' => __( 'External', 'linkvitals' ),
                    'image'    => __( 'Images', 'linkvitals' ),
                    'anchor'   => __( 'Anchors', 'linkvitals' ),
                    'ignored'  => __( 'Ignored', 'linkvitals' ),
                );

                $filters = array_intersect_key( $filters, array_flip( LHA_DB::get_report_filter_keys() ) );

                $current_filter = isset( $_GET['link_status'] ) ? LHA_DB::sanitize_report_filter_key( wp_unslash( $_GET['link_status'] ) ) : '';
                $total_filters = count( $filters );
                $i = 0;

                foreach ( $filters as $key => $label ) {
                    $i++;
                    $class = ( $key === $current_filter ) ? 'current' : '';
                    $url = add_query_arg( array( 'page' => 'lha-dashboard', 'tab' => 'report', 'link_status' => $key ), admin_url( 'tools.php' ) );
                    printf(
                        '<li><a href="%s" class="%s">%s</a>%s</li>',
                        esc_url( $url ),
                        esc_attr( $class ),
                        esc_html( $label ),
                        $i < $total_filters ? ' | ' : ''
                    );
                }
                ?>
            </ul>

            <form method="get">
                <input type="hidden" name="page" value="lha-dashboard" />
                <input type="hidden" name="tab" value="report" />
                <?php
                $list_table->search_box( __( 'Search URL', 'linkvitals' ), 'lha-search' );
                $list_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render bulk action confirmation page (Replace URL / Unlink).
     */
    public function render_bulk_confirm(): void {
        $bulk_action = sanitize_key( $_GET['lha_bulk_action'] );
        $raw_link_ids = isset( $_GET['link_ids'] ) ? (array) wp_unslash( $_GET['link_ids'] ) : array();
        $link_ids     = array_values( array_filter( array_map( 'absint', $raw_link_ids ) ) );

        if ( ! in_array( $bulk_action, array( 'replace_url', 'unlink' ), true ) ) {
            wp_die( esc_html__( 'Invalid bulk action.', 'linkvitals' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'lha_bulk_confirm_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'linkvitals' ) );
        }

        if ( empty( $link_ids ) ) {
            wp_die( esc_html__( 'No links selected.', 'linkvitals' ) );
        }

        // Process confirmed submission.
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lha_bulk_confirm_submit'] ) ) {
            if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'lha_bulk_confirm_nonce' ) ) {
                wp_die( esc_html__( 'Security check failed.', 'linkvitals' ) );
            }

            $posted_ids = isset( $_POST['link_ids'] ) ? array_map( 'absint', (array) $_POST['link_ids'] ) : array();
            $repair     = new LHA_Repair();
            $total_done = 0;

            if ( 'replace_url' === $bulk_action ) {
                $new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';
                if ( empty( $new_url ) ) {
                    echo '<div class="notice notice-error"><p>' . esc_html__( 'Please enter a valid replacement URL.', 'linkvitals' ) . '</p></div>';
                } else {
                    foreach ( $posted_ids as $lid ) {
                        $link = LHA_DB::get_link( $lid );
                        if ( $link ) {
                            $result = $repair->replace_url( $link['url'], $new_url );
                            if ( $result['success'] ) {
                                $total_done += $result['replaced'];
                            }
                        }
                    }
                    printf(
                        '<div class="notice notice-success"><p>%s</p></div>',
                        esc_html( sprintf(
                            /* translators: %d: number of replacements */
                            __( 'Bulk replace completed. %d occurrence(s) updated.', 'linkvitals' ),
                            $total_done
                        ) )
                    );
                    printf(
                        '<p><a href="%s" class="button">%s</a></p>',
                        esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=report' ) ),
                        esc_html__( 'Back to Report', 'linkvitals' )
                    );
                    return;
                }
            } elseif ( 'unlink' === $bulk_action ) {
                foreach ( $posted_ids as $lid ) {
                    $result = $repair->unlink( $lid );
                    if ( $result['success'] ) {
                        $total_done += $result['unlinked'];
                    }
                }
                printf(
                    '<div class="notice notice-success"><p>%s</p></div>',
                    esc_html( sprintf(
                        /* translators: %d: number of unlinks */
                        __( 'Bulk unlink completed. %d occurrence(s) unlinked.', 'linkvitals' ),
                        $total_done
                    ) )
                );
                printf(
                    '<p><a href="%s" class="button">%s</a></p>',
                    esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=report' ) ),
                        esc_html__( 'Back to Report', 'linkvitals' )
                );
                return;
            }
        }

        // Gather link data for display.
        $links_data = array();
        foreach ( $link_ids as $lid ) {
            $link = LHA_DB::get_link( $lid );
            if ( $link ) {
                $occurrences = LHA_DB::get_occurrences( $lid );
                $links_data[] = array(
                    'id'          => $lid,
                    'url'         => $link['url'],
                    'status'      => $link['status'],
                    'occurrences' => $occurrences,
                );
            }
        }

        $total_occurrences = 0;
        foreach ( $links_data as $ld ) {
            $total_occurrences += count( $ld['occurrences'] );
        }

        ?>
        <div class="wrap">
            <h1><?php echo 'replace_url' === $bulk_action ? esc_html__( 'Bulk Replace URL', 'linkvitals' ) : esc_html__( 'Bulk Unlink', 'linkvitals' ); ?></h1>

            <p>
                <?php
                printf(
                    /* translators: 1: link count, 2: occurrence count */
                    esc_html__( 'You have selected %1$d link(s) affecting %2$d content object(s).', 'linkvitals' ),
                    count( $links_data ),
                    $total_occurrences
                );
                ?>
            </p>

            <table class="wp-list-table widefat fixed striped" style="margin-bottom:20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'URL', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Sources', 'linkvitals' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $links_data as $ld ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( mb_substr( $ld['url'], 0, 80 ) ); ?></code></td>
                        <td><?php echo esc_html( $ld['status'] ); ?></td>
                        <td>
                            <?php
                            if ( ! empty( $ld['occurrences'] ) ) {
                                $source_titles = array();
                                foreach ( $ld['occurrences'] as $occ ) {
                                    $source_titles[] = $occ['source_title'];
                                }
                                echo esc_html( implode( ', ', array_slice( $source_titles, 0, 3 ) ) );
                                if ( count( $source_titles ) > 3 ) {
                                    printf( ' <small>(+%d)</small>', count( $source_titles ) - 3 );
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <form method="post">
                <?php wp_nonce_field( 'lha_bulk_confirm_nonce' ); ?>
                <?php foreach ( $link_ids as $lid ) : ?>
                    <input type="hidden" name="link_ids[]" value="<?php echo absint( $lid ); ?>" />
                <?php endforeach; ?>

                <?php if ( 'replace_url' === $bulk_action ) : ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="lha-new-url"><?php esc_html_e( 'New URL', 'linkvitals' ); ?></label></th>
                            <td>
                                <input type="url" id="lha-new-url" name="new_url" class="regular-text" placeholder="https://" required />
                                <p class="description"><?php esc_html_e( 'All selected links will be replaced with this URL.', 'linkvitals' ); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="lha_bulk_confirm_submit" class="button button-primary" value="<?php esc_attr_e( 'Confirm Replace', 'linkvitals' ); ?>" />
                        <a href="<?php echo esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=report' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'linkvitals' ); ?></a>
                    </p>
                <?php else : ?>
                    <p class="description"><?php esc_html_e( 'This will remove the link tags from content but keep the anchor text. This action cannot be undone.', 'linkvitals' ); ?></p>
                    <p class="submit">
                        <input type="submit" name="lha_bulk_confirm_submit" class="button button-primary" value="<?php esc_attr_e( 'Confirm Unlink', 'linkvitals' ); ?>" />
                        <a href="<?php echo esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=report' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'linkvitals' ); ?></a>
                    </p>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render Internal Link Analysis page
     */
    public function render_internal(): void {
        $analyzer = new LHA_Internal_Analyzer();
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : '';

        $results = $analyzer->get_analysis( array(
            'per_page' => $per_page,
            'offset'   => ( $page - 1 ) * $per_page,
            'filter'   => $filter,
        ) );

        $base_url = admin_url( 'tools.php?page=lha-dashboard&tab=internal' );

        ?>
        <div id="lha-internal">
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo empty( $filter ) ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'orphaned', $base_url ) ); ?>" class="<?php echo $filter === 'orphaned' ? 'current' : ''; ?>"><?php esc_html_e( 'Orphaned Pages', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'low_outbound', $base_url ) ); ?>" class="<?php echo $filter === 'low_outbound' ? 'current' : ''; ?>"><?php esc_html_e( 'Low Outbound', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'filter', 'http_links', $base_url ) ); ?>" class="<?php echo $filter === 'http_links' ? 'current' : ''; ?>"><?php esc_html_e( 'HTTP Links', 'linkvitals' ); ?></a></li>
            </ul>

            <?php if ( 'http_links' === $filter ) : ?>
                <?php
                $http_links = $analyzer->find_http_links();
                ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'URL', 'linkvitals' ); ?></th>
                            <th><?php esc_html_e( 'Protocol Issue', 'linkvitals' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $http_links ) ) : ?>
                            <tr><td colspan="2"><?php esc_html_e( 'No HTTP links found. Your internal links are all using HTTPS.', 'linkvitals' ); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ( $http_links as $http_link ) : ?>
                            <tr>
                                <td><a href="<?php echo esc_url( $http_link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( mb_substr( $http_link['url'], 0, 80 ) ); ?></a></td>
                                <td><span class="lha-badge lha-badge-warning"><?php esc_html_e( 'HTTP on HTTPS site', 'linkvitals' ); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Title', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Inbound Links', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Outbound Links', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'linkvitals' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $results['items'] ) ) : ?>
                        <tr><td colspan="6"><?php esc_html_e( 'No data available. Run a scan first.', 'linkvitals' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $results['items'] as $item ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $item['permalink'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a></td>
                            <td><?php echo esc_html( $item['post_type'] ); ?></td>
                            <td><?php echo esc_html( $item['inbound'] ); ?></td>
                            <td><?php echo esc_html( $item['outbound'] ); ?></td>
                            <td><?php echo $item['is_orphaned'] ? '<span class="lha-badge lha-badge-danger">' . esc_html__( 'Orphaned', 'linkvitals' ) . '</span>' : '<span class="lha-badge lha-badge-ok">' . esc_html__( 'OK', 'linkvitals' ) . '</span>'; ?></td>
                            <td><a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php esc_html_e( 'Edit', 'linkvitals' ); ?></a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render Settings page (delegates to LHA_Settings)
     */
    public function render_settings(): void {
        $settings = new LHA_Settings();
        $settings->render_page();
    }

    /**
     * Render Logs page
     */
    public function render_logs(): void {
        $repair_page     = isset( $_GET['repair_paged'] ) ? max( 1, absint( $_GET['repair_paged'] ) ) : 1;
        $repair_per_page = 20;
        $repair_data     = LHA_DB::get_repairs( $repair_page, $repair_per_page );
        $repairs         = $repair_data['items'];
        $repair_total    = $repair_data['total'];

        $log_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 50;

        $data  = LHA_Logger::get_logs( $log_page, $per_page );
        $logs  = $data['items'];
        $total = $data['total'];

        ?>
        <div id="lha-logs">
            <h2><?php esc_html_e( 'Repair History', 'linkvitals' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'URL Change', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'User', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Rollback', 'linkvitals' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $repairs ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No repair records yet.', 'linkvitals' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $repairs as $repair ) : ?>
                            <?php
                            $action_label = 'link_unlinked' === $repair['action_type']
                                ? __( 'Link unlinked', 'linkvitals' )
                                : __( 'URL replaced', 'linkvitals' );
                            $source_title = $repair['source_title'] ?: sprintf( __( 'Post #%d', 'linkvitals' ), (int) $repair['object_id'] );
                            $user         = get_user_by( 'id', (int) $repair['user_id'] );
                            ?>
                            <tr>
                                <td><?php echo esc_html( $repair['created_at'] ); ?></td>
                                <td><?php echo esc_html( $action_label ); ?></td>
                                <td>
                                    <?php if ( ! empty( $repair['edit_url'] ) ) : ?>
                                        <a href="<?php echo esc_url( $repair['edit_url'] ); ?>"><?php echo esc_html( $source_title ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $source_title ); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code><?php echo esc_html( mb_substr( $repair['old_url'], 0, 70 ) ); ?></code>
                                    <?php if ( ! empty( $repair['new_url'] ) ) : ?>
                                        <br><span aria-hidden="true">&rarr;</span>
                                        <code><?php echo esc_html( mb_substr( $repair['new_url'], 0, 70 ) ); ?></code>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ( 'rolled_back' === $repair['status'] ) : ?>
                                        <span class="lha-badge lha-badge-ok"><?php esc_html_e( 'Rolled back', 'linkvitals' ); ?></span>
                                    <?php else : ?>
                                        <span class="lha-badge lha-badge-warning"><?php esc_html_e( 'Active', 'linkvitals' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $user ? esc_html( $user->display_name ) : '-'; ?></td>
                                <td>
                                    <?php if ( 'active' === $repair['status'] ) : ?>
                                        <button type="button" class="button button-small lha-action-rollback-repair" data-repair-id="<?php echo esc_attr( (int) $repair['id'] ); ?>">
                                            <?php esc_html_e( 'Rollback', 'linkvitals' ); ?>
                                        </button>
                                    <?php else : ?>
                                        <?php esc_html_e( 'Rolled back', 'linkvitals' ); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            $repair_total_pages = ceil( $repair_total / $repair_per_page );
            if ( $repair_total_pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'repair_paged', '%#%' ),
                    'format'  => '',
                    'current' => $repair_page,
                    'total'   => $repair_total_pages,
                ) ) );
                echo '</div></div>';
            }
            ?>

            <h2><?php esc_html_e( 'Audit Logs', 'linkvitals' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Action', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'URL', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'User', 'linkvitals' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No logs yet.', 'linkvitals' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                        <tr>
                            <td><?php echo esc_html( $log['created_at'] ); ?></td>
                            <td><?php echo esc_html( $log['action_type'] ); ?></td>
                            <td><code><?php echo esc_html( mb_substr( $log['url'], 0, 60 ) ); ?></code></td>
                            <td><?php echo esc_html( $log['message'] ); ?></td>
                            <td><?php $user = get_user_by( 'id', $log['user_id'] ); echo $user ? esc_html( $user->display_name ) : '-'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            // Simple pagination
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo wp_kses_post( paginate_links( array(
                    'base'    => add_query_arg( 'paged', '%#%' ),
                    'format'  => '',
                    'current' => $log_page,
                    'total'   => $total_pages,
                ) ) );
                echo '</div></div>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render SEO Check page
     */
    public function render_seo(): void {
        $seo_checker = new LHA_SEO_Checker();
        $page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $per_page = 20;
        $issue_filter = isset( $_GET['issue'] ) ? sanitize_key( $_GET['issue'] ) : '';

        $counts = $seo_checker->get_issue_counts();
        $report = $seo_checker->get_report( $per_page, ( $page - 1 ) * $per_page, $issue_filter );

        $base_url = admin_url( 'tools.php?page=lha-dashboard&tab=seo' );
        ?>
        <div id="lha-seo">
            <!-- SEO Issue Summary -->
            <div class="lha-stats-grid" style="margin-bottom: 20px;">
                <div class="lha-stat-card">
                    <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $counts['total_external'] ) ); ?></span>
                    <span class="lha-stat-label"><?php esc_html_e( 'External Links', 'linkvitals' ); ?></span>
                </div>
                <div class="lha-stat-card lha-stat-warning">
                    <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $counts['missing_nofollow'] ) ); ?></span>
                    <span class="lha-stat-label"><?php esc_html_e( 'Missing nofollow', 'linkvitals' ); ?></span>
                </div>
                <div class="lha-stat-card lha-stat-danger">
                    <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $counts['missing_noopener_noreferrer'] ) ); ?></span>
                    <span class="lha-stat-label"><?php esc_html_e( 'Missing noopener', 'linkvitals' ); ?></span>
                </div>
                <div class="lha-stat-card lha-stat-warning">
                    <span class="lha-stat-number"><?php echo esc_html( number_format_i18n( $counts['http_not_https'] ) ); ?></span>
                    <span class="lha-stat-label"><?php esc_html_e( 'HTTP (not HTTPS)', 'linkvitals' ); ?></span>
                </div>
            </div>

            <p class="description">
                <?php esc_html_e( 'These are suggestions for SEO best practices. Not all external links need nofollow - use your judgment.', 'linkvitals' ); ?>
            </p>

            <!-- Filters -->
            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( $base_url ); ?>" class="<?php echo empty( $issue_filter ) ? 'current' : ''; ?>"><?php esc_html_e( 'All', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'issue', 'missing_nofollow', $base_url ) ); ?>" class="<?php echo $issue_filter === 'missing_nofollow' ? 'current' : ''; ?>"><?php esc_html_e( 'Missing nofollow', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'issue', 'missing_noopener_noreferrer', $base_url ) ); ?>" class="<?php echo $issue_filter === 'missing_noopener_noreferrer' ? 'current' : ''; ?>"><?php esc_html_e( 'Missing noopener/noreferrer', 'linkvitals' ); ?></a> | </li>
                <li><a href="<?php echo esc_url( add_query_arg( 'issue', 'http_not_https', $base_url ) ); ?>" class="<?php echo $issue_filter === 'http_not_https' ? 'current' : ''; ?>"><?php esc_html_e( 'HTTP links', 'linkvitals' ); ?></a></li>
            </ul>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'URL', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Source', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Anchor Text', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'nofollow', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'noopener', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'HTTPS', 'linkvitals' ); ?></th>
                        <th><?php esc_html_e( 'Issues', 'linkvitals' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $report['items'] ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No external links found. Run a scan first.', 'linkvitals' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $report['items'] as $item ) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $item['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( mb_substr( $item['url'], 0, 50 ) ); ?></a></td>
                            <td>
                                <?php if ( ! empty( $item['edit_url'] ) ) : ?>
                                    <a href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( mb_substr( $item['source_title'], 0, 30 ) ); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html( mb_substr( $item['source_title'], 0, 30 ) ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( mb_substr( $item['anchor_text'], 0, 30 ) ); ?></td>
                            <td><?php echo $item['has_nofollow'] ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>' : '<span class="dashicons dashicons-dismiss" style="color:#d63638;"></span>'; ?></td>
                            <td><?php echo $item['has_noopener'] ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>' : '<span class="dashicons dashicons-dismiss" style="color:#d63638;"></span>'; ?></td>
                            <td><?php echo ! $item['is_http'] ? '<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>' : '<span class="dashicons dashicons-dismiss" style="color:#d63638;"></span>'; ?></td>
                            <td>
                                <?php foreach ( $item['issues'] as $issue ) : ?>
                                    <span class="lha-badge lha-badge-warning"><?php echo esc_html( str_replace( '_', ' ', $issue ) ); ?></span>
                                <?php endforeach; ?>
                                <?php if ( empty( $item['issues'] ) ) : ?>
                                    <span class="lha-badge lha-badge-ok"><?php esc_html_e( 'OK', 'linkvitals' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // =====================
    // AJAX Handlers
    // =====================

    /**
     * AJAX: Start scan
     */
    public function ajax_start_scan(): void {
        LHA_Security::ajax_check();

        $type = isset( $_POST['scan_type'] ) ? sanitize_key( $_POST['scan_type'] ) : 'full';
        $scanner = new LHA_Scanner();

        switch ( $type ) {
            case 'incremental':
                $result = $scanner->start_incremental_scan();
                break;
            case 'recheck_broken':
                $result = $scanner->recheck_broken();
                break;
            default:
                $result = $scanner->start_full_scan();
                break;
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Get scan progress
     */
    public function ajax_scan_progress(): void {
        LHA_Security::ajax_check();

        $scanner = new LHA_Scanner();
        $progress = $scanner->get_progress();

        wp_send_json_success( $progress );
    }

    /**
     * AJAX: Process next batch
     */
    public function ajax_process_batch(): void {
        LHA_Security::ajax_check();

        $scanner = new LHA_Scanner();
        $result = $scanner->process_queue_batch();

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Pause scan
     */
    public function ajax_pause_scan(): void {
        LHA_Security::ajax_check();

        $scanner = new LHA_Scanner();
        $scanner->pause();

        wp_send_json_success( array( 'status' => 'paused' ) );
    }

    /**
     * AJAX: Resume scan
     */
    public function ajax_resume_scan(): void {
        LHA_Security::ajax_check();

        $scanner = new LHA_Scanner();
        $scanner->resume();

        wp_send_json_success( array( 'status' => 'running' ) );
    }

    /**
     * AJAX: Recheck a single link
     */
    public function ajax_recheck_link(): void {
        LHA_Security::ajax_check();

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        if ( ! $link_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'linkvitals' ) ) );
            return;
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            wp_send_json_error( array( 'message' => __( 'Link not found.', 'linkvitals' ) ) );
            return;
        }

        $settings = get_option( 'lha_settings', array() );
        $checker = new LHA_Link_Checker();
        $result = $checker->check( $link['url'], $settings );
        LHA_DB::update_link_result( $link_id, $result );

        wp_send_json_success( array(
            'link_id' => $link_id,
            'status'  => $result['status'],
            'http_code' => $result['http_code'],
        ) );
    }

    /**
     * AJAX: Ignore a link
     */
    public function ajax_ignore_link(): void {
        LHA_Security::ajax_check();

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        $reason = 'user';

        if ( ! $link_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'linkvitals' ) ) );
            return;
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            wp_send_json_error( array( 'message' => __( 'Link not found.', 'linkvitals' ) ) );
            return;
        }

        LHA_DB::ignore_link( $link_id, $reason );
        LHA_Logger::log( 'link_ignored', $link['url'], '', '', array( $link_id ), __( 'Link ignored by user', 'linkvitals' ) );

        wp_send_json_success( array( 'link_id' => $link_id ) );
    }

    /**
     * AJAX: Unignore a link
     */
    public function ajax_unignore_link(): void {
        LHA_Security::ajax_check();

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;

        if ( ! $link_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'linkvitals' ) ) );
            return;
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            wp_send_json_error( array( 'message' => __( 'Link not found.', 'linkvitals' ) ) );
            return;
        }

        LHA_DB::unignore_link( $link_id );
        LHA_Logger::log( 'link_unignored', $link['url'], '', '', array( $link_id ), __( 'Link unignored by user', 'linkvitals' ) );

        wp_send_json_success( array( 'link_id' => $link_id ) );
    }

    /**
     * AJAX: Export CSV
     */
    public function ajax_export_csv(): void {
        LHA_Security::ajax_check();

        $filters = array(
            'status'  => isset( $_GET['link_status'] ) ? LHA_DB::sanitize_report_filter_key( wp_unslash( $_GET['link_status'] ) ) : '',
            'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'last_checked',
            'order'   => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC',
        );

        LHA_Exporter::export( $filters );
    }

    /**
     * AJAX: Get preview of URL replacement (shows affected posts before confirming)
     */
    public function ajax_get_replace_preview(): void {
        LHA_Security::ajax_check();

        $old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
        $object_id = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : null;

        if ( empty( $old_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid URL.', 'linkvitals' ) ) );
            return;
        }

        $repair = new LHA_Repair();
        $preview = $repair->get_replace_preview( $old_url, $object_id ?: null );

        wp_send_json_success( $preview );
    }

    /**
     * AJAX: Replace URL (with confirmation - preview must be called first)
     */
    public function ajax_replace_url(): void {
        LHA_Security::ajax_check();

        $old_url = isset( $_POST['old_url'] ) ? esc_url_raw( wp_unslash( $_POST['old_url'] ) ) : '';
        $new_url = isset( $_POST['new_url'] ) ? esc_url_raw( wp_unslash( $_POST['new_url'] ) ) : '';
        $object_id = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : null;

        if ( empty( $old_url ) || empty( $new_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Both old URL and new URL are required.', 'linkvitals' ) ) );
            return;
        }

        if ( $old_url === $new_url ) {
            wp_send_json_error( array( 'message' => __( 'New URL must be different from old URL.', 'linkvitals' ) ) );
            return;
        }

        $repair = new LHA_Repair();
        $result = $repair->replace_url( $old_url, $new_url, $object_id ?: null );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Unlink a URL (remove anchor tag, keep text)
     */
    public function ajax_unlink(): void {
        LHA_Security::ajax_check();

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        $object_id = isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : null;

        if ( ! $link_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'linkvitals' ) ) );
            return;
        }

        $repair = new LHA_Repair();
        $result = $repair->unlink( $link_id, $object_id ?: null );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Roll back a recorded repair.
     */
    public function ajax_rollback_repair(): void {
        LHA_Security::ajax_check();

        $repair_id = isset( $_POST['repair_id'] ) ? absint( $_POST['repair_id'] ) : 0;
        if ( ! $repair_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid repair ID.', 'linkvitals' ) ) );
            return;
        }

        $repair = new LHA_Repair();
        $result = $repair->rollback( $repair_id );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Ask AI to analyze a broken link and suggest a fix
     */
    public function ajax_ai_analyze(): void {
        LHA_Security::ajax_check();

        $link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;
        if ( ! $link_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid link ID.', 'linkvitals' ) ) );
            return;
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            wp_send_json_error( array( 'message' => __( 'Link not found.', 'linkvitals' ) ) );
            return;
        }

        $occurrences = LHA_DB::get_occurrences( $link_id );

        $ai     = new LHA_AI();
        $result = $ai->analyze_link( $link, $occurrences );

        if ( $result['success'] ) {
            wp_send_json_success( array(
                'suggestion' => $result['suggestion'],
                'model'      => $result['model'] ?? '',
                'tokens'     => $result['tokens'] ?? 0,
            ) );
        } else {
            wp_send_json_error( array( 'message' => $result['error'] ?? __( 'AI request failed.', 'linkvitals' ) ) );
        }
    }

    /**
     * AJAX: Test AI API connection
     */
    public function ajax_ai_test(): void {
        LHA_Security::ajax_check();

        $provider = isset( $_POST['provider'] ) ? sanitize_key( $_POST['provider'] ) : '';
        if ( ! in_array( $provider, array( 'openai', 'claude' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid provider.', 'linkvitals' ) ) );
            return;
        }

        // Temporarily use the key sent from the form (not yet saved)
        $raw_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
        if ( ! empty( $raw_key ) ) {
            // Temporarily override the stored key for this test
            add_filter( 'lha_api_key_override', function() use ( $raw_key ) {
                return $raw_key;
            } );
        }

        $ai     = new LHA_AI();
        $result = $ai->test_connection( $provider );

        wp_send_json( array(
            'success' => $result['success'],
            'data'    => array( 'message' => $result['message'] ),
        ) );
    }

    /**
     * AJAX: Clean orphaned link records (links with no occurrences)
     */
    public function ajax_cleanup_orphans(): void {
        LHA_Security::ajax_check();

        $deleted = LHA_DB::cleanup_orphaned_links();

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted records */
                __( 'Cleaned %d orphaned link record(s).', 'linkvitals' ),
                $deleted
            ),
        ) );
    }

    /**
     * AJAX: Purge logs older than 90 days
     */
    public function ajax_purge_logs(): void {
        LHA_Security::ajax_check();

        $deleted = LHA_Logger::cleanup( 90 );

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: %d: number of deleted log entries */
                __( 'Purged %d old log entry(ies).', 'linkvitals' ),
                $deleted
            ),
        ) );
    }

    /**
     * AJAX: Purge old rolled-back repair history according to settings.
     */
    public function ajax_purge_repairs(): void {
        LHA_Security::ajax_check();

        $settings       = get_option( 'lha_settings', array() );
        $retention_days = isset( $settings['repair_history_retention_days'] )
            ? absint( $settings['repair_history_retention_days'] )
            : 180;

        if ( $retention_days < 1 ) {
            wp_send_json_success( array(
                'deleted' => 0,
                'message' => __( 'Repair history retention is set to keep records forever.', 'linkvitals' ),
            ) );
        }

        $deleted = LHA_DB::cleanup_repair_history( $retention_days );

        LHA_Logger::log(
            'repair_history_purged',
            '',
            '',
            '',
            array(),
            sprintf(
                /* translators: 1: number of deleted records, 2: retention days */
                __( 'Purged %1$d old repair history record(s) older than %2$d days.', 'linkvitals' ),
                $deleted,
                $retention_days
            )
        );

        wp_send_json_success( array(
            'deleted' => $deleted,
            'message' => sprintf(
                /* translators: 1: number of deleted records, 2: retention days */
                __( 'Purged %1$d old repair history record(s) older than %2$d days.', 'linkvitals' ),
                $deleted,
                $retention_days
            ),
        ) );
    }

    /**
     * AJAX: Reset all plugin data (truncate all tables)
     */
    public function ajax_reset_data(): void {
        LHA_Security::ajax_check();

        $confirmation = isset( $_POST['confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) : '';
        if ( 'RESET' !== $confirmation ) {
            wp_send_json_error( array( 'message' => __( 'Reset confirmation failed.', 'linkvitals' ) ) );
            return;
        }

        global $wpdb;

        $tables = array(
            LHA_DB::table( 'links' ),
            LHA_DB::table( 'occurrences' ),
            LHA_DB::table( 'queue' ),
            LHA_DB::table( 'logs' ),
            LHA_DB::table( 'repairs' ),
        );

        foreach ( $tables as $table ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // Reset scan status options.
        delete_option( 'lha_scan_status' );
        delete_option( 'lha_scan_progress' );
        delete_option( 'lha_last_scan_time' );
        delete_transient( 'lha_notice_check' );

        wp_send_json_success( array(
            'message' => __( 'All plugin data has been reset.', 'linkvitals' ),
        ) );
    }
}
