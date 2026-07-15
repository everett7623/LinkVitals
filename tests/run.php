<?php
/**
 * Dependency-free contract tests for LinkVitals.
 *
 * @package LinkVitals
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( mixed $key ): string {
        $key = strtolower( (string) $key );
        return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): array|string|int|null|false {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'home_url' ) ) {
    function home_url(): string {
        return 'https://www.example.com';
    }
}

if ( ! function_exists( 'mb_substr' ) ) {
    function mb_substr( string $string, int $offset, ?int $length = null, ?string $encoding = null ): string {
        unset( $encoding );
        return null === $length ? substr( $string, $offset ) : substr( $string, $offset, $length );
    }
}

require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-link-extractor.php';

$tests = array();

function lha_test( string $name, callable $test ): void {
    global $tests;
    $tests[ $name ] = $test;
}

function lha_assert_same( mixed $expected, mixed $actual ): void {
    if ( $expected !== $actual ) {
        throw new RuntimeException(
            sprintf(
                "Expected %s, got %s",
                var_export( $expected, true ),
                var_export( $actual, true )
            )
        );
    }
}

lha_test(
    'normalizes documented URL variants',
    static function(): void {
        $cases = array(
            ' HTTPS://WWW.Example.COM:443/Path/?A=1#frag ' => 'https://example.com/path/?a=1',
            'http://www.example.com:80/'                  => 'http://example.com/',
            'https://example.com/path///'                 => 'https://example.com/path',
        );

        foreach ( $cases as $input => $expected ) {
            lha_assert_same( $expected, LHA_DB::normalize_url( $input ) );
        }
    }
);

lha_test(
    'keeps URL normalization idempotent',
    static function(): void {
        $input      = ' HTTPS://WWW.Example.COM:443/Path/#section ';
        $normalized = LHA_DB::normalize_url( $input );
        lha_assert_same( $normalized, LHA_DB::normalize_url( $normalized ) );
    }
);

lha_test(
    'defines actionable issue statuses explicitly',
    static function(): void {
        lha_assert_same(
            array( 'broken', 'server_error', 'timeout', 'ssl_error', 'dns_error', 'forbidden' ),
            LHA_DB::get_issue_statuses()
        );
    }
);

lha_test(
    'does not double-count HTTP diagnostic buckets',
    static function(): void {
        $stats = array(
            'broken'      => 3,
            'server_error' => 2,
            'timeout'     => 1,
            'ssl_error'   => 1,
            'dns_error'   => 1,
            'forbidden'   => 1,
            'code_404'    => 3,
            'code_5xx'    => 2,
        );

        lha_assert_same( 9, LHA_DB::get_issue_total_from_stats( $stats ) );
        lha_assert_same( 0, LHA_DB::get_issue_total_from_stats( array() ) );
    }
);

lha_test(
    'clamps report filters to supported keys',
    static function(): void {
        lha_assert_same( 'server_error', LHA_DB::sanitize_report_filter_key( 'Server_Error!' ) );
        lha_assert_same( 'broken', LHA_DB::sanitize_report_filter_key( array( 'broken', 'ignored' ) ) );
        lha_assert_same( '', LHA_DB::sanitize_report_filter_key( 'not-supported' ) );
    }
);

lha_test(
    'resolves supported URL forms against the source page',
    static function(): void {
        $extractor = new LHA_Link_Extractor();
        $base_url  = 'https://example.com:8443/articles/current.html#old';
        $cases     = array(
            'https://other.example/path' => 'https://other.example/path',
            'mailto:test@example.com'    => 'mailto:test@example.com',
            '#section'                   => 'https://example.com:8443/articles/current.html#section',
            '//cdn.example.com/file.js'  => 'https://cdn.example.com/file.js',
            '/downloads/file.pdf'        => 'https://example.com:8443/downloads/file.pdf',
            'next.html'                  => 'https://example.com:8443/articles/next.html',
        );

        foreach ( $cases as $input => $expected ) {
            lha_assert_same( $expected, $extractor->resolve_url( $input, $base_url ) );
        }
    }
);

lha_test(
    'classifies links by documented priority',
    static function(): void {
        $extractor = new LHA_Link_Extractor();
        $cases     = array(
            array( '', 'a', 'href', 'empty' ),
            array( 'mailto:test@example.com', 'a', 'href', 'mailto' ),
            array( 'tel:+123456', 'a', 'href', 'tel' ),
            array( 'javascript:void(0)', 'a', 'href', 'javascript' ),
            array( 'relative/path', 'a', 'href', 'malformed' ),
            array( 'https://example.com/page#part', 'a', 'href', 'anchor' ),
            array( 'https://cdn.example.net/photo.webp?size=2', 'a', 'href', 'image' ),
            array( 'https://example.net/report.pdf', 'a', 'href', 'download' ),
            array( 'https://example.net/movie.mp4', 'a', 'href', 'media' ),
            array( 'https://example.com/page', 'a', 'href', 'internal' ),
            array( 'https://other.example/page', 'a', 'href', 'external' ),
        );

        foreach ( $cases as $case ) {
            list( $url, $tag, $attribute, $expected ) = $case;
            lha_assert_same( $expected, $extractor->classify_link( $url, $tag, $attribute ) );
        }
    }
);

lha_test(
    'keeps duplicate link occurrences as separate extraction results',
    static function(): void {
        $extractor = new LHA_Link_Extractor();
        $links     = $extractor->extract(
            '<a href="/same">First</a><a href="/same">Second</a>',
            'https://example.com/post'
        );

        lha_assert_same( 2, count( $links ) );
        lha_assert_same( 'First', $links[0]['anchor_text'] );
        lha_assert_same( 'Second', $links[1]['anchor_text'] );
        lha_assert_same( 'https://example.com/same', $links[0]['url'] );
        lha_assert_same( 'https://example.com/same', $links[1]['url'] );
    }
);

lha_test(
    'extracts every srcset candidate with source metadata',
    static function(): void {
        $extractor = new LHA_Link_Extractor();
        $links     = $extractor->extract(
            '<img src="hero.jpg" srcset="hero-small.jpg 320w, /hero-large.jpg 1280w" alt="Hero">',
            'https://example.com/posts/item'
        );

        lha_assert_same( 3, count( $links ) );
        lha_assert_same( array( 'src', 'srcset', 'srcset' ), array_column( $links, 'attribute_name' ) );
        lha_assert_same(
            array(
                'https://example.com/posts/hero.jpg',
                'https://example.com/posts/hero-small.jpg',
                'https://example.com/hero-large.jpg',
            ),
            array_column( $links, 'url' )
        );
        lha_assert_same( array( 'image', 'image', 'image' ), array_column( $links, 'link_type' ) );
    }
);

$failures = 0;
foreach ( $tests as $name => $test ) {
    try {
        $test();
        echo "[PASS] {$name}\n";
    } catch ( Throwable $error ) {
        $failures++;
        echo "[FAIL] {$name}: {$error->getMessage()}\n";
    }
}

echo sprintf( "\n%d test(s), %d failure(s).\n", count( $tests ), $failures );
exit( 0 === $failures ? 0 : 1 );
