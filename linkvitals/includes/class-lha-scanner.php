<?php
/**
 * Scanner class
 *
 * Orchestrates the scanning process:
 * - Populates the scan queue with content objects
 * - Processes queue items in batches
 * - Extracts links and stores them
 * - Triggers link checking
 *
 * @package LinkVitals
 * @requires PHP 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Scanner {

    private const CONTENT_SCAN_TYPES = array( 'full', 'incremental' );
    private const DEFAULT_SCAN_CURSOR = '2000-01-01 00:00:00';
    private const SOURCE_PAGE_SIZE = 100;
    private const SCAN_STATE_LOCK_OPTION = 'lha_scan_state_lock';
    private const SCAN_STATE_LOCK_MINUTES = 15;

    private LHA_Queue $queue;
    private LHA_Link_Extractor $extractor;
    private LHA_Link_Checker $checker;
    private string $last_item_error = '';
    private string $scan_state_lock_token = '';

    public function __construct() {
        $this->queue     = new LHA_Queue();
        $this->extractor = new LHA_Link_Extractor();
        $this->checker   = new LHA_Link_Checker();
    }

    /**
     * Start a full scan.
     *
     * Clears the existing queue, populates it with all scannable content
     * objects, and sets the scan status to running.
     *
     * @return array Scan start result with status and total_queued.
     */
    public function start_full_scan(): array {
        $started_at = current_time( 'mysql' );
        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return array( 'status' => 'already_running', 'total_queued' => 0 );
        }

        $this->scan_state_lock_token = $lock_token;

        try {
            if ( self::is_scan_active() ) {
                return array( 'status' => 'already_running', 'total_queued' => 0 );
            }

            LHA_Cron::begin_notification_tracking( true );

            // Clear existing queue (Req 9.1).
            $this->queue->clear();

            $total_queued = 0;

            // Queue all published posts from public post types, excluding attachments (Req 5.1, 5.6).
            $post_types = $this->get_scannable_post_types();
            $this->cleanup_stale_sources( $post_types );
            foreach ( $post_types as $post_type ) {
                $total_queued += $this->queue_posts( $post_type, null, false );
            }

            // Queue nav menu custom link items (Req 5.2).
            $total_queued += $this->queue_nav_menus( null, false );

            // Queue taxonomy terms with non-empty descriptions (Req 5.3).
            $total_queued += $this->queue_taxonomies( null, false );

            self::write_scan_start( 'full', $started_at );

            return array(
                'status'       => 'started',
                'total_queued' => $total_queued,
            );
        } catch ( \Throwable $error ) {
            LHA_Cron::clear_notification_tracking();
            throw $error;
        } finally {
            self::release_scan_state_lock( $lock_token );
            $this->scan_state_lock_token = '';
        }
    }

    /**
     * Start an incremental scan.
     *
     * Queues only content objects modified after the last scan timestamp (Req 9.2).
     *
     * @return array Scan start result.
     */
    public function start_incremental_scan(): array {
        $started_at = current_time( 'mysql' );
        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return array( 'status' => 'already_running', 'total_queued' => 0 );
        }

        $this->scan_state_lock_token = $lock_token;

        try {
            if ( self::is_scan_active() ) {
                return array( 'status' => 'already_running', 'total_queued' => 0 );
            }

            $last_scan = self::get_content_scan_cursor();
            LHA_Cron::begin_notification_tracking( true );
            $post_types = $this->get_scannable_post_types();
            $this->cleanup_stale_sources( $post_types );

            $total_queued = 0;

            foreach ( $post_types as $post_type ) {
                $total_queued += $this->queue_posts( $post_type, $last_scan );
            }

            // Queue nav menus modified after last scan.
            $total_queued += $this->queue_nav_menus( $last_scan );

            // Queue taxonomy terms modified after last scan.
            $total_queued += $this->queue_taxonomies( $last_scan );

            if ( $total_queued > 0 ) {
                self::write_scan_start( 'incremental', $started_at );
            } else {
                LHA_Cron::clear_notification_tracking();
            }

            return array(
                'status'       => $total_queued > 0 ? 'started' : 'no_new_content',
                'total_queued' => $total_queued,
            );
        } catch ( \Throwable $error ) {
            LHA_Cron::clear_notification_tracking();
            throw $error;
        } finally {
            self::release_scan_state_lock( $lock_token );
            $this->scan_state_lock_token = '';
        }
    }

    /**
     * Recheck broken links.
     *
     * Resets links with error statuses for bounded background verification.
     * The existing queue pipeline then drains every pending link in batches.
     *
     * @return array Result with status and count of links queued for rechecking.
     */
    public function recheck_broken(): array {
        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return array( 'status' => 'already_running', 'queued' => 0 );
        }

        try {
            if ( self::is_scan_active() ) {
                return array( 'status' => 'already_running', 'queued' => 0 );
            }

            LHA_Cron::begin_notification_tracking( true );
            $queued = LHA_DB::reset_issue_links_for_recheck();
            if ( $queued < 1 ) {
                LHA_Cron::clear_notification_tracking();
                return array( 'status' => 'no_broken_links', 'queued' => 0 );
            }

            self::write_scan_start( 'recheck' );

            return array( 'status' => 'started', 'queued' => $queued );
        } catch ( \Throwable $error ) {
            LHA_Cron::clear_notification_tracking();
            throw $error;
        } finally {
            self::release_scan_state_lock( $lock_token );
        }
    }

    /**
     * Queue every non-ignored link for a version-upgrade recheck.
     *
     * Active scans retain their status and generation. Inactive sites start a
     * dedicated recheck generation after the notification baseline is captured.
     *
     * @return array{status:string,queued:int}
     */
    public function queue_all_links_for_recheck(): array {
        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return array( 'status' => 'busy', 'queued' => 0 );
        }

        try {
            $active = self::is_scan_active();
            if ( ! $active ) {
                LHA_Cron::begin_notification_tracking( true );
            }

            $queued = LHA_DB::reset_links_for_recheck();
            if ( false === $queued ) {
                if ( ! $active ) {
                    LHA_Cron::clear_notification_tracking();
                }
                return array( 'status' => 'failed', 'queued' => 0 );
            }

            if ( $queued < 1 ) {
                if ( ! $active ) {
                    LHA_Cron::clear_notification_tracking();
                }
                return array( 'status' => 'no_links', 'queued' => 0 );
            }

            if ( ! $active ) {
                self::write_scan_start( 'recheck' );
                return array( 'status' => 'started', 'queued' => $queued );
            }

            return array(
                'status' => (string) get_option( 'lha_scan_status', 'running' ),
                'queued' => $queued,
            );
        } finally {
            self::release_scan_state_lock( $lock_token );
        }
    }

    /**
     * Process a batch from the queue.
     *
     * Called by WP-Cron or AJAX. Follows the design flow:
     * 1. Check lha_scan_status — if not 'running', return early (Req 8.6)
     * 2. Reset stuck items via queue->reset_stuck()
     * 3. Get pending items via queue->get_pending(batch_size)
     * 4. For each item: call process_queue_item()
     * 5. After extraction batch: get unchecked links, check them
     * 6. If no more pending AND no unchecked links: set status 'completed'
     *
     * @return array Processing result.
     */
    public function process_queue_batch(): array {
        // Step 1: Check scan status (Req 8.6).
        $status = get_option( 'lha_scan_status', 'idle' );
        if ( 'running' !== $status ) {
            return array( 'status' => $status, 'processed' => 0 );
        }

        $scan_token = (string) get_option( 'lha_scan_token', '' );
        $settings   = get_option( 'lha_settings', array() );
        $batch_size = isset( $settings['batch_size'] )
            ? min( 100, max( 1, absint( $settings['batch_size'] ) ) )
            : 20;

        // Step 2: Reset stuck items (processing > 10 minutes).
        $this->queue->reset_stuck();

        // Step 3: Get pending items.
        $items = $this->queue->get_pending( $batch_size );

        if ( empty( $items ) ) {
            // Another worker may already own queue items. Do not complete the
            // scan before that worker finishes extracting and enqueuing links.
            $counts = $this->queue->get_counts();
            if ( ! empty( $counts['pending'] ) || ! empty( $counts['processing'] ) ) {
                return array( 'status' => 'running', 'processed' => 0 );
            }

            // Step 5/6: No pending items — check for unchecked links.
            $unchecked = LHA_DB::get_unchecked_links( $batch_size );

            if ( ! empty( $unchecked ) ) {
                $this->check_links_batch( $unchecked, $settings );
                return array( 'status' => 'checking_links', 'processed' => count( $unchecked ) );
            }

            // All done — remove links without sources and mark completed (Req 8.7).
            LHA_DB::cleanup_orphaned_links();
            if ( ! $this->record_scan_completion( $scan_token ) ) {
                return array(
                    'status'    => get_option( 'lha_scan_status', 'running' ),
                    'processed' => 0,
                );
            }
            return array( 'status' => 'completed', 'processed' => 0 );
        }

        // Step 4: Process each queue item.
        $processed = 0;
        foreach ( $items as $item ) {
            $claim_token = (string) ( $item['claim_token'] ?? '' );
            if ( '' === $claim_token ) {
                continue;
            }

            $success = $this->process_queue_item( $item );

            if ( $success ) {
                $this->queue->update_status( (int) $item['id'], 'done', $claim_token );
            } else {
                $this->queue->increment_attempts( (int) $item['id'], $this->last_item_error, $claim_token );
            }

            $processed++;
        }

        // Step 5: After extraction, check unchecked links in same batch.
        $unchecked = LHA_DB::get_unchecked_links( $batch_size );
        if ( ! empty( $unchecked ) ) {
            $this->check_links_batch( $unchecked, $settings );
        }

        return array( 'status' => 'running', 'processed' => $processed );
    }

    /**
     * Process a single queue item.
     *
     * Flow (Req 5.7):
     * 1. Get content based on object_type
     * 2. Extract links via LHA_Link_Extractor::extract()
     * 3. Delete old occurrences only after extraction succeeds
     * 4. For each extracted link: upsert_link(), insert_occurrence()
     * 5. On success: queue marks item done (handled by caller)
     * 6. On failure: queue increments attempts (handled by caller)
     *
     * @param array $item Queue item row.
     * @return bool True on success, false on failure.
     */
    private function process_queue_item( array $item ): bool {
        $object_type = $item['object_type'];
        $object_id   = (int) $item['object_id'];

        $this->last_item_error = '';

        $content      = '';
        $source_title = '';
        $source_url   = '';
        $edit_url     = '';

        try {
            switch ( $object_type ) {
                case 'nav_menu_item':
                    $menu_item = get_post( $object_id );
                    if ( ! $menu_item ) {
                        return true; // Object no longer exists, skip.
                    }
                    $url = get_post_meta( $object_id, '_menu_item_url', true );
                    if ( ! empty( $url ) ) {
                        $content = '<a href="' . esc_attr( $url ) . '">' . esc_html( $menu_item->post_title ) . '</a>';
                    }
                    /* translators: %s: menu item title */
                    $source_title = sprintf( __( 'Menu: %s', 'linkvitals' ), $menu_item->post_title );
                    $source_url   = '';
                    $edit_url     = admin_url( 'nav-menus.php' );
                    break;

                case 'taxonomy':
                    $term = get_term( $object_id );
                    if ( ! $term || is_wp_error( $term ) ) {
                        return true; // Term no longer exists, skip.
                    }
                    $content      = $term->description;
                    /* translators: 1: taxonomy name, 2: term name */
                    $source_title = sprintf( __( '%1$s: %2$s', 'linkvitals' ), $term->taxonomy, $term->name );
                    $term_link    = get_term_link( $term );
                    $source_url   = is_wp_error( $term_link ) ? '' : $term_link;
                    $edit_url     = get_edit_term_link( $term->term_id, $term->taxonomy ) ?: '';
                    break;

                default:
                    // Posts, pages, and custom post types.
                    $post = get_post( $object_id );
                    if ( ! $post || 'publish' !== $post->post_status ) {
                        return true; // Non-published post, skip but mark done.
                    }
                    $content = $post->post_content;

                    // Include excerpt if it contains HTML (Req 5.4).
                    if ( ! empty( $post->post_excerpt ) && $post->post_excerpt !== wp_strip_all_tags( $post->post_excerpt ) ) {
                        $content .= "\n" . $post->post_excerpt;
                    }

                    // WooCommerce product gallery support (Req 5.5).
                    if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
                        $product = wc_get_product( $post->ID );
                        if ( $product ) {
                            $gallery_ids = $product->get_gallery_image_ids();
                            foreach ( $gallery_ids as $img_id ) {
                                $img_url = wp_get_attachment_url( $img_id );
                                if ( $img_url ) {
                                    $content .= "\n" . '<img src="' . esc_attr( $img_url ) . '" />';
                                }
                            }
                        }
                    }

                    $source_title = $post->post_title;
                    $source_url   = get_permalink( $post ) ?: '';
                    $edit_url     = get_edit_post_link( $post->ID, 'raw' ) ?: '';
                    break;
            }

            if ( empty( $content ) ) {
                $links = array();
            } else {
                // Extract before replacing occurrences so a parser failure does
                // not discard the last known-good result for this source.
                $links = $this->extractor->extract( $content, $source_url ?: home_url() );
            }

            // Replace old occurrences only after extraction succeeds (Req 5.7).
            LHA_DB::delete_occurrences_by_object( $object_type, $object_id );

            // Step 4: Upsert links and insert occurrences.
            foreach ( $links as $link_data ) {
                $link_id = LHA_DB::upsert_link( array(
                    'url'       => $link_data['url'] ?: 'empty',
                    'link_type' => $link_data['link_type'],
                ) );

                if ( $link_id ) {
                    LHA_DB::insert_occurrence( array(
                        'link_id'         => $link_id,
                        'object_type'     => $object_type,
                        'object_id'       => $object_id,
                        'source_title'    => $source_title,
                        'source_url'      => $source_url ?: '',
                        'edit_url'        => $edit_url ?: '',
                        'html_tag'        => $link_data['html_tag'],
                        'attribute_name'  => $link_data['attribute_name'],
                        'anchor_text'     => $link_data['anchor_text'],
                        'raw_html'        => $link_data['raw_html'],
                        'context_snippet' => mb_substr( $link_data['raw_html'], 0, 200 ),
                    ) );
                }
            }

            return true;
        } catch ( \Throwable $e ) {
            $error = trim( wp_strip_all_tags( $e->getMessage() ) );
            $this->last_item_error = mb_substr( '' !== $error ? $error : get_class( $e ), 0, 1000 );
            return false;
        }
    }

    /**
     * Check a batch of links via HTTP.
     *
     * Applies settings-based filtering (check_images, check_media, etc.)
     * and domain/pattern ignore lists before performing HTTP checks.
     *
     * @param array $links Array of link records to check.
     * @param array $settings Plugin settings.
     */
    private function check_links_batch( array $links, array $settings ): void {
        $anchor_checker = null;
        if ( ! empty( $settings['check_anchors'] ) ) {
            $anchor_checker = new LHA_Anchor_Checker();
        }

        foreach ( $links as $link ) {
            // Skip non-HTTP link types.
            if ( in_array( $link['link_type'], array( 'empty', 'mailto', 'tel', 'javascript', 'malformed' ), true ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'skipped',
                    'error_type' => 'non_http',
                ) );
                continue;
            }

            // Check if external links should be checked.
            if ( $link['link_type'] === 'external' && empty( $settings['check_external'] ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'skipped',
                    'error_type' => 'external_disabled',
                ) );
                continue;
            }

            // Check if images should be checked.
            if ( $link['link_type'] === 'image' && empty( $settings['check_images'] ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'skipped',
                    'error_type' => 'images_disabled',
                ) );
                continue;
            }

            // Check if media should be checked.
            if ( $link['link_type'] === 'media' && empty( $settings['check_media'] ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'skipped',
                    'error_type' => 'media_disabled',
                ) );
                continue;
            }

            // Anchor links — use anchor checker if enabled (Req 15.1, 15.5).
            if ( $link['link_type'] === 'anchor' && $anchor_checker ) {
                $anchor_result = $anchor_checker->check_anchor( $link['url'] );
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => $anchor_result['status'],
                    'error_type' => $anchor_result['error_type'],
                    'http_code'  => $anchor_result['http_code'] ?? 0,
                ) );
                continue;
            } elseif ( $link['link_type'] === 'anchor' && ! $anchor_checker ) {
                // Anchor checking disabled in settings (Req 15.5).
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'skipped',
                    'error_type' => 'anchors_disabled',
                    'http_code'  => 0,
                ) );
                continue;
            }

            // Check domain ignore list.
            if ( $this->is_domain_ignored( $link['url'], $settings ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'ignored',
                    'error_type' => 'domain_ignored',
                ) );
                LHA_DB::ignore_link( (int) $link['id'], 'domain_ignored' );
                continue;
            }

            // Check URL pattern ignore list.
            if ( $this->is_pattern_ignored( $link['url'], $settings ) ) {
                LHA_DB::update_link_result( (int) $link['id'], array(
                    'status'     => 'ignored',
                    'error_type' => 'pattern_ignored',
                ) );
                LHA_DB::ignore_link( (int) $link['id'], 'pattern_ignored' );
                continue;
            }

            // Perform HTTP check.
            $result = $this->checker->check( $link['url'], $settings );

            // Detect internal redirect chains (Req 16.4).
            // When an internal link redirects to another internal URL, flag it
            // for one-click replacement with the final destination.
            if ( LHA_Link_Checker::is_internal_redirect_chain( $link['url'], $result ) ) {
                $result['error_type'] = 'internal_redirect';
            }

            LHA_DB::update_link_result( (int) $link['id'], $result );

            // After HTTP check, verify fragment if present and anchor checking is enabled (Req 15.1).
            if ( $anchor_checker && ( $result['status'] ?? '' ) === 'ok' ) {
                $parsed_url = wp_parse_url( $link['url'] );
                if ( ! empty( $parsed_url['fragment'] ) ) {
                    $anchor_result = $anchor_checker->check_anchor( $link['url'] );
                    if ( $anchor_result['status'] === 'broken' ) {
                        LHA_DB::update_link_result( (int) $link['id'], array(
                            'status'     => 'broken',
                            'error_type' => 'broken_anchor',
                            'http_code'  => $anchor_result['http_code'] ?? 0,
                        ) );
                    }
                }
            }
        }
    }

    /**
     * Check if a URL's domain is in the ignore list.
     *
     * Supports wildcard subdomains (*.example.com matches sub.example.com).
     *
     * @param string $url      The URL to check.
     * @param array  $settings Plugin settings containing ignore_domains.
     * @return bool True if the domain should be ignored.
     */
    private function is_domain_ignored( string $url, array $settings ): bool {
        if ( empty( $settings['ignore_domains'] ) ) {
            return false;
        }

        $domain = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $domain ) ) {
            return false;
        }

        $ignored_domains = array_filter( array_map( 'trim', explode( "\n", $settings['ignore_domains'] ) ) );

        foreach ( $ignored_domains as $ignored ) {
            if ( strcasecmp( $domain, $ignored ) === 0 ) {
                return true;
            }
            // Wildcard subdomain matching: *.example.com
            if ( str_starts_with( $ignored, '*.' ) ) {
                $base = substr( $ignored, 2 );
                if ( str_ends_with( strtolower( $domain ), '.' . strtolower( $base ) ) || strcasecmp( $domain, $base ) === 0 ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if a URL matches any ignore pattern.
     *
     * Supports simple wildcard (*) matching in URL patterns.
     *
     * @param string $url      The URL to check.
     * @param array  $settings Plugin settings containing ignore_patterns.
     * @return bool True if the URL matches an ignore pattern.
     */
    private function is_pattern_ignored( string $url, array $settings ): bool {
        if ( empty( $settings['ignore_patterns'] ) ) {
            return false;
        }

        $patterns = array_filter( array_map( 'trim', explode( "\n", $settings['ignore_patterns'] ) ) );

        foreach ( $patterns as $pattern ) {
            $regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/i';
            if ( preg_match( $regex, $url ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get public post types that should be scanned.
     *
     * Excludes the 'attachment' post type per Req 5.6.
     *
     * @return array Array of post type names.
     */
    private function get_scannable_post_types(): array {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );
        return array_values( $post_types );
    }

    /** Remove occurrence records whose source objects are no longer scannable. */
    private function cleanup_stale_sources( array $post_types ): void {
        $taxonomies = array_values( get_taxonomies( array( 'public' => true ), 'names' ) );
        LHA_DB::cleanup_stale_occurrences( $post_types, $taxonomies );
        LHA_DB::cleanup_orphaned_links();
    }

    /**
     * Queue one repaired post for occurrence refresh without advancing the
     * completed content-scan cursor or resuming an explicitly paused scan.
     */
    public function queue_repair_refresh( string $object_type, int $object_id ): bool {
        $object_type = sanitize_key( $object_type );
        $object_id   = absint( $object_id );
        if ( '' === $object_type || $object_id < 1 ) {
            return false;
        }

        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return false;
        }

        try {
            $queued = $this->queue->add_refresh( $object_type, $object_id, '', 1 );
            if ( false === $queued ) {
                return false;
            }

            if ( ! self::is_scan_active() ) {
                self::write_scan_start( 'repair' );
            }

            return true;
        } finally {
            self::release_scan_state_lock( $lock_token );
        }
    }

    /** Return whether a scan currently owns the shared queue pipeline. */
    private static function is_scan_active(): bool {
        return in_array( get_option( 'lha_scan_status', 'idle' ), array( 'running', 'paused' ), true );
    }

    /** Acquire the site-local mutex used for scan initialization and completion. */
    private static function acquire_scan_state_lock(): string|false {
        $token = wp_generate_uuid4();
        $value = array(
            'token'       => $token,
            'acquired_at' => time(),
        );

        if ( add_option( self::SCAN_STATE_LOCK_OPTION, $value, '', false ) ) {
            return $token;
        }

        $current     = get_option( self::SCAN_STATE_LOCK_OPTION, array() );
        $acquired_at = is_array( $current ) ? (int) ( $current['acquired_at'] ?? 0 ) : 0;
        if ( $acquired_at > time() - ( self::SCAN_STATE_LOCK_MINUTES * MINUTE_IN_SECONDS ) ) {
            return false;
        }

        delete_option( self::SCAN_STATE_LOCK_OPTION );
        return add_option( self::SCAN_STATE_LOCK_OPTION, $value, '', false ) ? $token : false;
    }

    /** Release the state mutex only when it is still owned by this request. */
    private static function release_scan_state_lock( string $token ): void {
        $current = get_option( self::SCAN_STATE_LOCK_OPTION, array() );
        if ( is_array( $current ) && $token === (string) ( $current['token'] ?? '' ) ) {
            delete_option( self::SCAN_STATE_LOCK_OPTION );
        }
    }

    /** Keep a long queue-population request from being mistaken for a stale lock. */
    private function refresh_scan_state_lock(): void {
        if ( '' === $this->scan_state_lock_token ) {
            return;
        }

        $current = get_option( self::SCAN_STATE_LOCK_OPTION, array() );
        if ( ! is_array( $current ) || $this->scan_state_lock_token !== (string) ( $current['token'] ?? '' ) ) {
            return;
        }

        $current['acquired_at'] = time();
        update_option( self::SCAN_STATE_LOCK_OPTION, $current, false );
    }

    /**
     * Record the start of work that uses the shared scan pipeline.
     *
     * @param string      $scan_type  Full, incremental, recheck, or repair.
     * @param string|null $started_at Optional pre-queue timestamp for content scans.
     */
    public static function record_scan_start( string $scan_type, ?string $started_at = null ): void {
        self::write_scan_start( $scan_type, $started_at );
    }

    /** Persist one new scan generation after its queue initialization succeeds. */
    private static function write_scan_start( string $scan_type, ?string $started_at = null ): void {
        if ( false === get_option( 'lha_content_scan_cursor', false ) ) {
            $legacy_cursor = get_option( 'lha_last_scan_time', '' );
            if ( is_string( $legacy_cursor ) && '' !== $legacy_cursor ) {
                update_option( 'lha_content_scan_cursor', $legacy_cursor );
            }
        }

        update_option( 'lha_scan_started_at', $started_at ?: current_time( 'mysql' ) );
        update_option( 'lha_scan_type', $scan_type );
        update_option( 'lha_scan_token', wp_generate_uuid4() );
        update_option( 'lha_scan_status', 'running' );
    }

    /** Return the last completed content-scan boundary, including legacy data. */
    private static function get_content_scan_cursor(): string {
        $cursor = get_option( 'lha_content_scan_cursor', '' );
        if ( ! is_string( $cursor ) || '' === $cursor ) {
            $cursor = get_option( 'lha_last_scan_time', self::DEFAULT_SCAN_CURSOR );
        }

        return is_string( $cursor ) && '' !== $cursor ? $cursor : self::DEFAULT_SCAN_CURSOR;
    }

    /** Mark only the generation observed by this worker as complete. */
    private function record_scan_completion( string $expected_token ): bool {
        $lock_token = self::acquire_scan_state_lock();
        if ( false === $lock_token ) {
            return false;
        }

        try {
            if ( 'running' !== get_option( 'lha_scan_status', 'idle' ) ) {
                return false;
            }

            if ( $expected_token !== (string) get_option( 'lha_scan_token', '' ) ) {
                return false;
            }

            $scan_type  = get_option( 'lha_scan_type', '' );
            $started_at = get_option( 'lha_scan_started_at', '' );

            if ( in_array( $scan_type, self::CONTENT_SCAN_TYPES, true ) && is_string( $started_at ) && '' !== $started_at ) {
                update_option( 'lha_content_scan_cursor', $started_at );
            }

            update_option( 'lha_last_scan_time', current_time( 'mysql' ) );
            update_option( 'lha_scan_status', 'completed' );
            return true;
        } finally {
            self::release_scan_state_lock( $lock_token );
        }
    }

    /**
     * Queue posts of a specific post type.
     *
     * @param string      $post_type Post type name.
     * @param string|null $since     Only queue posts modified after this datetime.
     * @param bool        $check_existing Whether to check for active duplicate work.
     * @return int Number of items queued.
     */
    private function queue_posts( string $post_type, ?string $since = null, bool $check_existing = true ): int {
        $count = 0;

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => self::SOURCE_PAGE_SIZE,
            'paged'          => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        if ( $since ) {
            $args['date_query'] = array(
                array(
                    'after'     => $since,
                    'column'    => 'post_modified',
                    'inclusive' => false,
                ),
            );
        }

        do {
            $query    = new WP_Query( $args );
            $post_ids = $query->posts;
            $items    = array();

            foreach ( $post_ids as $post_id ) {
                $items[] = array(
                    'object_type' => $post_type,
                    'object_id'   => (int) $post_id,
                );
            }
            $count += $this->queue->add_many( $items, $check_existing );
            $this->refresh_scan_state_lock();

            $args['paged']++;
        } while ( count( $post_ids ) === $args['posts_per_page'] );

        wp_reset_postdata();

        return $count;
    }

    /**
     * Queue navigation menu items of type 'custom'.
     *
     * Only queues custom links (not post/page references) per Req 5.2.
     *
     * @param string|null $since Only queue items modified after this datetime.
     * @param bool        $check_existing Whether to check for active duplicate work.
     * @return int Number of items queued.
     */
    private function queue_nav_menus( ?string $since = null, bool $check_existing = true ): int {
        $queue_items = array();

        $menus = wp_get_nav_menus();
        foreach ( $menus as $menu ) {
            $menu_items = wp_get_nav_menu_items( $menu->term_id );
            if ( $menu_items ) {
                foreach ( $menu_items as $item ) {
                    if ( $item->type === 'custom' ) {
                        // For incremental: skip items not modified after $since.
                        if ( $since && strtotime( $item->post_modified ) <= strtotime( $since ) ) {
                            continue;
                        }
                        $queue_items[] = array(
                            'object_type' => 'nav_menu_item',
                            'object_id'   => (int) $item->ID,
                        );
                    }
                }
            }
            $this->refresh_scan_state_lock();
        }

        return $this->queue->add_many( $queue_items, $check_existing );
    }

    /**
     * Queue taxonomy terms with non-empty descriptions.
     *
     * Scans all public taxonomies per Req 5.3.
     *
     * @param string|null $since Only queue terms (not directly filterable by modification date).
     * @param bool        $check_existing Whether to check for active duplicate work.
     * @return int Number of items queued.
     */
    private function queue_taxonomies( ?string $since = null, bool $check_existing = true ): int {
        $count = 0;

        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

        foreach ( $taxonomies as $taxonomy ) {
            $offset = 0;

            do {
                $terms = get_terms( array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'fields'     => 'all',
                    'number'     => self::SOURCE_PAGE_SIZE,
                    'offset'     => $offset,
                    'orderby'    => 'term_id',
                    'order'      => 'ASC',
                    'update_term_meta_cache' => false,
                ) );

                if ( is_wp_error( $terms ) ) {
                    break;
                }

                $items = array();

                foreach ( $terms as $term ) {
                    if ( '' === trim( (string) $term->description ) ) {
                        continue;
                    }

                    // Terms have no reliable modification timestamp, so incremental
                    // scans conservatively re-process every non-empty description.

                    $items[] = array(
                        'object_type' => 'taxonomy',
                        'object_id'   => (int) $term->term_id,
                    );
                }

                $count += $this->queue->add_many( $items, $check_existing );
                $this->refresh_scan_state_lock();
                $offset += self::SOURCE_PAGE_SIZE;
            } while ( count( $terms ) === self::SOURCE_PAGE_SIZE );
        }

        return $count;
    }

    /**
     * Pause scanning.
     *
     * Sets a running scan to paused, preventing further batch processing (Req 8.8).
     *
     * @return string The resulting scan status.
     */
    public function pause(): string {
        $status = (string) get_option( 'lha_scan_status', 'idle' );
        if ( 'running' === $status ) {
            update_option( 'lha_scan_status', 'paused' );
            $status = (string) get_option( 'lha_scan_status', 'idle' );
        }

        return $status;
    }

    /**
     * Resume scanning.
     *
     * Resumes a paused scan by setting status back to running (Req 8.9).
     *
     * @return string The resulting scan status.
     */
    public function resume(): string {
        $status = (string) get_option( 'lha_scan_status', 'idle' );
        if ( 'paused' === $status ) {
            update_option( 'lha_scan_status', 'running' );
            $status = (string) get_option( 'lha_scan_status', 'idle' );
        }

        return $status;
    }

    /**
     * Get current scan progress.
     *
     * Returns the overall progress as a percentage with done and total counts.
     *
     * @return array Progress data with status, total, done, pending, processing, failed, percentage.
     */
    public function get_progress(): array {
        $counts = $this->queue->get_counts();

        $total = array_sum( $counts );
        $done  = ( $counts['done'] ?? 0 ) + ( $counts['failed'] ?? 0 );
        $percentage = $total > 0 ? round( ( $done / $total ) * 100 ) : 0;

        return array(
            'status'     => get_option( 'lha_scan_status', 'idle' ),
            'total'      => $total,
            'done'       => $done,
            'pending'    => $counts['pending'] ?? 0,
            'processing' => $counts['processing'] ?? 0,
            'failed'     => $counts['failed'] ?? 0,
            'percentage' => $percentage,
        );
    }
}
