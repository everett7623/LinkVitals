<?php
/**
 * Anchor Checker class
 *
 * Validates that anchor links (#fragment) point to existing id="" or name=""
 * attributes in the target page content. Supports both internal pages
 * (direct DB lookup) and external pages (HTTP fetch).
 *
 * @package LinkVitals
 * @since   1.0.0
 * @requires PHP 8.0
 *
 * Implements Requirements 15.1, 15.2, 15.3, 15.4, 15.5.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Anchor_Checker {

    /**
     * Check if an anchor fragment exists in the target page.
     *
     * Parses the URL to extract the fragment, retrieves the target page content
     * (from DB for internal links or via wp_remote_get for external), and searches
     * for an element with id or name attribute matching the fragment (case-sensitive).
     *
     * @param string $url Full URL with #fragment.
     * @return array Check result with keys: status, error_type, http_code.
     */
    public function check_anchor( string $url ): array {
        // Parse URL to extract the fragment.
        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['fragment'] ) ) {
            // No fragment in URL — nothing to check, consider it OK.
            return array(
                'status'     => 'ok',
                'error_type' => '',
                'http_code'  => 200,
            );
        }

        $fragment = $parsed['fragment'];

        // Build the URL without the fragment to fetch the page.
        $base_url = $url;
        $hash_pos = strpos( $base_url, '#' );
        if ( false !== $hash_pos ) {
            $base_url = substr( $base_url, 0, $hash_pos );
        }

        // For internal links, try to get post content directly (faster than HTTP).
        $post_id = url_to_postid( $base_url );

        if ( $post_id > 0 ) {
            $html  = $this->get_post_full_content( $post_id );
            $found = $this->is_anchor_present( $html, $fragment );

            if ( $found ) {
                return array(
                    'status'     => 'ok',
                    'error_type' => '',
                    'http_code'  => 200,
                );
            }

            return array(
                'status'     => 'broken',
                'error_type' => 'broken_anchor',
                'http_code'  => 200,
            );
        }

        // External or unresolvable URL — fetch via HTTP.
        $response = wp_remote_get( $base_url, array(
            'timeout'    => 8,
            'user-agent' => 'LinkVitals/1.0',
            'sslverify'  => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'status'     => 'broken',
                'error_type' => 'broken_anchor',
                'http_code'  => 0,
            );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code < 200 || $code >= 400 ) {
            return array(
                'status'     => 'broken',
                'error_type' => 'broken_anchor',
                'http_code'  => $code,
            );
        }

        $body  = wp_remote_retrieve_body( $response );
        $found = $this->is_anchor_present( $body, $fragment );

        if ( $found ) {
            return array(
                'status'     => 'ok',
                'error_type' => '',
                'http_code'  => $code,
            );
        }

        return array(
            'status'     => 'broken',
            'error_type' => 'broken_anchor',
            'http_code'  => $code,
        );
    }

    /**
     * Check if a fragment identifier exists in HTML content.
     *
     * Searches for an element with id attribute or name attribute matching
     * the fragment (case-sensitive match) using DOMDocument and XPath.
     *
     * @param string $html     HTML content to search.
     * @param string $fragment Anchor fragment identifier (without #).
     * @return bool True if an element with matching id or name exists.
     */
    public function is_anchor_present( string $html, string $fragment ): bool {
        if ( empty( $html ) || empty( $fragment ) ) {
            return false;
        }

        // Suppress DOM parsing errors for malformed HTML.
        $previous_errors = libxml_use_internal_errors( true );

        $dom = new DOMDocument();
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
        );

        libxml_clear_errors();
        libxml_use_internal_errors( $previous_errors );

        $xpath = new DOMXPath( $dom );

        // Search for id="fragment" (case-sensitive).
        $by_id = $xpath->query(
            sprintf( '//*[@id="%s"]', $this->escape_xpath_value( $fragment ) )
        );
        if ( $by_id && $by_id->length > 0 ) {
            return true;
        }

        // Search for name="fragment" (case-sensitive, legacy HTML anchors).
        $by_name = $xpath->query(
            sprintf( '//*[@name="%s"]', $this->escape_xpath_value( $fragment ) )
        );
        if ( $by_name && $by_name->length > 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Batch check multiple anchor links.
     *
     * Processes an array of link records. When anchor checking is disabled
     * in settings, marks all links as 'skipped' with error_type 'anchors_disabled'.
     *
     * @param array $links    Array of link records with 'id' and 'url' keys.
     * @param array $settings Plugin settings array.
     * @return array Results keyed by link ID.
     */
    public function check_batch( array $links, array $settings = array() ): array {
        $results = array();

        // When anchor checking is disabled, mark all as skipped (Req 15.5).
        if ( empty( $settings['check_anchors'] ) ) {
            foreach ( $links as $link ) {
                $link_id = (int) $link['id'];
                $results[ $link_id ] = array(
                    'status'     => 'skipped',
                    'error_type' => 'anchors_disabled',
                    'http_code'  => 0,
                );

                LHA_DB::update_link_result( $link_id, array(
                    'status'     => 'skipped',
                    'error_type' => 'anchors_disabled',
                    'http_code'  => 0,
                ) );
            }
            return $results;
        }

        // Anchor checking is enabled — verify each link.
        foreach ( $links as $link ) {
            $link_id = (int) $link['id'];
            $result  = $this->check_anchor( $link['url'] );

            $results[ $link_id ] = $result;

            LHA_DB::update_link_result( $link_id, $result );
        }

        return $results;
    }

    /**
     * Get the full rendered content of a post (including blocks and shortcodes).
     *
     * Applies the_content filters to render Gutenberg blocks and shortcodes
     * so that dynamically-generated IDs are included in the search.
     *
     * @param int $post_id WordPress post ID.
     * @return string Rendered HTML content.
     */
    private function get_post_full_content( int $post_id ): string {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return '';
        }

        // Apply content filters to render blocks, shortcodes, etc.
        $content = apply_filters( 'the_content', $post->post_content );
        return $content;
    }

    /**
     * Escape a string for safe use in an XPath expression attribute value.
     *
     * Handles the case where the fragment contains double quotes by using
     * the XPath concat() function to build the string safely.
     *
     * @param string $value The value to escape.
     * @return string Escaped value safe for XPath attribute selector.
     */
    private function escape_xpath_value( string $value ): string {
        if ( ! str_contains( $value, '"' ) ) {
            return $value;
        }

        return str_replace( '"', '&quot;', $value );
    }
}
