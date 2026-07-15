<?php
/**
 * SEO Checker class
 *
 * Analyzes external links for SEO best practices:
 * - Missing rel="nofollow"
 * - Missing rel="sponsored" (optional)
 * - target="_blank" without rel="noopener noreferrer"
 * - HTTP links (non-HTTPS)
 *
 * NOTE: This does NOT enforce nofollow on all external links.
 * It provides suggestions and filtering options for site owners.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_SEO_Checker {

    /**
     * Analyze a single link's raw HTML for SEO attributes.
     *
     * This is a static utility method that parses the raw HTML of an <a> tag
     * and checks for SEO-related attributes.
     *
     * @param string $raw_html The raw HTML of the <a> tag.
     * @param string $url      The URL of the link (used for HTTP vs HTTPS check).
     * @return array Analysis results with attribute flags and issues list.
     */
    public static function analyze_link( string $raw_html, string $url = '' ): array {
        $result = array(
            'has_nofollow'     => false,
            'has_sponsored'    => false,
            'has_ugc'          => false,
            'has_noopener'     => false,
            'has_noreferrer'   => false,
            'has_target_blank' => false,
            'is_http'          => false,
            'issues'           => array(),
        );

        if ( empty( $raw_html ) ) {
            return $result;
        }

        // Parse the anchor tag via DOMDocument.
        $prev = libxml_use_internal_errors( true );
        $dom  = new DOMDocument();
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $raw_html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors( $prev );

        $anchors = $dom->getElementsByTagName( 'a' );
        if ( $anchors->length === 0 ) {
            return $result;
        }

        $anchor = $anchors->item( 0 );
        $href   = $anchor->getAttribute( 'href' );
        $rel    = strtolower( $anchor->getAttribute( 'rel' ) );
        $target = $anchor->getAttribute( 'target' );

        // Parse rel attribute values.
        $rel_values = array_filter( array_map( 'trim', explode( ' ', $rel ) ) );

        $result['has_nofollow']     = in_array( 'nofollow', $rel_values, true );
        $result['has_sponsored']    = in_array( 'sponsored', $rel_values, true );
        $result['has_ugc']          = in_array( 'ugc', $rel_values, true );
        $result['has_noopener']     = in_array( 'noopener', $rel_values, true );
        $result['has_noreferrer']   = in_array( 'noreferrer', $rel_values, true );
        $result['has_target_blank'] = ( $target === '_blank' );

        // Use the provided $url for HTTPS check, falling back to href from the tag.
        $check_url       = ! empty( $url ) ? $url : $href;
        $result['is_http'] = str_starts_with( strtolower( $check_url ), 'http://' );

        // Identify issues (suggestions, not enforcements).
        if ( ! $result['has_nofollow'] ) {
            $result['issues'][] = 'missing_nofollow';
        }

        if ( $result['has_target_blank'] && ( ! $result['has_noopener'] || ! $result['has_noreferrer'] ) ) {
            $result['issues'][] = 'missing_noopener_noreferrer';
        }

        if ( $result['is_http'] ) {
            $result['issues'][] = 'http_not_https';
        }

        return $result;
    }

    /**
     * Analyze a link occurrence record (wrapper around analyze_link).
     *
     * @param array $occurrence Occurrence record with raw_html.
     * @return array SEO analysis results.
     */
    public function analyze_occurrence( array $occurrence ): array {
        $raw_html = $occurrence['raw_html'] ?? '';
        $url      = $occurrence['url'] ?? '';

        return self::analyze_link( $raw_html, $url );
    }

    /**
     * Get count of external links with specific SEO issues.
     *
     * Returns:
     *   'total_external'              => int
     *   'missing_nofollow'            => int
     *   'missing_noopener_noreferrer' => int
     *   'http_not_https'              => int
     *
     * @return array Issue counts.
     */
    public function get_issue_counts(): array {
        global $wpdb;

        $table_links       = LHA_DB::table( 'links' );
        $table_occurrences = LHA_DB::table( 'occurrences' );

        // Total external link occurrences (anchor tags only).
        $total_external = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0",
                'external',
                'a'
            )
        );

        // Missing nofollow: occurrences whose raw_html does NOT contain 'nofollow'.
        $missing_nofollow = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0
                   AND o.raw_html NOT LIKE %s",
                'external',
                'a',
                '%nofollow%'
            )
        );

        // Missing noopener/noreferrer: occurrences with target="_blank" but missing noopener.
        $missing_noopener = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0
                   AND o.raw_html LIKE %s
                   AND ( o.raw_html NOT LIKE %s OR o.raw_html NOT LIKE %s )",
                'external',
                'a',
                '%target="_blank"%',
                '%noopener%',
                '%noreferrer%'
            )
        );

        // HTTP (not HTTPS): external links whose URL starts with 'http://'.
        $http_not_https = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0
                   AND l.url LIKE %s",
                'external',
                'a',
                'http://%'
            )
        );

        return array(
            'total_external'              => $total_external,
            'missing_nofollow'            => $missing_nofollow,
            'missing_noopener_noreferrer' => $missing_noopener,
            'http_not_https'              => $http_not_https,
        );
    }

    /**
     * Get paginated SEO report of external link issues.
     *
     * @param int    $per_page     Items per page.
     * @param int    $offset       Offset for pagination.
     * @param string $issue_filter Filter by specific issue type:
     *                             'missing_nofollow', 'missing_noopener_noreferrer', 'http_not_https'.
     * @return array {
     *     @type array $items Array of report items.
     *     @type int   $total Total matching records.
     * }
     */
    public function get_report( int $per_page = 20, int $offset = 0, string $issue_filter = '' ): array {
        global $wpdb;

        $table_links       = LHA_DB::table( 'links' );
        $table_occurrences = LHA_DB::table( 'occurrences' );

        // Build WHERE clause based on issue filter.
        $where_extra = '';
        switch ( $issue_filter ) {
            case 'missing_nofollow':
                $where_extra = $wpdb->prepare(
                    ' AND o.raw_html NOT LIKE %s',
                    '%nofollow%'
                );
                break;

            case 'missing_noopener_noreferrer':
                $where_extra = $wpdb->prepare(
                    ' AND o.raw_html LIKE %s AND ( o.raw_html NOT LIKE %s OR o.raw_html NOT LIKE %s )',
                    '%target="_blank"%',
                    '%noopener%',
                    '%noreferrer%'
                );
                break;

            case 'http_not_https':
                $where_extra = $wpdb->prepare(
                    ' AND l.url LIKE %s',
                    'http://%'
                );
                break;
        }

        // Get total matching count.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0{$where_extra}",
                'external',
                'a'
            )
        );

        // Get paginated results.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT o.id AS occurrence_id, o.source_title, o.edit_url, o.anchor_text, o.raw_html,
                        l.url, l.domain
                 FROM {$table_occurrences} o
                 INNER JOIN {$table_links} l ON o.link_id = l.id
                 WHERE l.link_type = %s AND o.html_tag = %s AND l.is_ignored = 0{$where_extra}
                 ORDER BY o.created_at DESC
                 LIMIT %d OFFSET %d",
                'external',
                'a',
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        // Analyze each result to determine attribute presence.
        $items = array();
        foreach ( $results as $row ) {
            $analysis = self::analyze_link( $row['raw_html'], $row['url'] );

            $items[] = array(
                'url'          => $row['url'],
                'source_title' => $row['source_title'],
                'edit_url'     => $row['edit_url'],
                'anchor_text'  => $row['anchor_text'],
                'has_nofollow' => $analysis['has_nofollow'],
                'has_noopener' => $analysis['has_noopener'],
                'is_http'      => $analysis['is_http'],
                'issues'       => $analysis['issues'],
            );
        }

        return array(
            'items' => $items,
            'total' => $total,
        );
    }
}
