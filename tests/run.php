<?php
/**
 * Dependency-free contract tests for LinkVitals.
 *
 * @package LinkVitals
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['lha_test_options'] = array();
$GLOBALS['lha_test_db_events'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( mixed $key ): string {
        $key = strtolower( (string) $key );
        return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
    }
}

if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        unset( $domain );
        return $text;
    }
}

if ( ! function_exists( 'absint' ) ) {
    function absint( mixed $value ): int {
        return abs( (int) $value );
    }
}

if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string {
        unset( $type );
        return '2026-07-15 12:00:00';
    }
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
    function wp_generate_uuid4(): string {
        static $counter = 0;
        $counter++;
        return sprintf( '00000000-0000-4000-8000-%012d', $counter );
    }
}

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $name, mixed $default = false ): mixed {
        return array_key_exists( $name, $GLOBALS['lha_test_options'] )
            ? $GLOBALS['lha_test_options'][ $name ]
            : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $name, mixed $value ): bool {
        $GLOBALS['lha_test_options'][ $name ] = $value;
        return true;
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
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-link-checker.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-image-repair.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-queue.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php';

class LHA_Test_WPDB {

    public string $prefix = 'wp_';

    /** @var array<int, array<string, mixed>> */
    public array $rows;

    /** @var array<int, string> */
    public array $operations = array();

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct( array $rows = array() ) {
        $this->rows = $rows;
    }

    public function prepare( string $query, mixed ...$args ): string {
        $index = 0;

        return preg_replace_callback(
            '/%[ds]/',
            static function( array $match ) use ( $args, &$index ): string {
                $value = $args[ $index++ ];
                if ( '%d' === $match[0] ) {
                    return (string) (int) $value;
                }

                return "'" . str_replace( "'", "''", (string) $value ) . "'";
            },
            $query
        ) ?? $query;
    }

    public function query( string $query ): int|false {
        if ( ! str_contains( $query, "SET status = 'processing', claim_token" ) ) {
            return false;
        }

        $this->operations[] = 'claim_update';
        preg_match( "/claim_token = '([^']+)'/", $query, $token_match );
        preg_match( "/updated_at = '([^']+)'/", $query, $time_match );
        preg_match( '/LIMIT ([0-9]+)/', $query, $limit_match );

        $pending = array_values(
            array_filter(
                $this->rows,
                static fn( array $row ): bool => 'pending' === $row['status']
            )
        );
        usort( $pending, array( $this, 'compare_queue_rows' ) );
        $pending = array_slice( $pending, 0, (int) ( $limit_match[1] ?? 0 ) );
        $claimed_ids = array_map( 'intval', array_column( $pending, 'id' ) );

        foreach ( $this->rows as &$row ) {
            if ( in_array( (int) $row['id'], $claimed_ids, true ) ) {
                $row['status']      = 'processing';
                $row['claim_token'] = $token_match[1] ?? '';
                $row['updated_at']  = $time_match[1] ?? '';
            }
        }
        unset( $row );

        return count( $claimed_ids );
    }

    public function get_results( string $query, mixed $output = null ): array {
        unset( $output );

        if ( str_contains( $query, 'wp_lha_links' ) ) {
            $GLOBALS['lha_test_db_events'][] = 'link_select';
            return array();
        }

        if ( str_contains( $query, 'claim_token' ) ) {
            $this->operations[] = 'claim_select';
            preg_match( "/claim_token = '([^']+)'/", $query, $token_match );
            $token = $token_match[1] ?? '';
            $rows  = array_values(
                array_filter(
                    $this->rows,
                    static fn( array $row ): bool => 'processing' === $row['status'] && $token === $row['claim_token']
                )
            );
            usort( $rows, array( $this, 'compare_queue_rows' ) );
            return $rows;
        }

        $this->operations[] = 'pending_select';
        return array();
    }

    private function compare_queue_rows( array $left, array $right ): int {
        return array( $left['priority'], $left['created_at'], $left['id'] )
            <=> array( $right['priority'], $right['created_at'], $right['id'] );
    }
}

class LHA_Test_Queue extends LHA_Queue {

    /** @var array<string, int> */
    private array $counts;

