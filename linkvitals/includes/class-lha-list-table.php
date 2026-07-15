<?php
/**
 * List Table class
 *
 * Extends WP_List_Table to display links report
 * with filtering, sorting, pagination, and bulk actions.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class LHA_List_Table extends WP_List_Table {

    /**
     * Occurrence summaries keyed by link ID for the current page.
     *
     * @var array<int, array>
     */
    private array $occurrence_summaries = array();

    /**
     * Whether the current request's bulk action has already been handled.
     */
    private bool $bulk_action_processed = false;

    public function __construct() {
        parent::__construct( array(
            'singular' => 'link',
            'plural'   => 'links',
            'ajax'     => false,
        ) );
    }

    /**
     * Define table columns
     */
    public function get_columns(): array {
        return array(
            'cb'           => '<input type="checkbox" />',
            'status'       => __( 'Status', 'linkvitals' ),
            'url'          => __( 'URL', 'linkvitals' ),
            'link_type'    => __( 'Type', 'linkvitals' ),
            'source_title' => __( 'Source', 'linkvitals' ),
            'anchor_text'  => __( 'Anchor Text', 'linkvitals' ),
            'http_code'    => __( 'HTTP Code', 'linkvitals' ),
            'response_time' => __( 'Response', 'linkvitals' ),
            'last_checked' => __( 'Last Checked', 'linkvitals' ),
        );
    }

    /**
     * Define sortable columns
     */
    public function get_sortable_columns(): array {
        return array(
            'url'           => array( 'url', false ),
            'status'        => array( 'status', false ),
            'http_code'     => array( 'http_code', false ),
            'response_time' => array( 'response_time', false ),
            'last_checked'  => array( 'last_checked', true ),
        );
    }

    /**
     * Define bulk actions
     */
    public function get_bulk_actions(): array {
        return array(
            'recheck'     => __( 'Recheck', 'linkvitals' ),
            'ignore'      => __( 'Ignore', 'linkvitals' ),
            'unignore'    => __( 'Unignore', 'linkvitals' ),
            'replace_url' => __( 'Replace URL', 'linkvitals' ),
            'unlink'      => __( 'Unlink', 'linkvitals' ),
        );
    }

    /**
     * Prepare items for display
     */
    public function prepare_items(): void {
        $per_page = 20;
        $current_page = $this->get_pagenum();

        $this->process_bulk_action();

        $args = array(
            'status'   => isset( $_GET['link_status'] ) ? LHA_DB::sanitize_report_filter_key( wp_unslash( $_GET['link_status'] ) ) : '',
            'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
            'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'last_checked',
            'order'    => isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC',
            'per_page' => $per_page,
            'offset'   => ( $current_page - 1 ) * $per_page,
        );

        $data = LHA_DB::get_links( $args );

        $this->items = $data['items'];
        $this->occurrence_summaries = LHA_DB::get_occurrence_summaries(
            array_map( 'absint', array_column( $this->items, 'id' ) )
        );

        $this->set_pagination_args( array(
            'total_items' => $data['total'],
            'per_page'    => $per_page,
            'total_pages' => ceil( $data['total'] / $per_page ),
        ) );

        $this->_column_headers = array(
            $this->get_columns(),
            array(), // hidden
            $this->get_sortable_columns(),
        );

    }

    /**
     * Get the cached occurrence summary for a table item.
     */
    private function get_occurrence_summary( array $item ): ?array {
        $link_id = (int) ( $item['id'] ?? 0 );
        return $this->occurrence_summaries[ $link_id ] ?? null;
    }

    /**
     * Determine whether the latest occurrence can be safely unlinked.
     */
    private function is_anchor_href_occurrence( ?array $occurrence_summary ): bool {
        if ( empty( $occurrence_summary ) ) {
            return false;
        }

        return 'a' === strtolower( (string) ( $occurrence_summary['html_tag'] ?? '' ) )
            && 'href' === strtolower( (string) ( $occurrence_summary['attribute_name'] ?? '' ) );
    }

    /**
     * Build a short repair suggestion for the current issue.
     */
    private function get_repair_suggestion( array $item, ?array $occurrence_summary, bool $is_internal_redirect_chain ): string {
        if ( empty( $occurrence_summary ) ) {
            return '';
        }

        $status    = (string) ( $item['status'] ?? '' );
        $link_type = (string) ( $item['link_type'] ?? '' );
        $http_code = (int) ( $item['http_code'] ?? 0 );

        if ( 'redirect' === $status ) {
            return '';
        } elseif ( 404 === $http_code ) {
            if ( 'image' === $link_type ) {
                $suggestion = __( 'Suggestion: restore the missing media file or replace this image URL with the correct attachment URL.', 'linkvitals' );
            } elseif ( in_array( $link_type, array( 'download', 'media' ), true ) ) {
                $suggestion = __( 'Suggestion: restore the missing file or replace this file URL with the current file URL.', 'linkvitals' );
            } elseif ( $this->is_anchor_href_occurrence( $occurrence_summary ) ) {
                $suggestion = __( 'Suggestion: replace the URL with a live page, remove the link, or create a site redirect if the old URL must keep working.', 'linkvitals' );
            } else {
                $suggestion = __( 'Suggestion: restore the missing resource or replace the URL in the source content.', 'linkvitals' );
            }
        } elseif ( in_array( $status, array( 'server_error', 'timeout', 'ssl_error', 'dns_error', 'forbidden' ), true ) ) {
            $suggestion = __( 'Suggestion: fix the server or domain issue, then recheck. Replace the source URL only when the target has moved.', 'linkvitals' );
        } else {
            return '';
        }

        return '<br><small class="lha-repair-suggestion">' . esc_html( $suggestion ) . '</small>';
    }

    /**
     * Checkbox column
     */
    public function column_cb( $item ): string {
        return sprintf(
            '<input type="checkbox" name="link_ids[]" value="%d" />',
            absint( $item['id'] )
        );
    }

    /**
     * Status column with badge
     */
    public function column_status( $item ): string {
        $status = $item['status'];
        $badge_class = match( $status ) {
            'ok'           => 'lha-badge-ok',
            'broken'       => 'lha-badge-danger',
            'redirect'     => 'lha-badge-warning',
            'timeout'      => 'lha-badge-warning',
            'ssl_error'    => 'lha-badge-danger',
            'dns_error'    => 'lha-badge-danger',
            'server_error' => 'lha-badge-danger',
            'forbidden'    => 'lha-badge-warning',
            'ignored'      => 'lha-badge-muted',
            default        => 'lha-badge-muted',
        };

        if ( $item['is_ignored'] ) {
            $status = 'ignored';
            $badge_class = 'lha-badge-muted';
        }

        return sprintf( '<span class="lha-badge %s">%s</span>', esc_attr( $badge_class ), esc_html( $status ) );
    }

    /**
     * URL column with row actions and inline quick-edit
     */
    public function column_url( $item ): string {
        $occurrence_summary = $this->get_occurrence_summary( $item );
        $has_occurrences = ! empty( $occurrence_summary );
        $is_anchor_href = $this->is_anchor_href_occurrence( $occurrence_summary );
        $is_internal_redirect_chain = LHA_Link_Checker::is_internal_redirect_chain( (string) $item['url'], $item );

        $url_display = esc_html( mb_substr( $item['url'], 0, 80 ) );
        if ( mb_strlen( $item['url'] ) > 80 ) {
            $url_display .= '&hellip;';
        }

        $output = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">%s</a>',
            esc_url( $item['url'] ),
            esc_attr( $item['url'] ),
            $url_display
        );

        // Show final URL if redirect (clickable suggestion)
        if ( ! empty( $item['final_url'] ) && $item['final_url'] !== $item['url'] ) {
            $output .= '<br><small class="lha-redirect-hint">' . esc_html__( 'Redirect to:', 'linkvitals' ) . ' <code>' . esc_html( mb_substr( $item['final_url'], 0, 70 ) ) . '</code></small>';
        }

        if ( ! $has_occurrences ) {
            $output .= '<br><small style="color:#d63638;">' . esc_html__( 'No source occurrence found (stale record).', 'linkvitals' ) . '</small>';
        }

        $output .= $this->get_repair_suggestion( $item, $occurrence_summary, $is_internal_redirect_chain );

        // Inline quick-edit form (hidden by default)
        if ( $has_occurrences ) {
            $prefill = '';
            if ( $item['status'] === 'redirect' && ! empty( $item['final_url'] ) && $is_internal_redirect_chain ) {
                $prefill = $item['final_url'];
            }
            $output .= sprintf(
                '<div class="lha-inline-edit" data-link-id="%d" data-old-url="%s" style="display:none;margin-top:8px;">'
                . '<input type="url" class="lha-new-url-input regular-text" placeholder="%s" value="%s" style="width:100%%;margin-bottom:5px;" />'
                . '<p class="lha-repair-note">%s</p>'
                . '<button type="button" class="button button-small button-primary lha-inline-save">%s</button> '
                . '<button type="button" class="button button-small lha-inline-cancel">%s</button>'
                . '<span class="lha-inline-status" style="margin-left:8px;"></span>'
                . '</div>',
                absint( $item['id'] ),
                esc_attr( $item['url'] ),
                esc_attr__( 'Enter replacement URL...', 'linkvitals' ),
                esc_attr( $prefill ),
                esc_html__( 'Replaces the URL in source content. It does not create a redirect.', 'linkvitals' ),
                esc_html__( 'Save', 'linkvitals' ),
                esc_html__( 'Cancel', 'linkvitals' )
            );
        }

        // Row actions
        $actions = array();

        // Primary replacement action for actual failures.
        if ( $has_occurrences && in_array( $item['status'], array( 'broken', 'server_error', 'dns_error', 'timeout', 'ssl_error' ), true ) ) {
            $actions['edit_url'] = sprintf(
                '<a href="#" class="lha-action-edit-url" data-link-id="%d" style="color:#b32d2e;font-weight:600;">%s</a>',
                absint( $item['id'] ),
                esc_html__( 'Replace URL', 'linkvitals' )
            );
        }

        // One-click replace for redirects
        if ( $has_occurrences && $is_internal_redirect_chain ) {
            $actions['use_final'] = sprintf(
                '<a href="#" class="lha-action-replace" data-link-id="%d" data-old-url="%s" data-new-url="%s" style="color:#00a32a;">%s</a>',
                absint( $item['id'] ),
                esc_attr( $item['url'] ),
                esc_attr( $item['final_url'] ),
                esc_html__( 'Use final URL', 'linkvitals' )
            );
        }

        $actions['recheck'] = sprintf(
            '<a href="#" class="lha-action-recheck" data-link-id="%d">%s</a>',
            absint( $item['id'] ),
            esc_html__( 'Recheck', 'linkvitals' )
        );

        if ( $item['is_ignored'] ) {
            $actions['unignore'] = sprintf(
                '<a href="#" class="lha-action-unignore" data-link-id="%d">%s</a>',
                absint( $item['id'] ),
                esc_html__( 'Unignore', 'linkvitals' )
            );
        } else {
            $actions['ignore'] = sprintf(
                '<a href="#" class="lha-action-ignore" data-link-id="%d">%s</a>',
                absint( $item['id'] ),
                esc_html__( 'Ignore', 'linkvitals' )
            );
        }

        // Unlink for broken links
        if ( $has_occurrences && $is_anchor_href && in_array( $item['status'], array( 'broken', 'server_error', 'dns_error' ), true ) ) {
            $actions['unlink'] = sprintf(
                '<a href="#" class="lha-action-unlink" data-link-id="%d">%s</a>',
                absint( $item['id'] ),
                esc_html__( 'Unlink', 'linkvitals' )
            );
        }

        return $output . $this->row_actions( $actions );
    }

    /**
     * Link type column
     */
    public function column_link_type( $item ): string {
        return esc_html( ucfirst( $item['link_type'] ) );
    }

    /**
     * Source title column - shows first occurrence
     */
    public function column_source_title( $item ): string {
        $first = $this->get_occurrence_summary( $item );
        if ( empty( $first ) ) {
            return '-';
        }

        $output = '';

        if ( ! empty( $first['edit_url'] ) ) {
            $output = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $first['edit_url'] ),
                esc_html( mb_substr( $first['source_title'], 0, 50 ) )
            );
        } else {
            $output = esc_html( mb_substr( $first['source_title'], 0, 50 ) );
        }

        $count = (int) ( $first['occurrence_count'] ?? 1 );
        if ( $count > 1 ) {
            $output .= sprintf( ' <small>(+%d)</small>', $count - 1 );
        }

        return $output;
    }

    /**
     * Anchor text column
     */
    public function column_anchor_text( $item ): string {
        $first = $this->get_occurrence_summary( $item );
        if ( empty( $first ) || empty( $first['anchor_text'] ) ) {
            return '-';
        }
        return esc_html( mb_substr( $first['anchor_text'], 0, 50 ) );
    }

    /**
     * HTTP code column
     */
    public function column_http_code( $item ): string {
        $code = (int) $item['http_code'];
        if ( $code === 0 ) {
            return '-';
        }

        $class = '';
        if ( $code >= 200 && $code < 300 ) {
            $class = 'lha-code-ok';
        } elseif ( $code >= 300 && $code < 400 ) {
            $class = 'lha-code-redirect';
        } else {
            $class = 'lha-code-error';
        }

        return sprintf( '<span class="%s">%d</span>', esc_attr( $class ), $code );
    }

    /**
     * Response time column
     */
    public function column_response_time( $item ): string {
        $time = (float) $item['response_time'];
        if ( $time === 0.0 ) {
            return '-';
        }
        return esc_html( number_format( $time, 0 ) . 'ms' );
    }

    /**
     * Last checked column
     */
    public function column_last_checked( $item ): string {
        if ( $item['last_checked'] === '0000-00-00 00:00:00' ) {
            return esc_html__( 'Never', 'linkvitals' );
        }
        return esc_html( human_time_diff( strtotime( $item['last_checked'] ) ) . ' ' . __( 'ago', 'linkvitals' ) );
    }

    /**
     * Default column handler
     */
    public function column_default( $item, $column_name ): string {
        return esc_html( $item[ $column_name ] ?? '' );
    }

    /**
     * Message when no items found
     */
    public function no_items(): void {
        esc_html_e( 'No links found. Run a scan to discover links.', 'linkvitals' );
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_action(): void {
        if ( $this->bulk_action_processed ) {
            return;
        }

        $action = $this->current_action();
        if ( ! $action ) {
            return;
        }

        // Verify nonce for bulk actions
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'bulk-links' ) ) {
            return;
        }

        if ( ! LHA_Security::check_permission() ) {
            return;
        }

        $raw_link_ids = isset( $_GET['link_ids'] ) ? (array) wp_unslash( $_GET['link_ids'] ) : array();
        $link_ids     = array_values( array_filter( array_map( 'absint', $raw_link_ids ) ) );
        if ( empty( $link_ids ) ) {
            return;
        }

        $this->bulk_action_processed = true;

        // For replace_url and unlink, redirect to confirmation page.
        if ( in_array( $action, array( 'replace_url', 'unlink' ), true ) ) {
            $redirect_url = add_query_arg( array(
                'page'            => 'lha-dashboard',
                'tab'             => 'report',
                'lha_bulk_action' => $action,
                'link_ids'        => $link_ids,
                '_wpnonce'        => wp_create_nonce( 'lha_bulk_confirm_nonce' ),
            ), admin_url( 'tools.php' ) );

            wp_safe_redirect( $redirect_url );
            exit;
        }

        $settings = get_option( 'lha_settings', array() );
        $checker = new LHA_Link_Checker();

        switch ( $action ) {
            case 'recheck':
                foreach ( $link_ids as $link_id ) {
                    $link = LHA_DB::get_link( $link_id );
                    if ( $link ) {
                        $result = $checker->check( $link['url'], $settings );
                        LHA_DB::update_link_result( $link_id, $result );
                    }
                }
                break;

            case 'ignore':
                foreach ( $link_ids as $link_id ) {
                    $link = LHA_DB::get_link( $link_id );
                    if ( ! $link ) {
                        continue;
                    }
                    LHA_DB::ignore_link( $link_id, 'bulk_action' );
                    LHA_Logger::log( 'link_ignored', $link['url'], '', '', array( $link_id ), __( 'Link ignored by bulk action', 'linkvitals' ) );
                }
                break;

            case 'unignore':
                foreach ( $link_ids as $link_id ) {
                    $link = LHA_DB::get_link( $link_id );
                    if ( ! $link ) {
                        continue;
                    }
                    LHA_DB::unignore_link( $link_id );
                    LHA_Logger::log( 'link_unignored', $link['url'], '', '', array( $link_id ), __( 'Link unignored by bulk action', 'linkvitals' ) );
                }
                break;
        }
    }
}
