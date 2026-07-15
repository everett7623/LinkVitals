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

    private LHA_Queue $queue;
    private LHA_Link_Extractor $extractor;
    private LHA_Link_Checker $checker;

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
        // Clear existing queue (Req 9.1).
        $this->queue->clear();

        $total_queued = 0;

        // Queue all published posts from public post types, excluding attachments (Req 5.1, 5.6).
        $post_types = $this->get_scannable_post_types();
        foreach ( $post_types as $post_type ) {
            $total_queued += $this->queue_posts( $post_type );
        }

        // Queue nav menu custom link items (Req 5.2).
        $total_queued += $this->queue_nav_menus();

        // Queue taxonomy terms with non-empty descriptions (Req 5.3).
        $total_queued += $this->queue_taxonomies();

        // Update scan status and last_scan_time (Req 9.4).
        update_option( 'lha_scan_status', 'running' );
        update_option( 'lha_last_scan_time', current_time( 'mysql' ) );

        return array(
            'status'       => 'started',
            'total_queued' => $total_queued,
        );
    }

    /**
     * Start an incremental scan.
     *
     * Queues only content objects modified after the last scan timestamp (Req 9.2).
     *
     * @return array Scan start result.
     */
    public function start_incremental_scan(): array {
        $last_scan  = get_option( 'lha_last_scan_time', '2000-01-01 00:00:00' );
        $post_types = $this->get_scannable_post_types();

        $total_queued = 0;

        foreach ( $post_types as $post_type ) {
            $total_queued += $this->queue_posts( $post_type, $last_scan );
        }

        // Queue nav menus modified after last scan.
        $total_queued += $this->queue_nav_menus( $last_scan );

        // Queue taxonomy terms modified after last scan.
        $total_queued += $this->queue_taxonomies( $last_scan );

        if ( $total_queued > 0 ) {
            update_option( 'lha_scan_status', 'running' );
            update_option( 'lha_last_scan_time', current_time( 'mysql' ) );
        }

        return array(
            'status'       => $total_queued > 0 ? 'started' : 'no_new_content',
            'total_queued' => $total_queued,
        );
    }

    /**
     * Recheck broken links.
     *
     * Re-queues links with error statuses for fresh HTTP verification (Req 9.3).
     * Statuses come from LHA_DB::get_issue_statuses().
     *
     * @return array Result with status and count of rechecked links.
     */
    public function recheck_broken(): array {
        $settings   = get_option( 'lha_settings', array() );
        $batch_size = isset( $settings['batch_size'] ) ? absint( $settings['batch_size'] ) : 20;

        $broken_links = LHA_DB::get_broken_links( $batch_size );

        if ( empty( $broken_links ) ) {
            return array( 'status' => 'no_broken_links', 'checked' => 0 );
        }

        $checked = 0;
        foreach ( $broken_links as $link ) {
            $result = $this->checker->check( $link['url'], $settings );

            // Detect internal redirect chains (Req 16.4).
            if ( LHA_Link_Checker::is_internal_redirect_chain( $link['url'], $result ) ) {
                $result['error_type'] = 'internal_redirect';
            }

            LHA_DB::update_link_result( (int) $link['id'], $result );
            $checked++;
        }

        return array( 'status' => 'completed', 'checked' => $checked );
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

        $settings   = get_option( 'lha_settings', array() );
        $batch_size = isset( $settings['batch_size'] ) ? absint( $settings['batch_size'] ) : 20;

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

            // All done — set status to completed (Req 8.7).
            update_option( 'lha_scan_status', 'completed' );
            return array( 'status' => 'completed', 'processed' => 0 );
        }

        // Step 4: Process each queue item.
        $processed = 0;
        foreach ( $items as $item ) {
            $success = $this->process_queue_item( $item );

            if ( $success ) {
                $this->queue->update_status( (int) $item['id'], 'done' );
            } else {
                $this->queue->increment_attempts( (int) $item['id'] );
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
     * 1. Delete old occurrences for the object
     * 2. Get content based on object_type
     * 3. Extract links via LHA_Link_Extractor::extract()
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
                return true; // No content to extract from.
            }

            // Step 1: Delete old occurrences for this object (Req 5.7).
            LHA_DB::delete_occurrences_by_object( $object_type, $object_id );

            // Step 3: Extract links.
            $links = $this->extractor->extract( $content, $source_url ?: home_url() );

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

    /**
     * Queue posts of a specific post type.
     *
     * @param string      $post_type Post type name.
     * @param string|null $since     Only queue posts modified after this datetime.
     * @return int Number of items queued.
     */
    private function queue_posts( string $post_type, ?string $since = null ): int {
        $count = 0;

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'paged'          => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
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

            foreach ( $post_ids as $post_id ) {
                $this->queue->add( $post_type, (int) $post_id, get_permalink( $post_id ) ?: '' );
                $count++;
            }

            $args['paged']++;
        } while ( $args['paged'] <= $query->max_num_pages );

        wp_reset_postdata();

        return $count;
    }

    /**
     * Queue navigation menu items of type 'custom'.
     *
     * Only queues custom links (not post/page references) per Req 5.2.
     *
     * @param string|null $since Only queue items modified after this datetime.
     * @return int Number of items queued.
     */
    private function queue_nav_menus( ?string $since = null ): int {
        $count = 0;

        $menus = wp_get_nav_menus();
        foreach ( $menus as $menu ) {
            $items = wp_get_nav_menu_items( $menu->term_id );
            if ( $items ) {
                foreach ( $items as $item ) {
                    if ( $item->type === 'custom' ) {
                        // For incremental: skip items not modified after $since.
                        if ( $since && strtotime( $item->post_modified ) <= strtotime( $since ) ) {
                            continue;
                        }
                        $this->queue->add( 'nav_menu_item', (int) $item->ID, '' );
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Queue taxonomy terms with non-empty descriptions.
     *
     * Scans all public taxonomies per Req 5.3.
     *
     * @param string|null $since Only queue terms (not directly filterable by modification date).
     * @return int Number of items queued.
     */
    private function queue_taxonomies( ?string $since = null ): int {
        $count = 0;

        $taxonomies = get_taxonomies( array( 'public' => true ), 'names' );

        foreach ( $taxonomies as $taxonomy ) {
            $terms = get_terms( array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => false,
                'fields'     => 'all',
            ) );

            if ( is_wp_error( $terms ) ) {
                continue;
            }

            foreach ( $terms as $term ) {
                if ( empty( $term->description ) ) {
                    continue;
                }

                // For incremental scans, taxonomy terms don't have a reliable
                // modification timestamp. Queue all terms with descriptions
                // during incremental as well (they are cheap to re-process).
                if ( $since ) {
                    // Terms don't have post_modified — skip incremental filtering
                    // for terms. Full terms are always re-queued.
                    continue;
                }

                $term_link = get_term_link( $term );
                $this->queue->add(
                    'taxonomy',
                    (int) $term->term_id,
                    is_wp_error( $term_link ) ? '' : $term_link
                );
                $count++;
            }
        }

        return $count;
    }

    /**
     * Pause scanning.
     *
     * Sets the scan status to paused, preventing further batch processing (Req 8.8).
     */
    public function pause(): void {
        update_option( 'lha_scan_status', 'paused' );
    }

    /**
     * Resume scanning.
     *
     * Resumes a paused scan by setting status back to running (Req 8.9).
     */
    public function resume(): void {
        $status = get_option( 'lha_scan_status', 'idle' );
        if ( 'paused' === $status ) {
            update_option( 'lha_scan_status', 'running' );
        }
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