    /** @param array<string, int> $counts */
    public function __construct( array $counts ) {
        $this->counts = $counts;
    }

    public function reset_stuck( int $minutes = 10 ): int {
        unset( $minutes );
        return 0;
    }

    public function get_pending( int $batch_size = 20 ): array {
        unset( $batch_size );
        return array();
    }

    public function get_counts(): array {
        $GLOBALS['lha_test_db_events'][] = 'queue_counts';
        return $this->counts;
    }
}

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

lha_test(
    'claims queue batches before selecting their rows',
    static function(): void {
        $wpdb = new LHA_Test_WPDB(
            array(
                array( 'id' => 1, 'status' => 'pending', 'priority' => 5, 'created_at' => '2026-07-15 10:00:00', 'claim_token' => '' ),
                array( 'id' => 2, 'status' => 'pending', 'priority' => 1, 'created_at' => '2026-07-15 11:00:00', 'claim_token' => '' ),
                array( 'id' => 3, 'status' => 'pending', 'priority' => 1, 'created_at' => '2026-07-15 09:00:00', 'claim_token' => '' ),
            )
        );
        $GLOBALS['wpdb'] = $wpdb;

        $queue = new LHA_Queue();
        $first = $queue->get_pending( 2 );
        $second = $queue->get_pending( 2 );

        lha_assert_same( array( 3, 2 ), array_map( 'intval', array_column( $first, 'id' ) ) );
        lha_assert_same( array( 1 ), array_map( 'intval', array_column( $second, 'id' ) ) );
        lha_assert_same(
            array( 'claim_update', 'claim_select', 'claim_update', 'claim_select' ),
            $wpdb->operations
        );
    }
);

lha_test(
    'declares indexed queue claim tokens in the database schema',
    static function(): void {
        $schema = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php' );

        lha_assert_same( true, is_string( $schema ) && str_contains( $schema, "claim_token varchar(36) NOT NULL DEFAULT ''" ) );
        lha_assert_same( true, is_string( $schema ) && str_contains( $schema, 'KEY claim_token (claim_token)' ) );
    }
);

lha_test(
    'derives WordPress image-size repair candidates without guessing',
    static function(): void {
        lha_assert_same(
            array( 'https://example.com/uploads/photo.jpg?cache=1' ),
            LHA_Image_Repair::get_candidate_urls( 'https://example.com/uploads/photo-300x300.jpg?cache=1' )
        );
        lha_assert_same(
            array(
                'https://example.com/uploads/photo-scaled.webp',
                'https://example.com/uploads/photo.webp',
            ),
            LHA_Image_Repair::get_candidate_urls( 'https://example.com/uploads/photo-scaled-1024x683.webp' )
        );
        lha_assert_same( array(), LHA_Image_Repair::get_candidate_urls( 'https://example.com/uploads/photo.jpg' ) );
        lha_assert_same( array(), LHA_Image_Repair::get_candidate_urls( 'https://example.com/300x300/photo.jpg' ) );
    }
);

lha_test(
    'limits automatic image repair eligibility to internal 404 variants',
    static function(): void {
        $eligible = array(
            'url'        => 'https://example.com/uploads/photo-300x300.jpg',
            'link_type'  => 'image',
            'status'     => 'broken',
            'http_code'  => 404,
            'is_ignored' => 0,
        );

        lha_assert_same( true, LHA_Image_Repair::is_repairable_link( $eligible ) );

        $external = $eligible;
        $external['url'] = 'https://other.example/uploads/photo-300x300.jpg';
        lha_assert_same( false, LHA_Image_Repair::is_repairable_link( $external ) );

        $not_found = $eligible;
        $not_found['http_code'] = 500;
        lha_assert_same( false, LHA_Image_Repair::is_repairable_link( $not_found ) );

        $ignored = $eligible;
        $ignored['is_ignored'] = 1;
        lha_assert_same( false, LHA_Image_Repair::is_repairable_link( $ignored ) );
    }
);

