<?php
/**
 * Internal Link Analyzer class
 *
 * Analyzes internal linking structure:
 * - Inbound/outbound link counts per post
 * - Orphaned pages detection (zero inbound internal links)
 * - Low outbound internal links detection (below configurable threshold)
 * - Flags internal links targeting non-existent, draft, private, or trashed posts
 *
 * Implements Requirements 14.1, 14.2, 14.3, 14.6.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Internal_Analyzer {

    /**
     * Default threshold for low outbound internal links.
     *
     * @var int
     */
    private int $low_outbound_threshold = 2;

    /**
     * Get internal link analysis data for published posts.
     *
     * Queries posts with their inbound/outbound internal link counts,
     * supports filtering by orphaned status or low outbound, and returns
     * paginated results.
     *
     * @param array $args Query arguments (per_page, offset, orderby, order, filter).
     * @return array {items: array, total: int}
     */
    public function get_analysis( array $args = array() ): array {
        global $wpdb;

        $defaults = array(
            'per_page' => 20,
            'offset'   => 0,
            'orderby'  => 'inbound',
            'order'    => 'ASC',
            'filter'   => '', // orphaned, low_outbound
        );

        $args = wp_parse_args( $args, $defaults );

        // Calculate counts for all published posts.
        $counts = $this->calculate_counts();

        // Apply filters.
        if ( ! empty( $args['filter'] ) ) {
            switch ( $args['filter'] ) {
                case 'orphaned':
                    $counts = array_filter( $counts, function ( $item ) {
                        return 0 === $item['inbound'];
                    } );
                    break;
                case 'low_outbound':
                    $counts = array_filter( $counts, function ( $item ) {
                        return $item['outbound'] < $this->low_outbound_threshold;
                    } );
                    break;
                case 'http_links':
                    // Return HTTP links in a standard format for the template.
                    $http_links = $this->find_http_links();
                    $http_items = array();
                    foreach ( $http_links as $hl ) {
                        $http_items[] = array(
                            'post_id'     => 0,
                            'title'       => $hl['url'],
                            'post_type'   => '',
                            'permalink'   => $hl['url'],
                            'edit_url'    => '',
                            'inbound'     => 0,
                            'outbound'    => 0,
                            'is_orphaned' => false,
                            'link_id'     => (int) $hl['id'],
                            'issue'       => 'http_on_https',
                        );
                    }
                    return array(
                        'items' => array_slice( $http_items, (int) $args['offset'], (int) $args['per_page'] ),
                        'total' => count( $http_items ),
                    );
            }
        }

        // Sort results.
        $orderby = $args['orderby'];
        $order   = strtoupper( $args['order'] ) === 'DESC' ? -1 : 1;

        usort( $counts, function ( $a, $b ) use ( $orderby, $order ) {
            $val_a = $a[ $orderby ] ?? 0;
            $val_b = $b[ $orderby ] ?? 0;

            if ( is_string( $val_a ) ) {
                return $order * strcmp( $val_a, $val_b );
            }

            return $order * ( $val_a <=> $val_b );
        } );

        $total = count( $counts );

        // Paginate.
        $items = array_slice( $counts, (int) $args['offset'], (int) $args['per_page'] );

        return array(
            'items' => array_values( $items ),
            'total' => $total,
        );
    }

    /**
     * Calculate inbound and outbound internal link counts for all published posts.
     *
     * Outbound = count of internal links originating from a post (occurrences where
     *            object_id = post.ID and the link is internal).
     * Inbound  = count of distinct source posts that link to this post's URL
     *            via internal links.
     *
     * @return array Array of items with post_id, title, post_type, permalink, edit_url, inbound, outbound, is_orphaned.
     */
    public function calculate_counts(): array {
        global $wpdb;

        $table_links       = LHA_DB::table( 'links' );
        $table_occurrences = LHA_DB::table( 'occurrences' );

        // Get all public post types (excluding attachments).
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );

        if ( empty( $post_types ) ) {
            return array();
        }

        // Get all published posts.
        $posts_query = new WP_Query( array(
            'post_type'      => array_values( $post_types ),
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ) );

        if ( empty( $posts_query->posts ) ) {
            wp_reset_postdata();
            return array();
        }

        // Build a map of post_id => outbound count using a single query.
        // Outbound: count of distinct internal links in occurrences for each post.
        $outbound_results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.object_id, COUNT(DISTINCT l.id) as outbound_count
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s
                 GROUP BY o.object_id",
                'internal'
            ),
            ARRAY_A
        );

        $outbound_map = array();
        if ( $outbound_results ) {
            foreach ( $outbound_results as $row ) {
                $outbound_map[ (int) $row['object_id'] ] = (int) $row['outbound_count'];
            }
        }

        // Build inbound counts.
        // Inbound for a post = number of distinct source posts that have an internal link
        // whose URL matches this post's permalink path.
        // We build a lookup of permalink paths to post IDs, then query occurrences.
        $permalink_map = array(); // path => post_id
        $post_data     = array(); // post_id => array of basic post info

        foreach ( $posts_query->posts as $post_id ) {
            $post      = get_post( $post_id );
            $permalink = get_permalink( $post_id );
            $path      = wp_parse_url( $permalink, PHP_URL_PATH );

            $post_data[ $post_id ] = array(
                'post_id'   => $post_id,
                'title'     => $post->post_title,
                'post_type' => $post->post_type,
                'permalink' => $permalink,
                'edit_url'  => get_edit_post_link( $post_id, 'raw' ),
                'path'      => $path ? rtrim( $path, '/' ) : '',
            );

            if ( $path ) {
                $clean_path = rtrim( $path, '/' );
                if ( ! isset( $permalink_map[ $clean_path ] ) ) {
                    $permalink_map[ $clean_path ] = $post_id;
                }
            }
        }

        wp_reset_postdata();

        // Query all internal link URLs to build inbound counts.
        $internal_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.url, o.object_id as source_post_id
                 FROM {$table_links} l
                 INNER JOIN {$table_occurrences} o ON l.id = o.link_id
                 WHERE l.link_type = %s",
                'internal'
            ),
            ARRAY_A
        );

        $inbound_map = array(); // target_post_id => count of distinct source posts

        if ( $internal_links ) {
            // Track unique source->target pairs to count distinct sources.
            $source_target_pairs = array(); // "source_id:target_id" => true

            foreach ( $internal_links as $link ) {
                $link_path = wp_parse_url( $link['url'], PHP_URL_PATH );
                if ( ! $link_path ) {
                    continue;
                }

                $clean_link_path = rtrim( $link_path, '/' );
                $source_post_id  = (int) $link['source_post_id'];

                // Check if this path matches any published post.
                if ( isset( $permalink_map[ $clean_link_path ] ) ) {
                    $target_post_id = $permalink_map[ $clean_link_path ];

                    // Don't count self-links as inbound.
                    if ( $source_post_id === $target_post_id ) {
                        continue;
                    }

                    $pair_key = $source_post_id . ':' . $target_post_id;
                    if ( ! isset( $source_target_pairs[ $pair_key ] ) ) {
                        $source_target_pairs[ $pair_key ] = true;

                        if ( ! isset( $inbound_map[ $target_post_id ] ) ) {
                            $inbound_map[ $target_post_id ] = 0;
                        }
                        $inbound_map[ $target_post_id ]++;
                    }
                }
            }
        }

        // Assemble final results.
        $results = array();

        foreach ( $post_data as $post_id => $data ) {
            $inbound  = $inbound_map[ $post_id ] ?? 0;
            $outbound = $outbound_map[ $post_id ] ?? 0;

            $results[] = array(
                'post_id'     => $post_id,
                'title'       => $data['title'],
                'post_type'   => $data['post_type'],
                'permalink'   => $data['permalink'],
                'edit_url'    => $data['edit_url'],
                'inbound'     => $inbound,
                'outbound'    => $outbound,
                'is_orphaned' => ( 0 === $inbound ),
            );
        }

        return $results;
    }

    /**
     * Detect orphaned pages — published posts/pages with zero inbound internal links.
     *
     * @return array Array of orphaned post items (post_id, title, post_type, permalink, edit_url).
     */
    public function detect_orphans(): array {
        $counts = $this->calculate_counts();

        return array_values( array_filter( $counts, function ( $item ) {
            return $item['is_orphaned'];
        } ) );
    }

    /**
     * Detect posts with low outbound internal links (below threshold).
     *
     * @param int $threshold Minimum outbound link count (default: class threshold).
     * @return array Array of post items with low outbound counts.
     */
    public function detect_low_outbound( int $threshold = 0 ): array {
        if ( $threshold <= 0 ) {
            $threshold = $this->low_outbound_threshold;
        }

        $counts = $this->calculate_counts();

        return array_values( array_filter( $counts, function ( $item ) use ( $threshold ) {
            return $item['outbound'] < $threshold;
        } ) );
    }

    /**
     * Find internal links pointing to non-existent, draft, private, or trashed posts.
     *
     * Checks each internal link URL to determine if it targets an unavailable resource.
     *
     * @return array Array of flagged links with link_id, url, reason, and source info.
     */
    public function find_broken_internal(): array {
        global $wpdb;

        $table_links       = LHA_DB::table( 'links' );
        $table_occurrences = LHA_DB::table( 'occurrences' );

        // Get all internal links that are not ignored.
        $internal_links = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.url FROM {$table_links} l WHERE l.link_type = %s AND l.is_ignored = 0",
                'internal'
            ),
            ARRAY_A
        );

        if ( empty( $internal_links ) ) {
            return array();
        }

        $broken = array();

        foreach ( $internal_links as $link ) {
            $post_id = url_to_postid( $link['url'] );

            if ( 0 === $post_id ) {
                // Cannot determine post ID — might be an archive, taxonomy page, etc.
                // Only flag if it looks like a post/page URL (has a path beyond root).
                $path = wp_parse_url( $link['url'], PHP_URL_PATH );
                if ( $path && '/' !== $path && strlen( $path ) > 1 ) {
                    // Try to determine if this could be a post URL that no longer exists.
                    // WordPress returns 0 for URLs that don't resolve to any post.
                    // We flag these as potentially targeting a deleted/non-existent post.
                    $broken[] = array(
                        'link_id' => (int) $link['id'],
                        'url'     => $link['url'],
                        'reason'  => 'not_found',
                    );
                }
                continue;
            }

            $post = get_post( $post_id );

            if ( ! $post ) {
                $broken[] = array(
                    'link_id' => (int) $link['id'],
                    'url'     => $link['url'],
                    'reason'  => 'deleted',
                );
                continue;
            }

            // Check post status for non-public states.
            switch ( $post->post_status ) {
                case 'draft':
                    $broken[] = array(
                        'link_id' => (int) $link['id'],
                        'url'     => $link['url'],
                        'reason'  => 'draft',
                    );
                    break;

                case 'private':
                    $broken[] = array(
                        'link_id' => (int) $link['id'],
                        'url'     => $link['url'],
                        'reason'  => 'private',
                    );
                    break;

                case 'trash':
                    $broken[] = array(
                        'link_id' => (int) $link['id'],
                        'url'     => $link['url'],
                        'reason'  => 'trashed',
                    );
                    break;
            }
        }

        return $broken;
    }

    /**
     * Find internal links using HTTP instead of HTTPS.
     *
     * Only relevant when the site itself uses HTTPS.
     *
     * @return array Array of link records (id, url) using HTTP on an HTTPS site.
     */
    public function find_http_links(): array {
        global $wpdb;

        $table_links = LHA_DB::table( 'links' );
        $site_url    = home_url();

        // Only relevant for HTTPS sites.
        if ( ! str_starts_with( $site_url, 'https://' ) ) {
            return array();
        }

        $site_host = wp_parse_url( $site_url, PHP_URL_HOST );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, url FROM {$table_links} WHERE link_type = %s AND url LIKE %s AND is_ignored = 0",
                'internal',
                'http://' . $wpdb->esc_like( $site_host ) . '%'
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Set the threshold for low outbound link detection.
     *
     * @param int $threshold Minimum number of outbound internal links.
     */
    public function set_low_outbound_threshold( int $threshold ): void {
        $this->low_outbound_threshold = max( 1, $threshold );
    }

    /**
     * Get the current low outbound threshold.
     *
     * @return int The threshold value.
     */
    public function get_low_outbound_threshold(): int {
        return $this->low_outbound_threshold;
    }
}
