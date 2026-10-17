<?php
/**
 * Link Checker class
 *
 * Checks HTTP status of URLs using WordPress HTTP API.
 * - Prioritizes HEAD requests, falls back to GET on 405/403
 * - Handles redirects, timeouts, SSL errors
 * - Respects rate limiting per domain
 *
 * @package LinkVitals
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Link_Checker {

    /**
     * Track last request time per domain for rate limiting.
     *
     * @var array<string, float>
     */
    private static array $domain_last_request = array();

    /**
     * Stored proxy filter closure for cleanup.
     *
     * @var callable|null
     */
    private $proxy_filter = null;

    /**
     * Minimum delay between requests to same external domain (milliseconds).
     */
    private const DOMAIN_RATE_LIMIT_MS = 1000;

    /**
     * Default HTTP timeout in seconds.
     */
    private const DEFAULT_TIMEOUT = 8;

    /**
     * Default maximum redirects to follow.
     */
    private const DEFAULT_MAX_REDIRECTS = 5;

    /**
     * Check a single URL and return its status result.
     *
     * @param string $url      URL to check.
     * @param array  $settings Plugin settings array.
     * @return array{http_code: int, status: string, error_type: string, final_url: string, redirect_count: int, response_time: float, content_type: string}
     */
    public function check( string $url, array $settings = array() ): array {
        $timeout       = isset( $settings['http_timeout'] ) ? absint( $settings['http_timeout'] ) : self::DEFAULT_TIMEOUT;
        $max_redirects = isset( $settings['max_redirects'] ) ? absint( $settings['max_redirects'] ) : self::DEFAULT_MAX_REDIRECTS;

        $result = array(
            'http_code'      => 0,
            'status'         => 'unknown',
            'error_type'     => '',
            'final_url'      => '',
            'redirect_count' => 0,
            'response_time'  => 0.0,
            'content_type'   => '',
        );

        // Skip non-HTTP URLs: mailto, tel, javascript, empty, malformed.
        $skip_check = $this->should_skip_url( $url );
        if ( '' !== $skip_check ) {
            $result['status']     = 'skipped';
            $result['error_type'] = $skip_check;
            return $result;
        }

        // Set up proxy if enabled.
        if ( ! empty( $settings['proxy_enabled'] ) && ! empty( $settings['proxy_host'] ) && ! empty( $settings['proxy_port'] ) ) {
            $this->setup_proxy( $settings );
        }

        // Apply rate limiting for external domains.
        $this->rate_limit( $url );

        $start_time = microtime( true );

        // Try HEAD request first.
        $response = $this->do_request( 'HEAD', $url, $timeout, $max_redirects );

        // If HEAD fails with 405 Method Not Allowed or 403 Forbidden, fall back to GET.
        if ( is_wp_error( $response ) || in_array( wp_remote_retrieve_response_code( $response ), array( 405, 403 ), true ) ) {
            $response = $this->do_request( 'GET', $url, $timeout, $max_redirects );
        }

        $result['response_time'] = round( ( microtime( true ) - $start_time ) * 1000, 2 );

        // Tear down proxy filter after requests complete.
        $this->teardown_proxy();

        // Handle WP_Error (connection failures, SSL errors, timeouts, DNS errors).
        if ( is_wp_error( $response ) ) {
            return $this->handle_error( $result, $response );
        }

        // Parse successful response.
        $result['http_code']    = (int) wp_remote_retrieve_response_code( $response );
        $result['content_type'] = wp_remote_retrieve_header( $response, 'content-type' );

        // Detect redirects via WordPress HTTP API response object.
        $this->detect_redirects( $result, $response, $url );

        // Classify status based on HTTP code and redirect info.
        $result['status'] = $this->classify_status( $result['http_code'], $result );

        // Set error_type based on classification.
        if ( 'broken' === $result['status'] && 404 === $result['http_code'] ) {
            $result['error_type'] = '404';
        } elseif ( 'server_error' === $result['status'] ) {
            $result['error_type'] = '5xx';
        } elseif ( 'forbidden' === $result['status'] && $result['http_code'] >= 520 && $result['http_code'] <= 527 ) {
            $result['error_type'] = 'cloudflare_blocked';
        } elseif ( 'forbidden' === $result['status'] ) {
            $result['error_type'] = '403';
        }

        return $result;
    }

    /**
     * Check multiple URLs in batch with rate limiting and deduplication.
     *
     * @param array $urls     Array of URLs to check.
     * @param array $settings Plugin settings.
     * @return array<string, array> Results keyed by URL.
     */
    public function check_batch( array $urls, array $settings = array() ): array {
        $results = array();
        $checked = array();

        foreach ( $urls as $url ) {
            // Deduplicate: same URL only checked once.
            $url_key = md5( $url );
            if ( isset( $checked[ $url_key ] ) ) {
                $results[ $url ] = $checked[ $url_key ];
                continue;
            }

            $result            = $this->check( $url, $settings );
            $results[ $url ]   = $result;
            $checked[ $url_key ] = $result;
        }

        return $results;
    }

    /**
     * Determine if a URL should be skipped (non-HTTP link types).
     *
     * @param string $url URL to evaluate.
     * @return string Error type if skipped, empty string if URL should be checked.
     */
    private function should_skip_url( string $url ): string {
        $url_trimmed = trim( $url );

        // Empty URL.
        if ( '' === $url_trimmed ) {
            return 'empty';
        }

        // Mailto links.
        if ( str_starts_with( strtolower( $url_trimmed ), 'mailto:' ) ) {
            return 'mailto';
        }

        // Tel links.
        if ( str_starts_with( strtolower( $url_trimmed ), 'tel:' ) ) {
            return 'tel';
        }

        // JavaScript links.
        if ( str_starts_with( strtolower( $url_trimmed ), 'javascript:' ) ) {
            return 'javascript';
        }

        // Must be HTTP or HTTPS to proceed.
        if ( ! preg_match( '/^https?:\/\//i', $url_trimmed ) ) {
            return 'malformed';
        }

        // Validate URL structure.
        $parsed = wp_parse_url( $url_trimmed );
        if ( false === $parsed || empty( $parsed['host'] ) ) {
            return 'malformed';
        }

        return '';
    }

    /**
     * Make an HTTP request using WordPress HTTP API.
     *
     * @param string $method        HTTP method (HEAD or GET).
     * @param string $url           URL to request.
     * @param int    $timeout       Request timeout in seconds.
     * @param int    $max_redirects Maximum redirects to follow.
     * @return \WP_Error|array WordPress HTTP API response or WP_Error.
     */
    private function do_request( string $method, string $url, int $timeout, int $max_redirects ): \WP_Error|array {
        $args = array(
            'timeout'     => $timeout,
            'redirection' => $max_redirects,
            'user-agent'  => sprintf(
                'Mozilla/5.0 (compatible; LinkVitals/%s; +https://github.com/everett7623/LinkVitals)',
                LHA_VERSION
            ),
            'sslverify'   => true,
            'headers'     => array(
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ),
        );

        if ( 'HEAD' === $method ) {
            return wp_remote_head( $url, $args );
        }

        // For GET, limit response body size — we only need headers.
        $args['limit_response_size'] = 1024;
        return wp_remote_get( $url, $args );
    }

    /**
     * Detect redirects from WordPress HTTP API response.
     *
     * WordPress follows redirects transparently and returns the final response.
     * We detect this by examining the response object's URL history.
     *
     * @param array  $result   Result array (modified by reference).
     * @param array  $response WordPress HTTP API response.
     * @param string $original_url The originally requested URL.
     */
    private function detect_redirects( array &$result, array $response, string $original_url ): void {
        // Method 1: Use WP_HTTP_Requests_Response to get the effective URL.
        if ( isset( $response['http_response'] ) && $response['http_response'] instanceof \WP_HTTP_Requests_Response ) {
            $http_response = $response['http_response'];
            $effective_url = $http_response->get_response_object()->url ?? '';
            $effective_url = $this->normalize_redirect_target( $effective_url, $original_url );

            if ( ! empty( $effective_url ) && $this->urls_differ( $original_url, $effective_url ) ) {
                $result['final_url'] = $effective_url;
            }

            // Get redirect count from the response history.
            $response_data = $http_response->get_response_object();
            if ( isset( $response_data->history ) && is_array( $response_data->history ) ) {
                $result['redirect_count'] = count( $response_data->history );
            }
        }

        // Method 2: Fallback — check Location header (for 3xx responses not followed).
        if ( empty( $result['final_url'] ) ) {
            $location = wp_remote_retrieve_header( $response, 'location' );
            $location = $this->normalize_redirect_target( (string) $location, $original_url );
            if ( ! empty( $location ) ) {
                $result['final_url'] = $location;
                if ( 0 === $result['redirect_count'] ) {
                    $result['redirect_count'] = 1;
                }
            }
        }

        // Only mark as redirect if final_url is different from original URL
        // (after normalization to handle fragments and trailing slashes)
        if ( ! empty( $result['final_url'] ) && ! $this->urls_differ( $original_url, $result['final_url'] ) ) {
            // URLs are essentially the same (only differ by fragment/trailing slash)
            // Clear final_url and redirect_count to avoid false redirect detection
            $result['final_url'] = '';
            $result['redirect_count'] = 0;
        }
    }

    /**
     * Normalize redirect target to absolute URL so comparisons are reliable.
     */
    private function normalize_redirect_target( string $target_url, string $original_url ): string {
        $target_url = trim( $target_url );
        if ( '' === $target_url ) {
            return '';
        }

        if ( preg_match( '/^https?:\/\//i', $target_url ) ) {
            return $target_url;
        }

        $original_parts = wp_parse_url( $original_url );
        if ( ! is_array( $original_parts ) || empty( $original_parts['host'] ) ) {
            return $target_url;
        }

        $scheme = $original_parts['scheme'] ?? 'https';
        $host   = $original_parts['host'];
        $port   = isset( $original_parts['port'] ) ? ':' . (int) $original_parts['port'] : '';

        if ( str_starts_with( $target_url, '//' ) ) {
            return $scheme . ':' . $target_url;
        }

        if ( str_starts_with( $target_url, '/' ) ) {
            return $scheme . '://' . $host . $port . $target_url;
        }

        $base_path = $original_parts['path'] ?? '/';
        $base_dir  = preg_replace( '#/[^/]*$#', '/', $base_path );
        if ( '' === $base_dir ) {
            $base_dir = '/';
        }

        return $scheme . '://' . $host . $port . $base_dir . ltrim( $target_url, '/' );
    }

    /**
     * Compare two URLs to determine if they differ (accounting for trailing slashes and query params).
     *
     * @param string $url1 First URL.
     * @param string $url2 Second URL.
     * @return bool True if URLs are meaningfully different.
     */
    private function urls_differ( string $url1, string $url2 ): bool {
        // Normalize URLs for comparison: remove trailing slashes, compare without fragment
        $normalized1 = $this->normalize_url_for_comparison( $url1 );
        $normalized2 = $this->normalize_url_for_comparison( $url2 );

        return strcasecmp( $normalized1, $normalized2 ) !== 0;
    }

    /**
     * Normalize URL for comparison by removing trailing slashes and fragments.
     *
     * @param string $url URL to normalize.
     * @return string Normalized URL.
     */
    private function normalize_url_for_comparison( string $url ): string {
        // Remove fragment (#section)
        $url = preg_replace( '/#.*$/', '', $url );
        // Remove trailing slash
        $url = rtrim( $url, '/' );
        return $url;
    }

    /**
     * Handle WP_Error responses and classify the error type.
     *
     * @param array     $result Current result array.
     * @param \WP_Error $error  The WP_Error object.
     * @return array Updated result array with error classification.
     */
    private function handle_error( array $result, \WP_Error $error ): array {
        $error_message = strtolower( $error->get_error_message() );
        $error_code    = $error->get_error_code();

        // Timeout detection.
        if (
            str_contains( $error_message, 'timed out' ) ||
            str_contains( $error_message, 'timeout' ) ||
            str_contains( $error_message, 'curl error 28' )
        ) {
            $result['status']     = 'timeout';
            $result['error_type'] = 'timeout';
            return $result;
        }

        // SSL error detection.
        if (
            str_contains( $error_message, 'ssl' ) ||
            str_contains( $error_message, 'certificate' ) ||
            str_contains( $error_message, 'curl error 60' ) ||
            str_contains( $error_message, 'curl error 51' ) ||
            str_contains( $error_message, 'curl error 35' )
        ) {
            $result['status']     = 'ssl_error';
            $result['error_type'] = 'ssl_error';
            return $result;
        }

        // DNS error detection.
        if (
            str_contains( $error_message, 'resolve host' ) ||
            str_contains( $error_message, 'curl error 6' ) ||
            str_contains( $error_message, 'dns' ) ||
            str_contains( $error_message, 'name or service not known' )
        ) {
            $result['status']     = 'dns_error';
            $result['error_type'] = 'dns_error';
            return $result;
        }

        // Connection refused.
        if (
            str_contains( $error_message, 'connection refused' ) ||
            str_contains( $error_message, 'curl error 7' )
        ) {
            $result['status']     = 'broken';
            $result['error_type'] = 'connection_refused';
            return $result;
        }

        // Generic error — mark as broken.
        $result['status']     = 'broken';
        $result['error_type'] = ! empty( $error_code ) ? sanitize_key( substr( (string) $error_code, 0, 50 ) ) : 'unknown';

        return $result;
    }

    /**
     * Classify status based on HTTP response code.
     *
     * @param int   $code   HTTP status code.
     * @param array $result Current result array (for checking final_url).
     * @return string Status classification: ok, broken, redirect, forbidden, server_error, unknown.
     */
    private function classify_status( int $code, array $result ): string {
        // 2xx Success.
        if ( $code >= 200 && $code < 300 ) {
            // If WordPress followed a redirect and returned 200, classify as redirect.
            if ( ! empty( $result['final_url'] ) ) {
                return 'redirect';
            }
            return 'ok';
        }

        // 3xx Redirection (not followed by WP HTTP API — unusual with redirection > 0).
        if ( $code >= 300 && $code < 400 ) {
            return 'redirect';
        }

        // 403 Forbidden.
        if ( 403 === $code ) {
            return 'forbidden';
        }

        // 4xx Client Error (including 404).
        if ( $code >= 400 && $code < 500 ) {
            return 'broken';
        }

        // Cloudflare-specific 5xx codes (520-527) — likely WAF/bot protection, not a true server error.
        if ( $code >= 520 && $code <= 527 ) {
            return 'forbidden';
        }

        // 5xx Server Error.
        if ( $code >= 500 && $code < 600 ) {
            return 'server_error';
        }

        return 'unknown';
    }

    /**
     * Apply rate limiting for external domains.
     *
     * Ensures a minimum delay between consecutive requests to the same external domain
     * to prevent hammering external servers or triggering abuse protections.
     * Internal URLs (same site domain) are exempt from rate limiting.
     *
     * @param string $url URL about to be requested.
     */
    private function rate_limit( string $url ): void {
        $domain = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $domain ) ) {
            return;
        }

        // Skip rate limiting for internal domain.
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! empty( $site_host ) && strcasecmp( $domain, $site_host ) === 0 ) {
            return;
        }

        if ( isset( self::$domain_last_request[ $domain ] ) ) {
            $elapsed_ms = ( microtime( true ) - self::$domain_last_request[ $domain ] ) * 1000;
            if ( $elapsed_ms < self::DOMAIN_RATE_LIMIT_MS ) {
                $sleep_ms = (int) ceil( self::DOMAIN_RATE_LIMIT_MS - $elapsed_ms );
                usleep( $sleep_ms * 1000 );
            }
        }

        self::$domain_last_request[ $domain ] = microtime( true );
    }

    /**
     * Determine whether a URL is internal (belongs to the current site).
     *
     * @param string $url URL to evaluate.
     * @return bool True if the URL is on the same domain as the site.
     */
    public static function is_internal_url( string $url ): bool {
        $url_host  = wp_parse_url( $url, PHP_URL_HOST );
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        if ( empty( $url_host ) || empty( $site_host ) ) {
            return false;
        }

        // Compare without www prefix for consistency.
        $url_host  = preg_replace( '/^www\./i', '', strtolower( $url_host ) );
        $site_host = preg_replace( '/^www\./i', '', strtolower( $site_host ) );

        return $url_host === $site_host;
    }

    /**
     * Detect whether a redirect result represents an internal redirect chain.
     *
     * An internal redirect chain occurs when an internal link redirects to
     * another internal URL. These can be replaced with the final URL directly.
     *
     * @param string $original_url The original URL that was checked.
     * @param array  $result       The check result array.
     * @return bool True if this is an internal redirect chain.
     */
    public static function is_internal_redirect_chain( string $original_url, array $result ): bool {
        if ( 'redirect' !== ( $result['status'] ?? '' ) ) {
            return false;
        }

        if ( empty( $result['final_url'] ) ) {
            return false;
        }

        // Both the original URL and the final URL must be internal.
        return self::is_internal_url( $original_url ) && self::is_internal_url( $result['final_url'] );
    }

    /**
     * Set up HTTP proxy via WordPress http_api_curl action.
     *
     * Adds proxy configuration to WP HTTP API requests using the
     * http_api_curl hook for curl-based proxy configuration.
     *
     * @param array $settings Plugin settings array containing proxy_host, proxy_port, proxy_type.
     */
    private function setup_proxy( array $settings ): void {
        $host = $settings['proxy_host'] ?? '';
        $port = (int) ( $settings['proxy_port'] ?? 0 );
        $type = $settings['proxy_type'] ?? 'http';

        if ( empty( $host ) || empty( $port ) ) {
            return;
        }

        // Use http_api_curl hook for curl-based proxy configuration.
        $this->proxy_filter = function ( &$handle ) use ( $host, $port, $type ) {
            curl_setopt( $handle, CURLOPT_PROXY, $host );
            curl_setopt( $handle, CURLOPT_PROXYPORT, $port );
            if ( 'socks5' === strtolower( $type ) ) {
                curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5 );
            } else {
                curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
            }
        };

        add_action( 'http_api_curl', $this->proxy_filter, 10, 1 );
    }

    /**
     * Remove the proxy filter after requests complete.
     */
    private function teardown_proxy(): void {
        if ( $this->proxy_filter ) {
            remove_action( 'http_api_curl', $this->proxy_filter, 10 );
            $this->proxy_filter = null;
        }
    }

    /**
     * Reset the rate limit tracking (useful for testing).
     */
    public static function reset_rate_limits(): void {
        self::$domain_last_request = array();
    }
}