lha_test(
    'keeps image repair failure responses on one stable contract',
    static function(): void {
        lha_assert_same(
            array(
                'success'  => false,
                'status'   => 'failed',
                'link_id'  => 0,
                'old_url'  => '',
                'new_url'  => '',
                'replaced' => 0,
                'resolved' => false,
                'message'  => 'Invalid link ID.',
            ),
            ( new LHA_Image_Repair() )->repair_link( 0 )
        );
    }
);

lha_test(
    'wires image repair through one AJAX action and bounded bulk client',
    static function(): void {
        $main   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/linkvitals.php' );
        $admin  = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $table  = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-list-table.php' );
        $client = file_get_contents( dirname( __DIR__ ) . '/linkvitals/assets/js/image-repair.js' );

        lha_assert_same( true, is_string( $main ) && str_contains( $main, 'class-lha-image-repair.php' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'wp_ajax_lha_repair_image_variant' ) );
        lha_assert_same( true, is_string( $table ) && str_contains( $table, 'repair_image_variants' ) );
        lha_assert_same( true, is_string( $client ) && str_contains( $client, "action: 'lha_repair_image_variant'" ) );
        lha_assert_same( true, is_string( $client ) && str_contains( $client, 'index++' ) );
    }
);

lha_test(
    'maps every dashboard statistic card to a supported report filter',
    static function(): void {
        $cards = LHA_Admin::get_dashboard_stat_cards();

        lha_assert_same( 13, count( $cards ) );
        lha_assert_same(
            array( '', 'internal', 'external', 'broken', '404', '5xx', 'server_error', 'redirect', 'timeout', 'ssl_error', 'dns_error', 'forbidden', 'ignored' ),
            array_column( $cards, 'filter' )
        );

        foreach ( $cards as $card ) {
            lha_assert_same( $card['filter'], LHA_DB::sanitize_report_filter_key( $card['filter'] ) );
        }

        $admin = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $css   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/assets/css/admin.css' );

        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'class="lha-stat-card lha-stat-card-link' ) );
        lha_assert_same( true, is_string( $css ) && str_contains( $css, '.lha-stat-card-link:focus' ) );
    }
);

lha_test(
    'keeps release metadata tied to the plugin version',
    static function(): void {
        $main = file_get_contents( dirname( __DIR__ ) . '/linkvitals/linkvitals.php' );
        preg_match( "/define\( 'LHA_VERSION', '([^']+)' \)/", is_string( $main ) ? $main : '', $version_match );
        $version = $version_match[1] ?? '';

        lha_assert_same( true, '' !== $version );
        foreach ( array( 'linkvitals.pot', 'linkvitals-zh_CN.po' ) as $catalog ) {
            $contents = file_get_contents( dirname( __DIR__ ) . '/linkvitals/languages/' . $catalog );
            lha_assert_same(
                true,
                is_string( $contents ) && str_contains( $contents, 'Project-Id-Version: LinkVitals ' . $version )
            );
        }

        $checker = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-link-checker.php' );
        lha_assert_same( true, is_string( $checker ) && str_contains( $checker, 'LHA_VERSION' ) );
        lha_assert_same( 0, preg_match( '/LinkVitals\/[0-9]+\.[0-9]+\.[0-9]+/', is_string( $checker ) ? $checker : '' ) );
    }
);

lha_test(
    'keeps the scan running while another worker owns queue items',
    static function(): void {
        $GLOBALS['lha_test_options'] = array(
            'lha_scan_status' => 'running',
            'lha_settings'    => array( 'batch_size' => 20 ),
        );
        $GLOBALS['lha_test_db_events'] = array();
        $GLOBALS['wpdb'] = new LHA_Test_WPDB();

        $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
        $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
        $queue_property->setAccessible( true );
        $queue_property->setValue(
            $scanner,
            new LHA_Test_Queue(
                array(
                    'pending'    => 0,
                    'processing' => 1,
                    'done'       => 2,
                    'failed'     => 0,
                    'paused'     => 0,
                )
            )
        );

        $result = $scanner->process_queue_batch();

        lha_assert_same( array( 'status' => 'running', 'processed' => 0 ), $result );
        lha_assert_same( 'running', $GLOBALS['lha_test_options']['lha_scan_status'] );
        lha_assert_same( array( 'queue_counts' ), $GLOBALS['lha_test_db_events'] );
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
