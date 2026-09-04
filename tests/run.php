<?php
/**
 * Dependency-free contract tests for LinkVitals.
 *
 * @package LinkVitals
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['lha_test_options'] = array();
$GLOBALS['lha_test_transients'] = array();
$GLOBALS['lha_test_db_events'] = array();
$GLOBALS['lha_test_terms'] = null;
$GLOBALS['lha_test_term_queries'] = array();

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( mixed $key ): string {
        $key = strtolower( (string) $key );
        return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( mixed $text ): string {
        return trim( strip_tags( (string) $text ) );
    }
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( mixed $text ): string {
        return trim( strip_tags( (string) $text ) );
    }
}

if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( mixed $email ): string {
        return filter_var( (string) $email, FILTER_SANITIZE_EMAIL );
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
    function update_option( string $name, mixed $value, ?bool $autoload = null ): bool {
        unset( $autoload );
        $GLOBALS['lha_test_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = true ): bool {
        unset( $deprecated, $autoload );
        if ( array_key_exists( $name, $GLOBALS['lha_test_options'] ) ) {
            return false;
        }

        $GLOBALS['lha_test_options'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $name ): bool {
        unset( $GLOBALS['lha_test_options'][ $name ] );
        return true;
    }
}

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $name ): mixed {
        return $GLOBALS['lha_test_transients'][ $name ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $name, mixed $value, int $expiration = 0 ): bool {
        unset( $expiration );
        $GLOBALS['lha_test_transients'][ $name ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $name ): bool {
        unset( $GLOBALS['lha_test_transients'][ $name ] );
        return true;
    }
}

if ( ! function_exists( 'get_taxonomies' ) ) {
    function get_taxonomies( array $args = array(), string $output = 'names' ): array {
        unset( $args, $output );
        return array( 'category' );
    }
}

if ( ! function_exists( 'get_terms' ) ) {
    function get_terms( array $args = array() ): array {
        $GLOBALS['lha_test_term_queries'][] = $args;
        $terms = is_array( $GLOBALS['lha_test_terms'] )
            ? $GLOBALS['lha_test_terms']
            : array(
                (object) array( 'term_id' => 11, 'description' => 'Category description' ),
                (object) array( 'term_id' => 12, 'description' => '' ),
            );

        $offset = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        $number = isset( $args['number'] ) ? (int) $args['number'] : 0;
        return $number > 0 ? array_slice( $terms, $offset, $number ) : array_slice( $terms, $offset );
    }
}

if ( ! function_exists( 'get_term_link' ) ) {
    function get_term_link( object $term ): string {
        return 'https://example.com/category/' . $term->term_id;
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

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( mixed $value ): bool {
        unset( $value );
        return false;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( array $response ): int {
        return (int) ( $response['response']['code'] ?? 0 );
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( array $response ): string {
        return (string) ( $response['body'] ?? '' );
    }
}

require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-link-extractor.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-link-checker.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-image-repair.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-queue.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-cron.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai-internal.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai-jobs.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-settings.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-seo-checker.php';
require_once dirname( __DIR__ ) . '/linkvitals/includes/class-lha-repair.php';

class LHA_Test_WPDB {

    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';

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
        if ( str_contains( $query, "UPDATE wp_lha_links SET status = 'pending' WHERE is_ignored = 0" ) ) {
            $this->operations[] = 'all_links_recheck_update';
            $updated = 0;

            foreach ( $this->rows as &$row ) {
                if ( empty( $row['is_ignored'] ) ) {
                    $row['status'] = 'pending';
                    $updated++;
                }
            }
            unset( $row );

            return $updated;
        }

        if ( str_contains( $query, "UPDATE wp_lha_links SET status = 'pending'" ) ) {
            $this->operations[] = 'issue_recheck_update';
            $updated = 0;
            $issue_statuses = LHA_DB::get_issue_statuses();

            foreach ( $this->rows as &$row ) {
                if ( empty( $row['is_ignored'] ) && in_array( $row['status'], $issue_statuses, true ) ) {
                    $row['status'] = 'pending';
                    $updated++;
                }
            }
            unset( $row );

            return $updated;
        }

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

    public function get_var( string $query ): mixed {
        if ( str_contains( $query, 'SELECT COUNT(*) FROM wp_lha_links WHERE is_ignored = 0' ) ) {
            return count(
                array_filter(
                    $this->rows,
                    static fn( array $row ): bool => empty( $row['is_ignored'] )
                )
            );
        }

        return null;
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

    /** @var array<int, array<string, mixed>> */
    public array $added = array();

    /** @var array<int, int> */
    public array $claimed_batch_sizes = array();

    /** @param array<string, int> $counts */
    public function __construct( array $counts ) {
        $this->counts = $counts;
    }

    public function reset_stuck( int $minutes = 10 ): int {
        unset( $minutes );
        return 0;
    }

    public function get_pending( int $batch_size = 20 ): array {
        $this->claimed_batch_sizes[] = $batch_size;
        return array();
    }

    public function get_counts(): array {
        $GLOBALS['lha_test_db_events'][] = 'queue_counts';
        return $this->counts;
    }

    public function add( string $object_type, int $object_id, string $object_url = '', int $priority = 5, bool $check_existing = true ): int|false {
        $this->added[] = compact( 'object_type', 'object_id', 'object_url', 'priority', 'check_existing' );
        return count( $this->added );
    }

    public function add_refresh( string $object_type, int $object_id, string $object_url = '', int $priority = 1 ): int|false {
        return $this->add( $object_type, $object_id, $object_url, $priority );
    }

    public function add_many( array $items, bool $check_existing = true ): int {
        foreach ( $items as $item ) {
            $this->add(
                (string) ( $item['object_type'] ?? '' ),
                (int) ( $item['object_id'] ?? 0 ),
                (string) ( $item['object_url'] ?? '' ),
                (int) ( $item['priority'] ?? 5 ),
                $check_existing
            );
        }

        return count( $items );
    }
}

class LHA_Test_Token_Swapping_Queue extends LHA_Test_Queue {

    public function get_counts(): array {
        update_option( 'lha_scan_token', 'replacement-scan-token' );
        return parent::get_counts();
    }
}

class LHA_Test_Cleanup_WPDB extends LHA_Test_WPDB {

    public string $postmeta = 'wp_postmeta';
    public string $term_taxonomy = 'wp_term_taxonomy';

    /** @var array<int, string> */
    public array $cleanup_queries = array();

    public function query( string $query ): int|false {
        $this->cleanup_queries[] = $query;
        return 1;
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
        $inputs = array(
            ' HTTPS://WWW.Example.COM:443/Path/#section ',
            'http://example.com:80/',
            'https://example.com:80/path/',
            'http://example.com:443/path#part',
            'mailto:USER@Example.COM',
            '/relative/path///',
            '',
        );

        foreach ( $inputs as $input ) {
            $normalized = LHA_DB::normalize_url( $input );
            lha_assert_same( $normalized, LHA_DB::normalize_url( $normalized ) );
            lha_assert_same( false, str_contains( $normalized, '#' ) );
        }

        lha_assert_same( 'https://example.com:80/path', LHA_DB::normalize_url( 'https://example.com:80/path/' ) );
        lha_assert_same( 'http://example.com:443/path', LHA_DB::normalize_url( 'http://example.com:443/path/' ) );
        lha_assert_same( 'https://example.com/path', LHA_DB::normalize_url( 'https://example.com:443/path/' ) );
        lha_assert_same( 'http://example.com/path', LHA_DB::normalize_url( 'http://example.com:80/path/' ) );
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
            './next.html'                => 'https://example.com:8443/articles/next.html',
            '../archive/item.html'       => 'https://example.com:8443/archive/item.html',
            '?page=2'                    => 'https://example.com:8443/articles/current.html?page=2',
            '/a/../downloads/./file.pdf' => 'https://example.com:8443/downloads/file.pdf',
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
    'extracts every supported tag without inventing missing attributes',
    static function(): void {
        $extractor = new LHA_Link_Extractor();
        $links = $extractor->extract(
            '<a href="/page"> Link <strong>text</strong> </a><a>Missing</a><a href="">Empty</a>' .
            '<img src="/image.jpg" alt="Image"><iframe src="/frame"></iframe><embed src="/embed">' .
            '<object data="/object"></object><video src="/movie.mp4"></video><audio src="/sound.mp3"></audio>' .
            '<source srcset="/small.webp 1x, /large.webp 2x">',
            'https://example.com/articles/item'
        );

        lha_assert_same( 10, count( $links ) );
        lha_assert_same(
            array( 'a', 'a', 'img', 'iframe', 'embed', 'object', 'video', 'audio', 'source', 'source' ),
            array_column( $links, 'html_tag' )
        );
        lha_assert_same( 'Link text', $links[0]['anchor_text'] );
        lha_assert_same( 'empty', $links[1]['link_type'] );
        lha_assert_same( array( 'media', 'media' ), array_slice( array_column( $links, 'link_type' ), 6, 2 ) );
        lha_assert_same( array( 'image', 'image' ), array_slice( array_column( $links, 'link_type' ), 8, 2 ) );
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
    'clamps queue claim sizes and never returns an item twice',
    static function(): void {
        $rows = array();
        for ( $id = 1; $id <= 105; $id++ ) {
            $rows[] = array(
                'id'          => $id,
                'status'      => 'pending',
                'priority'    => 1,
                'created_at'  => '2026-07-15 10:00:00',
                'claim_token' => '',
            );
        }

        $wpdb = new LHA_Test_WPDB( $rows );
        $GLOBALS['wpdb'] = $wpdb;
        $queue = new LHA_Queue();
        $first = $queue->get_pending( 1000 );
        $second = $queue->get_pending( 1000 );

        lha_assert_same( 100, count( $first ) );
        lha_assert_same( 5, count( $second ) );
        lha_assert_same( array(), array_intersect( array_column( $first, 'id' ), array_column( $second, 'id' ) ) );
        lha_assert_same( range( 1, 100 ), array_map( 'intval', array_column( $first, 'id' ) ) );
        lha_assert_same( range( 101, 105 ), array_map( 'intval', array_column( $second, 'id' ) ) );

        $wpdb = new LHA_Test_WPDB( $rows );
        $GLOBALS['wpdb'] = $wpdb;
        $minimum = ( new LHA_Queue() )->get_pending( 0 );
        lha_assert_same( 1, count( $minimum ) );
        lha_assert_same( 1, (int) $minimum[0]['id'] );
    }
);

lha_test(
    'classifies every HTTP status boundary consistently',
    static function(): void {
        $method = new ReflectionMethod( LHA_Link_Checker::class, 'classify_status' );
        $method->setAccessible( true );
        $checker = new LHA_Link_Checker();
        $cases = array(
            0   => 'unknown',
            199 => 'unknown',
            200 => 'ok',
            299 => 'ok',
            300 => 'redirect',
            399 => 'redirect',
            400 => 'broken',
            402 => 'broken',
            403 => 'forbidden',
            404 => 'broken',
            499 => 'broken',
            500 => 'server_error',
            519 => 'server_error',
            520 => 'forbidden',
            527 => 'forbidden',
            528 => 'server_error',
            599 => 'server_error',
            600 => 'unknown',
        );

        foreach ( $cases as $code => $expected ) {
            lha_assert_same( $expected, $method->invoke( $checker, $code, array( 'final_url' => '' ) ) );
        }

        lha_assert_same( 'redirect', $method->invoke( $checker, 200, array( 'final_url' => 'https://example.com/final' ) ) );
    }
);

lha_test(
    'declares indexed queue claim tokens in the database schema',
    static function(): void {
        $schema = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php' );

        lha_assert_same( true, is_string( $schema ) && str_contains( $schema, "claim_token varchar(36) NOT NULL DEFAULT ''" ) );
        lha_assert_same( true, is_string( $schema ) && str_contains( $schema, 'KEY claim_token (claim_token)' ) );
        lha_assert_same( true, is_string( $schema ) && str_contains( $schema, 'KEY claim_order (status, priority, created_at, id)' ) );
    }
);

lha_test(
    'avoids redundant lookups while populating a cleared full-scan queue',
    static function(): void {
        $queue = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-queue.php' );
        $scanner = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php' );

        $posts_start = strpos( is_string( $scanner ) ? $scanner : '', 'private function queue_posts(' );
        $posts_end = strpos( is_string( $scanner ) ? $scanner : '', 'private function queue_nav_menus(', (int) $posts_start );
        $posts_section = false !== $posts_start && false !== $posts_end ? substr( $scanner, $posts_start, $posts_end - $posts_start ) : '';

        $menus_start = $posts_end;
        $menus_end = strpos( is_string( $scanner ) ? $scanner : '', 'private function queue_taxonomies(', (int) $menus_start );
        $menus_section = false !== $menus_start && false !== $menus_end ? substr( $scanner, $menus_start, $menus_end - $menus_start ) : '';

        $terms_start = strpos( is_string( $scanner ) ? $scanner : '', 'private function queue_taxonomies(' );
        $terms_end = strpos( is_string( $scanner ) ? $scanner : '', '* Pause scanning.', (int) $terms_start );
        $terms_section = false !== $terms_start && false !== $terms_end ? substr( $scanner, $terms_start, $terms_end - $terms_start ) : '';

        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'bool $check_existing = true' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'if ( $check_existing )' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'private const INSERT_BATCH_SIZE = 100' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'public function add_many( array $items, bool $check_existing = true ): int' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'array_chunk( $items, self::INSERT_BATCH_SIZE )' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'INSERT INTO {$this->table}' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, '$this->queue_posts( $post_type, null, false )' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, '$this->queue_nav_menus( null, false )' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, '$this->queue_taxonomies( null, false )' ) );
        lha_assert_same( true, str_contains( $posts_section, "'no_found_rows'  => true" ) );
        lha_assert_same( true, str_contains( $posts_section, "'update_post_meta_cache' => false" ) );
        lha_assert_same( true, str_contains( $posts_section, "'update_post_term_cache' => false" ) );
        lha_assert_same( false, str_contains( $posts_section, 'get_permalink(' ) );
        lha_assert_same( true, str_contains( $menus_section, '$menu_items = wp_get_nav_menu_items(' ) );
        lha_assert_same( true, str_contains( $menus_section, '$queue_items[] = array(' ) );
        lha_assert_same( true, str_contains( $menus_section, '$this->queue->add_many( $queue_items, $check_existing )' ) );
        lha_assert_same( false, str_contains( $terms_section, 'get_term_link(' ) );
        lha_assert_same( true, str_contains( $posts_section, 'count( $post_ids ) === $args[\'posts_per_page\']' ) );
        lha_assert_same( true, substr_count( $scanner, '$this->queue->add_many(' ) >= 3 );
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
    'parses current OpenAI and Claude structured response envelopes',
    static function(): void {
        $ai = new LHA_AI();

        $openai_method = new ReflectionMethod( LHA_AI::class, 'parse_openai_response' );
        $openai_method->setAccessible( true );
        $openai = $openai_method->invoke(
            $ai,
            array(
                'response' => array( 'code' => 200 ),
                'body'     => json_encode(
                    array(
                        'status' => 'completed',
                        'model'  => 'gpt-test',
                        'output' => array(
                            array(
                                'type'    => 'message',
                                'content' => array(
                                    array( 'type' => 'output_text', 'text' => '{"suggestions":[]}' ),
                                ),
                            ),
                        ),
                        'usage' => array( 'total_tokens' => 12 ),
                    )
                ),
            )
        );

        lha_assert_same( true, $openai['success'] );
        lha_assert_same( '{"suggestions":[]}', $openai['raw'] );
        lha_assert_same( 12, $openai['tokens'] );

        $claude_method = new ReflectionMethod( LHA_AI::class, 'parse_claude_response' );
        $claude_method->setAccessible( true );
        $claude = $claude_method->invoke(
            $ai,
            array(
                'response' => array( 'code' => 200 ),
                'body'     => json_encode(
                    array(
                        'stop_reason' => 'end_turn',
                        'model'       => 'claude-test',
                        'content'     => array(
                            array( 'type' => 'text', 'text' => '{"suggestions":[]}' ),
                        ),
                        'usage' => array( 'input_tokens' => 7, 'output_tokens' => 5 ),
                    )
                ),
            )
        );

        lha_assert_same( true, $claude['success'] );
        lha_assert_same( '{"suggestions":[]}', $claude['raw'] );
        lha_assert_same( 12, $claude['tokens'] );
    }
);

lha_test(
    'keeps AI suggestions inside the server candidate whitelist',
    static function(): void {
        $candidates = array(
            array(
                'source_post_id' => 10,
                'title'          => 'Approved source',
                'permalink'      => 'https://example.com/approved',
                'edit_url'       => 'https://example.com/wp-admin/post.php?post=10&action=edit',
            ),
            array(
                'source_post_id' => 20,
                'title'          => 'Second source',
                'permalink'      => 'https://example.com/second',
                'edit_url'       => 'https://example.com/wp-admin/post.php?post=20&action=edit',
            ),
        );
        $suggestions = array(
            array( 'source_post_id' => 999, 'anchor_text' => 'Invented', 'placement_hint' => 'Anywhere', 'reason' => 'Unknown ID' ),
            array( 'source_post_id' => 10, 'anchor_text' => '<b>Useful anchor</b>', 'placement_hint' => 'After the introduction', 'reason' => 'Relevant context' ),
            array( 'source_post_id' => 10, 'anchor_text' => 'Duplicate', 'placement_hint' => 'Footer', 'reason' => 'Duplicate ID' ),
            array( 'source_post_id' => 20, 'anchor_text' => '', 'placement_hint' => 'Body', 'reason' => 'Missing anchor' ),
        );

        $normalized = LHA_AI_Internal::normalize_suggestions( $suggestions, $candidates );

        lha_assert_same( 1, count( $normalized ) );
        lha_assert_same( 10, $normalized[0]['source_post_id'] );
        lha_assert_same( 'Useful anchor', $normalized[0]['anchor_text'] );
        lha_assert_same( 'https://example.com/approved', $normalized[0]['source_url'] );
    }
);

lha_test(
    'uses one stable state contract for every AI background job result',
    static function(): void {
        $state = LHA_AI_Jobs::make_state( 'job-1', 'queued', 42 );

        lha_assert_same(
            array( 'job_id', 'status', 'target_post_id', 'suggestions', 'model', 'tokens', 'message', 'error', 'updated_at' ),
            array_keys( $state )
        );
        lha_assert_same( 'job-1', $state['job_id'] );
        lha_assert_same( 42, $state['target_post_id'] );
        lha_assert_same( array(), $state['suggestions'] );
    }
);

lha_test(
    'keeps AI background jobs isolated to their initiating user',
    static function(): void {
        $state = LHA_AI_Jobs::make_state(
            'job-1',
            'queued',
            42,
            array( '_user_id' => 7 )
        );
        set_transient( 'lha_ai_job_job-1', $state, DAY_IN_SECONDS );

        $owner_state = LHA_AI_Jobs::get_status( 'job-1', 7 );
        lha_assert_same( 'queued', $owner_state['status'] );
        lha_assert_same( false, array_key_exists( '_user_id', $owner_state ) );

        $other_user_state = LHA_AI_Jobs::get_status( 'job-1', 8 );
        lha_assert_same( 'failed', $other_user_state['status'] );
        lha_assert_same( 0, $other_user_state['target_post_id'] );

        $method = new ReflectionMethod( LHA_AI_Jobs::class, 'active_key' );
        $method->setAccessible( true );
        lha_assert_same( 'lha_ai_active_7_42', $method->invoke( null, 42, 7 ) );
        lha_assert_same( 'lha_ai_active_8_42', $method->invoke( null, 42, 8 ) );

        $jobs = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai-jobs.php' );
        lha_assert_same( true, is_string( $jobs ) && str_contains( $jobs, '$target_post_id !== absint( $state[\'target_post_id\'] ?? 0 )' ) );
        lha_assert_same( true, is_string( $jobs ) && str_contains( $jobs, '$user_id !== absint( $state[\'_user_id\'] ?? 0 )' ) );
    }
);

lha_test(
    'limits internal-link source counts to matching published post occurrences',
    static function(): void {
        $analyzer = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-internal-analyzer.php' );
        $ai       = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai-internal.php' );
        $post_join = 'p.ID = o.object_id AND p.post_type = o.object_type';

        lha_assert_same( 2, substr_count( is_string( $analyzer ) ? $analyzer : '', $post_join ) );
        lha_assert_same( 1, substr_count( is_string( $ai ) ? $ai : '', $post_join ) );
        lha_assert_same( true, is_string( $analyzer ) && str_contains( $analyzer, 'p.post_status = %s' ) );
        lha_assert_same( true, is_string( $ai ) && str_contains( $ai, 'p.post_status = %s' ) );
    }
);

lha_test(
    'preserves encrypted AI keys when settings password fields stay blank',
    static function(): void {
        $method = new ReflectionMethod( LHA_Settings::class, 'validate_and_sanitize' );
        $method->setAccessible( true );
        $saved = $method->invoke(
            new LHA_Settings(),
            array(
                'ai_provider'      => 'openai',
                'ai_key_openai'    => '',
                'ai_key_claude'    => '',
                'ai_model_openai'  => LHA_AI::OPENAI_DEFAULT_MODEL,
                'ai_model_claude'  => LHA_AI::CLAUDE_DEFAULT_MODEL,
            ),
            array(
                'ai_key_openai' => 'encrypted-openai',
                'ai_key_claude' => 'encrypted-claude',
            )
        );

        lha_assert_same( 'encrypted-openai', $saved['ai_key_openai'] );
        lha_assert_same( 'encrypted-claude', $saved['ai_key_claude'] );
        lha_assert_same( 'openai', $saved['ai_provider'] );
    }
);

lha_test(
    'wires orphan suggestions through background polling and structured provider output',
    static function(): void {
        $main     = file_get_contents( dirname( __DIR__ ) . '/linkvitals/linkvitals.php' );
        $admin    = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $jobs     = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai-jobs.php' );
        $ai       = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-ai.php' );
        $client   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/assets/js/ai-admin.js' );

        lha_assert_same( true, is_string( $main ) && str_contains( $main, 'class-lha-ai-jobs.php' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'wp_ajax_lha_ai_orphan_trigger' ) );
        lha_assert_same( true, is_string( $jobs ) && str_contains( $jobs, 'wp_schedule_single_event' ) );
        lha_assert_same( true, is_string( $jobs ) && str_contains( $jobs, 'wp_set_current_user( $user_id )' ) );
        lha_assert_same( true, is_string( $ai ) && str_contains( $ai, '/v1/responses' ) );
        lha_assert_same( true, is_string( $ai ) && str_contains( $ai, "'output_config'" ) );
        lha_assert_same( true, is_string( $client ) && str_contains( $client, "request('lha_ai_orphan_status'" ) );
        lha_assert_same( true, is_string( $client ) && ! str_contains( $client, 'setInterval(' ) );
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
    'wires CI for supported PHP versions and release validation',
    static function(): void {
        $workflow = file_get_contents( dirname( __DIR__ ) . '/.github/workflows/ci.yml' );

        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'permissions:' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'contents: read' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, "- '8.0'" ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, "- '8.3'" ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'php tests/run.php' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'python tools/i18n-sync.py' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'python generate-mo.py' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'python tools/package-release.py' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'python tools/dev-verify.py' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'wordpress-integration:' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'image: mysql:8.0' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, "wordpress: '6.4'" ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'wordpress: latest' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'tools: wp-cli' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'tests/integration/run.php' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'plugin uninstall linkvitals' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'wordpress-multisite:' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'core multisite-install' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'site create --slug=before-activation' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'plugin activate linkvitals --network' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'site create --slug=after-activation' ) );
        lha_assert_same( true, is_string( $workflow ) && str_contains( $workflow, 'plugin deactivate linkvitals --network' ) );

        $integration = file_get_contents( dirname( __DIR__ ) . '/tests/integration/run.php' );
        $uninstall   = file_get_contents( dirname( __DIR__ ) . '/tests/integration/verify-uninstall.php' );
        $activator   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-activator.php' );
        $deactivator = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-deactivator.php' );
        $main        = file_get_contents( dirname( __DIR__ ) . '/linkvitals/linkvitals.php' );
        $multisite   = file_get_contents( dirname( __DIR__ ) . '/tests/integration/multisite-run.php' );
        $multisite_deactivation = file_get_contents( dirname( __DIR__ ) . '/tests/integration/multisite-verify-deactivation.php' );
        $multisite_uninstall = file_get_contents( dirname( __DIR__ ) . '/tests/integration/multisite-verify-uninstall.php' );

        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, "wp_next_scheduled( 'lha_process_queue' )" ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$queue->get_pending( 1 )' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$queue->increment_attempts( $retry_id' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, "\$attempt < 3 ? 'pending' : 'failed'" ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'A terminally failed item was claimed again.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$queue->reset_stuck( 10 )' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Fresh processing work was reset as stuck.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The reclaimed item reused its stale claim token.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'LHA_Security::verify_ajax_nonce()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'wp_insert_post(' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'wp_insert_term(' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'wp_update_nav_menu_item(' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$scanner->start_full_scan()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'lha_integration_occurrence_count' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$repair->replace_url(' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$repair->rollback( $repair_id )' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Rollback ignored a newer content edit.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Repair rollback resumed an explicitly paused scan.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'A no-op URL repair reported success.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The repaired post was not queued for refresh.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Replacement preview exposed an unsupported menu source.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, "add_filter( 'pre_http_request', \$http_filter, 10, 3 )" ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, "add_filter( 'pre_wp_mail', \$mail_filter, 10, 2 )" ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, "while ( 'running' === get_option( 'lha_scan_status' )" ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$cron->process_queue()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'LHA_Cron::complete_notification_tracking()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The deterministic 404 was not classified as broken.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'A second completion path sent a duplicate email.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'A second completion path wrote a duplicate notification log.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$cron->run_scheduled_scan()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'No-change scheduled scan changed completion time.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'No-change scheduled scan advanced the content cursor.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'No-change scheduled scan sent an email.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$pause_scanner->pause()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$pause_scanner->resume()' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$paused_progress === $still_paused_progress' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The resumed scan did not complete.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'lha_integration_force_modified_after' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'lha_integration_pending_queue_count' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The changed post was not queued.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'An unchanged post was queued.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The new incremental issue did not send one notification.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'The unchanged post was queued on the second scan.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'An unchanged issue was checked again.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'An unchanged issue sent another notification.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, '$incremental_logs_before + 2 === $incremental_logs_after' ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, 'false === get_transient( $transient_name )' ) );
        lha_assert_same( true, is_string( $activator ) && str_contains( $activator, "add_filter( 'cron_schedules', array( LHA_Cron::class, 'add_schedules' ) )" ) );
        lha_assert_same( true, is_string( $activator ) && str_contains( $activator, 'public static function activate( bool $network_wide = false, bool $store_version = true )' ) );
        lha_assert_same( true, is_string( $activator ) && str_contains( $activator, "false === get_option( 'lha_version', false )" ) );
        lha_assert_same( false, is_string( $activator ) && str_contains( $activator, "update_option( 'lha_version', LHA_VERSION )" ) );
        lha_assert_same( true, is_string( $main ) && str_contains( $main, 'LHA_Activator::activate( false, false )' ) );
        lha_assert_same( true, is_string( $main ) && str_contains( $main, 'if ( $upgraded )' ) );
        lha_assert_same( false, is_string( $main ) && str_contains( $main, '$upgraded ? LHA_VERSION : $current_version' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Upgrade provisioning committed the new version before required routines completed.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'Reactivation replaced the version marker before required upgrade routines completed.' ) );
        lha_assert_same( true, is_string( $integration ) && str_contains( $integration, 'A blocked upgrade rewrote the version marker.' ) );
        lha_assert_same( true, is_string( $main ) && str_contains( $main, "add_action( 'wp_initialize_site', array( LHA_Activator::class, 'activate_new_site' ), 200 )" ) );
        lha_assert_same( true, is_string( $activator ) && str_contains( $activator, 'public static function activate_new_site( WP_Site $new_site )' ) );
        lha_assert_same( true, is_string( $activator ) && str_contains( $activator, 'active_sitewide_plugins' ) );
        lha_assert_same( true, is_string( $deactivator ) && str_contains( $deactivator, 'public static function deactivate( bool $network_wide = false )' ) );
        lha_assert_same( true, is_string( $deactivator ) && str_contains( $deactivator, 'private static function deactivate_single_site' ) );
        lha_assert_same( true, is_string( $deactivator ) && str_contains( $deactivator, 'wp_unschedule_hook( LHA_AI_Jobs::HOOK )' ) );
        lha_assert_same( true, is_string( $multisite ) && str_contains( $multisite, 'lha_integration_multisite_expectations' ) );
        lha_assert_same( true, is_string( $multisite_deactivation ) && str_contains( $multisite_deactivation, "wp_next_scheduled( 'lha_process_queue' )" ) );
        lha_assert_same( true, is_string( $multisite_deactivation ) && str_contains( $multisite_deactivation, 'lha_multisite_has_scheduled_hook' ) );
        lha_assert_same( true, is_string( $multisite_uninstall ) && str_contains( $multisite_uninstall, "array( 'delete', 'preserve' )" ) );

        $schedule_method = new ReflectionMethod( LHA_Cron::class, 'add_schedules' );
        lha_assert_same( true, $schedule_method->isStatic() );
    }
);

lha_test(
    'clamps settings boundaries and rejects unsupported option values',
    static function(): void {
        $method = new ReflectionMethod( LHA_Settings::class, 'validate_and_sanitize' );
        $method->setAccessible( true );
        $settings = new LHA_Settings();

        $maximums = $method->invoke(
            $settings,
            array(
                'auto_scan'                       => 'yes',
                'scan_frequency'                  => 'hourly',
                'batch_size'                      => 1000,
                'http_timeout'                    => 300,
                'max_redirects'                   => 100,
                'proxy_enabled'                   => 1,
                'proxy_port'                      => 70000,
                'proxy_type'                      => 'ftp',
                'ai_provider'                     => 'unsupported',
                'ai_model_openai'                 => '',
                'ai_model_claude'                 => '',
                'repair_history_retention_days'   => 99999,
                'language'                        => 'zh-cn',
            )
        );

        lha_assert_same( 1, $maximums['auto_scan'] );
        lha_assert_same( 'weekly', $maximums['scan_frequency'] );
        lha_assert_same( 100, $maximums['batch_size'] );
        lha_assert_same( 30, $maximums['http_timeout'] );
        lha_assert_same( 10, $maximums['max_redirects'] );
        lha_assert_same( 65535, $maximums['proxy_port'] );
        lha_assert_same( 'http', $maximums['proxy_type'] );
        lha_assert_same( '', $maximums['ai_provider'] );
        lha_assert_same( LHA_AI::OPENAI_DEFAULT_MODEL, $maximums['ai_model_openai'] );
        lha_assert_same( LHA_AI::CLAUDE_DEFAULT_MODEL, $maximums['ai_model_claude'] );
        lha_assert_same( 3650, $maximums['repair_history_retention_days'] );
        lha_assert_same( 'zh_CN', $maximums['language'] );
        lha_assert_same( 1, $maximums['language_manually_selected'] );

        $minimums = $method->invoke(
            $settings,
            array(
                'scan_frequency'                => 'daily',
                'batch_size'                    => 0,
                'http_timeout'                  => 0,
                'max_redirects'                 => 0,
                'repair_history_retention_days' => 0,
                'language'                      => 'invalid-locale',
            )
        );

        lha_assert_same( 'daily', $minimums['scan_frequency'] );
        lha_assert_same( 1, $minimums['batch_size'] );
        lha_assert_same( 1, $minimums['http_timeout'] );
        lha_assert_same( 1, $minimums['max_redirects'] );
        lha_assert_same( 0, $minimums['repair_history_retention_days'] );
        lha_assert_same( 'auto', $minimums['language'] );
    }
);

lha_test(
    'classifies SEO attributes across HTML whitespace and target casing',
    static function(): void {
        $safe = LHA_SEO_Checker::analyze_link(
            "<a href=\"https://external.example\" rel=\"NOFOLLOW\tsponsored\nugc noopener noreferrer\" target=\"_BLANK\">Safe</a>"
        );

        lha_assert_same( true, $safe['has_nofollow'] );
        lha_assert_same( true, $safe['has_sponsored'] );
        lha_assert_same( true, $safe['has_ugc'] );
        lha_assert_same( true, $safe['has_noopener'] );
        lha_assert_same( true, $safe['has_noreferrer'] );
        lha_assert_same( true, $safe['has_target_blank'] );
        lha_assert_same( false, $safe['is_http'] );
        lha_assert_same( array(), $safe['issues'] );

        $unsafe = LHA_SEO_Checker::analyze_link(
            '<a href="https://external.example" target="_blank">Unsafe</a>',
            'http://external.example'
        );
        lha_assert_same(
            array( 'missing_nofollow', 'missing_noopener_noreferrer', 'http_not_https' ),
            $unsafe['issues']
        );

        $not_link = LHA_SEO_Checker::analyze_link( '<span>Not a link</span>' );
        lha_assert_same( array(), $not_link['issues'] );
    }
);

lha_test(
    'fences queue completion and retries with the active claim token',
    static function(): void {
        $queue   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-queue.php' );
        $scanner = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php' );

        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'public function update_status( int $id, string $status, string $claim_token ): bool' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'public function increment_attempts( int $id, string $error, string $claim_token ): bool' ) );
        lha_assert_same( true, is_string( $queue ) && substr_count( $queue, "WHERE id = %d AND status = 'processing' AND claim_token = %s" ) === 2 );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, "SET status = CASE WHEN attempts + 1 >= %d THEN 'failed' ELSE 'pending' END," ) );
        lha_assert_same( false, is_string( $queue ) && str_contains( $queue, 'SELECT attempts FROM {$this->table}' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "\$this->queue->update_status( (int) \$item['id'], 'done', \$claim_token )" ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "\$this->queue->increment_attempts( (int) \$item['id'], \$this->last_item_error, \$claim_token )" ) );
    }
);

lha_test(
    'uses one aggregate query for each admin statistics summary',
    static function(): void {
        $db  = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php' );
        $seo = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-seo-checker.php' );

        $stats_start = strpos( is_string( $db ) ? $db : '', 'public static function get_stats()' );
        $stats_end = strpos( is_string( $db ) ? $db : '', 'public static function get_issue_total_from_stats(', (int) $stats_start );
        $stats_section = false !== $stats_start && false !== $stats_end ? substr( $db, $stats_start, $stats_end - $stats_start ) : '';

        $seo_start = strpos( is_string( $seo ) ? $seo : '', 'public function get_issue_counts()' );
        $seo_end = strpos( is_string( $seo ) ? $seo : '', 'public function get_report(', (int) $seo_start );
        $seo_section = false !== $seo_start && false !== $seo_end ? substr( $seo, $seo_start, $seo_end - $seo_start ) : '';

        lha_assert_same( 1, substr_count( $stats_section, '$wpdb->get_row(' ) );
        lha_assert_same( 0, substr_count( $stats_section, '$wpdb->get_var(' ) );
        lha_assert_same( 12, substr_count( $stats_section, 'COALESCE(SUM(CASE WHEN' ) );
        lha_assert_same( 1, substr_count( $seo_section, '$wpdb->get_row(' ) );
        lha_assert_same( 0, substr_count( $seo_section, '$wpdb->get_var(' ) );
        lha_assert_same( true, str_contains( $seo_section, 'AS missing_nofollow' ) );
        lha_assert_same( true, str_contains( $seo_section, 'AS missing_noopener_noreferrer' ) );
        lha_assert_same( true, str_contains( $seo_section, 'AS http_not_https' ) );
    }
);

lha_test(
    'renders SEO issue badges through the translation catalog',
    static function(): void {
        $admin = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );

        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, "'missing_nofollow'            => __( 'Missing nofollow', 'linkvitals' )" ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, "'missing_noopener_noreferrer' => __( 'Missing noopener/noreferrer', 'linkvitals' )" ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, "'http_not_https'              => __( 'HTTP links', 'linkvitals' )" ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'isset( $issue_labels[ $issue ] )' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'sanitize_key( wp_unslash( $_GET[\'issue\'] ) )' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'if ( ! isset( $issue_labels[ $issue_filter ] ) )' ) );
        lha_assert_same( false, is_string( $admin ) && str_contains( $admin, "str_replace( '_', ' ', \$issue )" ) );
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

lha_test(
    'keeps pause and resume actions within valid scan states',
    static function(): void {
        $GLOBALS['lha_test_options'] = array();
        $GLOBALS['wpdb'] = new LHA_Test_WPDB();
        $scanner = new LHA_Scanner();

        update_option( 'lha_scan_status', 'idle' );
        lha_assert_same( 'idle', $scanner->pause() );
        lha_assert_same( 'idle', get_option( 'lha_scan_status' ) );
        lha_assert_same( 'idle', $scanner->resume() );

        update_option( 'lha_scan_status', 'completed' );
        lha_assert_same( 'completed', $scanner->pause() );
        lha_assert_same( 'completed', $scanner->resume() );

        update_option( 'lha_scan_status', 'running' );
        lha_assert_same( 'paused', $scanner->pause() );
        lha_assert_same( 'paused', get_option( 'lha_scan_status' ) );
        lha_assert_same( 'running', $scanner->resume() );
        lha_assert_same( 'running', get_option( 'lha_scan_status' ) );
    }
);

lha_test(
    'queues repair refreshes without advancing or resuming active scan state',
    static function(): void {
        foreach ( array( 'completed' => 'running', 'paused' => 'paused' ) as $initial_status => $expected_status ) {
            $GLOBALS['lha_test_options'] = array(
                'lha_scan_status'         => $initial_status,
                'lha_scan_token'          => 'existing-repair-token',
                'lha_scan_type'           => 'full',
                'lha_content_scan_cursor' => '2026-07-01 00:00:00',
            );
            $GLOBALS['wpdb'] = new LHA_Test_WPDB();

            $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
            $queue = new LHA_Test_Queue( array() );
            $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
            $queue_property->setAccessible( true );
            $queue_property->setValue( $scanner, $queue );

            lha_assert_same( true, $scanner->queue_repair_refresh( 'post', 42 ) );
            lha_assert_same( 1, count( $queue->added ) );
            lha_assert_same( 'post', $queue->added[0]['object_type'] );
            lha_assert_same( 42, $queue->added[0]['object_id'] );
            lha_assert_same( 1, $queue->added[0]['priority'] );
            lha_assert_same( $expected_status, get_option( 'lha_scan_status' ) );
            lha_assert_same( false, isset( $GLOBALS['lha_test_options']['lha_scan_state_lock'] ) );

            if ( 'completed' === $initial_status ) {
                lha_assert_same( 'repair', get_option( 'lha_scan_type' ) );
                lha_assert_same( false, 'existing-repair-token' === get_option( 'lha_scan_token' ) );
            } else {
                lha_assert_same( 'full', get_option( 'lha_scan_type' ) );
                lha_assert_same( 'existing-repair-token', get_option( 'lha_scan_token' ) );
            }
        }
    }
);

lha_test(
    'guards admin batch notifications and client state transitions',
    static function(): void {
        $admin = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $client = file_get_contents( dirname( __DIR__ ) . '/linkvitals/assets/js/admin.js' );

        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, "if ( 'running' !== \$scan_status )" ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'LHA_Cron::begin_notification_tracking();' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, '$status = $scanner->pause();' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, '$status = $scanner->resume();' ) );
        lha_assert_same( true, is_string( $client ) && str_contains( $client, 'onScanPaused: function()' ) );
        lha_assert_same( true, is_string( $client ) && str_contains( $client, "data.status === 'paused'" ) );
    }
);

lha_test(
    'rejects overlapping scan starts without changing active scan state',
    static function(): void {
        $GLOBALS['lha_test_options'] = array(
            'lha_scan_status'     => 'running',
            'lha_scan_token'      => 'active-scan-token',
            'lha_scan_type'       => 'full',
            'lha_scan_started_at' => '2026-07-15 11:00:00',
            'lha_settings'        => array( 'email_notifications' => 1 ),
        );
        $GLOBALS['lha_test_transients'] = array(
            'lha_pre_scan_broken_count' => 7,
        );
        $GLOBALS['wpdb'] = new LHA_Test_WPDB(
            array(
                array( 'id' => 1, 'status' => 'broken', 'is_ignored' => 0 ),
            )
        );

        $scanner = new LHA_Scanner();

        lha_assert_same(
            array( 'status' => 'already_running', 'total_queued' => 0 ),
            $scanner->start_full_scan()
        );
        lha_assert_same(
            array( 'status' => 'already_running', 'total_queued' => 0 ),
            $scanner->start_incremental_scan()
        );
        lha_assert_same(
            array( 'status' => 'already_running', 'queued' => 0 ),
            $scanner->recheck_broken()
        );

        lha_assert_same( 'running', $GLOBALS['lha_test_options']['lha_scan_status'] );
        lha_assert_same( 'active-scan-token', $GLOBALS['lha_test_options']['lha_scan_token'] );
        lha_assert_same( 'full', $GLOBALS['lha_test_options']['lha_scan_type'] );
        lha_assert_same( 7, $GLOBALS['lha_test_transients']['lha_pre_scan_broken_count'] );
        lha_assert_same( array(), $GLOBALS['wpdb']->operations );
        lha_assert_same( false, isset( $GLOBALS['lha_test_options']['lha_scan_state_lock'] ) );
    }
);

lha_test(
    'clamps corrupted runtime batch sizes before queue and link processing',
    static function(): void {
        foreach ( array( 0 => 1, 1000 => 100 ) as $stored_size => $expected_size ) {
            $GLOBALS['lha_test_options'] = array(
                'lha_scan_status' => 'running',
                'lha_scan_token'  => 'batch-scan-token',
                'lha_settings'    => array( 'batch_size' => $stored_size ),
            );
            $GLOBALS['lha_test_db_events'] = array();
            $GLOBALS['wpdb'] = new LHA_Test_WPDB();

            $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
            $queue   = new LHA_Test_Queue(
                array(
                    'pending'    => 0,
                    'processing' => 1,
                    'done'       => 0,
                    'failed'     => 0,
                    'paused'     => 0,
                )
            );
            $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
            $queue_property->setAccessible( true );
            $queue_property->setValue( $scanner, $queue );

            $result = $scanner->process_queue_batch();

            lha_assert_same( array( 'status' => 'running', 'processed' => 0 ), $result );
            lha_assert_same( array( $expected_size ), $queue->claimed_batch_sizes );
        }
    }
);

lha_test(
    'prevents an older scan token from completing a newer scan generation',
    static function(): void {
        $GLOBALS['lha_test_options'] = array(
            'lha_scan_status'        => 'running',
            'lha_scan_token'         => 'original-scan-token',
            'lha_scan_type'          => 'full',
            'lha_scan_started_at'    => '2026-07-15 11:30:00',
            'lha_content_scan_cursor' => '2026-07-01 00:00:00',
            'lha_settings'           => array( 'batch_size' => 20 ),
        );
        $GLOBALS['lha_test_db_events'] = array();
        $GLOBALS['wpdb'] = new LHA_Test_WPDB();

        $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
        $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
        $queue_property->setAccessible( true );
        $queue_property->setValue(
            $scanner,
            new LHA_Test_Token_Swapping_Queue(
                array(
                    'pending'    => 0,
                    'processing' => 0,
                    'done'       => 1,
                    'failed'     => 0,
                    'paused'     => 0,
                )
            )
        );

        $result = $scanner->process_queue_batch();

        lha_assert_same( array( 'status' => 'running', 'processed' => 0 ), $result );
        lha_assert_same( 'replacement-scan-token', $GLOBALS['lha_test_options']['lha_scan_token'] );
        lha_assert_same( 'running', $GLOBALS['lha_test_options']['lha_scan_status'] );
        lha_assert_same( '2026-07-01 00:00:00', $GLOBALS['lha_test_options']['lha_content_scan_cursor'] );
        lha_assert_same( false, isset( $GLOBALS['lha_test_options']['lha_last_scan_time'] ) );
        lha_assert_same( false, isset( $GLOBALS['lha_test_options']['lha_scan_state_lock'] ) );
    }
);

lha_test(
    'queues every actionable issue for bounded background rechecking',
    static function(): void {
        $GLOBALS['lha_test_options'] = array( 'lha_scan_status' => 'completed' );
        $GLOBALS['wpdb'] = new LHA_Test_WPDB(
            array(
                array( 'id' => 1, 'status' => 'broken', 'is_ignored' => 0 ),
                array( 'id' => 2, 'status' => 'timeout', 'is_ignored' => 0 ),
                array( 'id' => 3, 'status' => 'ok', 'is_ignored' => 0 ),
                array( 'id' => 4, 'status' => 'dns_error', 'is_ignored' => 1 ),
            )
        );

        $scanner = new LHA_Scanner();
        $result  = $scanner->recheck_broken();

        lha_assert_same( array( 'status' => 'started', 'queued' => 2 ), $result );
        lha_assert_same( 'running', $GLOBALS['lha_test_options']['lha_scan_status'] );
        lha_assert_same( array( 'pending', 'pending', 'ok', 'dns_error' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
    }
);

lha_test(
    'serializes upgrade rechecks without resuming paused scans',
    static function(): void {
        $rows = array(
            array( 'id' => 1, 'status' => 'broken', 'is_ignored' => 0 ),
            array( 'id' => 2, 'status' => 'ok', 'is_ignored' => 0 ),
            array( 'id' => 3, 'status' => 'broken', 'is_ignored' => 1 ),
        );

        $GLOBALS['lha_test_options'] = array(
            'lha_scan_status'         => 'paused',
            'lha_scan_type'           => 'full',
            'lha_scan_token'          => 'paused-upgrade-token',
            'lha_content_scan_cursor' => '2026-07-01 00:00:00',
            'lha_settings'            => array( 'email_notifications' => 1 ),
        );
        $GLOBALS['lha_test_transients'] = array( 'lha_pre_scan_broken_count' => 7 );
        $GLOBALS['wpdb'] = new LHA_Test_WPDB( $rows );

        $paused_result = ( new LHA_Scanner() )->queue_all_links_for_recheck();

        lha_assert_same( array( 'status' => 'paused', 'queued' => 2 ), $paused_result );
        lha_assert_same( array( 'pending', 'pending', 'broken' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
        lha_assert_same( 'paused', get_option( 'lha_scan_status' ) );
        lha_assert_same( 'full', get_option( 'lha_scan_type' ) );
        lha_assert_same( 'paused-upgrade-token', get_option( 'lha_scan_token' ) );
        lha_assert_same( '2026-07-01 00:00:00', get_option( 'lha_content_scan_cursor' ) );
        lha_assert_same( 7, get_transient( 'lha_pre_scan_broken_count' ) );

        $GLOBALS['lha_test_options'] = array(
            'lha_scan_status'         => 'completed',
            'lha_content_scan_cursor' => '2026-07-01 00:00:00',
            'lha_settings'            => array( 'email_notifications' => 0 ),
        );
        $GLOBALS['lha_test_transients'] = array();
        $GLOBALS['wpdb'] = new LHA_Test_WPDB( $rows );

        $started_result = ( new LHA_Scanner() )->queue_all_links_for_recheck();

        lha_assert_same( array( 'status' => 'started', 'queued' => 2 ), $started_result );
        lha_assert_same( 'running', get_option( 'lha_scan_status' ) );
        lha_assert_same( 'recheck', get_option( 'lha_scan_type' ) );
        lha_assert_same( '2026-07-01 00:00:00', get_option( 'lha_content_scan_cursor' ) );

        $GLOBALS['lha_test_options']['lha_scan_status'] = 'completed';
        $GLOBALS['lha_test_options']['lha_scan_state_lock'] = array(
            'token'       => 'busy-upgrade-lock',
            'acquired_at' => time(),
        );
        $GLOBALS['wpdb'] = new LHA_Test_WPDB( $rows );

        lha_assert_same(
            array( 'status' => 'busy', 'queued' => 0 ),
            ( new LHA_Scanner() )->queue_all_links_for_recheck()
        );
        lha_assert_same( array( 'broken', 'ok', 'broken' ), array_column( $GLOBALS['wpdb']->rows, 'status' ) );
    }
);

lha_test(
    'requeues taxonomy descriptions during incremental scans',
    static function(): void {
        $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
        $queue   = new LHA_Test_Queue( array() );

        $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
        $queue_property->setAccessible( true );
        $queue_property->setValue( $scanner, $queue );

        $method = new ReflectionMethod( LHA_Scanner::class, 'queue_taxonomies' );
        $method->setAccessible( true );
        $queued = $method->invoke( $scanner, '2026-07-20 00:00:00' );

        lha_assert_same( 1, $queued );
        lha_assert_same( 'taxonomy', $queue->added[0]['object_type'] );
        lha_assert_same( 11, $queue->added[0]['object_id'] );
    }
);

lha_test(
    'pages large taxonomy sources before adding them to the queue',
    static function(): void {
        $scanner = ( new ReflectionClass( LHA_Scanner::class ) )->newInstanceWithoutConstructor();
        $queue   = new LHA_Test_Queue( array() );

        $queue_property = new ReflectionProperty( LHA_Scanner::class, 'queue' );
        $queue_property->setAccessible( true );
        $queue_property->setValue( $scanner, $queue );

        $GLOBALS['lha_test_terms'] = array_map(
            static fn( int $term_id ): object => (object) array(
                'term_id'     => $term_id,
                'description' => "Description {$term_id}",
            ),
            range( 1, 205 )
        );
        $GLOBALS['lha_test_term_queries'] = array();

        try {
            $method = new ReflectionMethod( LHA_Scanner::class, 'queue_taxonomies' );
            $method->setAccessible( true );
            $queued = $method->invoke( $scanner, null, false );

            lha_assert_same( 205, $queued );
            lha_assert_same( 205, count( $queue->added ) );
            lha_assert_same( array( 0, 100, 200 ), array_column( $GLOBALS['lha_test_term_queries'], 'offset' ) );
            lha_assert_same( array( 100, 100, 100 ), array_column( $GLOBALS['lha_test_term_queries'], 'number' ) );
            lha_assert_same( array( 'term_id', 'term_id', 'term_id' ), array_column( $GLOBALS['lha_test_term_queries'], 'orderby' ) );
            lha_assert_same( array( false, false, false ), array_column( $GLOBALS['lha_test_term_queries'], 'update_term_meta_cache' ) );
            lha_assert_same( false, $queue->added[0]['check_existing'] );
            lha_assert_same( false, $queue->added[204]['check_existing'] );
        } finally {
            $GLOBALS['lha_test_terms'] = null;
            $GLOBALS['lha_test_term_queries'] = array();
        }
    }
);

lha_test(
    'separates scan completion time from the safe content cursor',
    static function(): void {
        $GLOBALS['lha_test_options'] = array(
            'lha_settings'       => array( 'batch_size' => 20 ),
            'lha_last_scan_time' => '2026-07-01 01:00:00',
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
                    'processing' => 0,
                    'done'       => 1,
                    'failed'     => 0,
                    'paused'     => 0,
                )
            )
        );

        LHA_Scanner::record_scan_start( 'recheck', '2026-07-15 11:50:00' );
        $legacy_recheck_result = $scanner->process_queue_batch();

        lha_assert_same( 'completed', $legacy_recheck_result['status'] );
        lha_assert_same( '2026-07-01 01:00:00', $GLOBALS['lha_test_options']['lha_content_scan_cursor'] );

        LHA_Scanner::record_scan_start( 'full', '2026-07-15 11:55:00' );
        $full_result = $scanner->process_queue_batch();

        lha_assert_same( 'completed', $full_result['status'] );
        lha_assert_same( '2026-07-15 11:55:00', $GLOBALS['lha_test_options']['lha_content_scan_cursor'] );
        lha_assert_same( '2026-07-15 12:00:00', $GLOBALS['lha_test_options']['lha_last_scan_time'] );

        LHA_Scanner::record_scan_start( 'recheck', '2026-07-15 12:05:00' );
        $recheck_result = $scanner->process_queue_batch();

        lha_assert_same( 'completed', $recheck_result['status'] );
        lha_assert_same( '2026-07-15 11:55:00', $GLOBALS['lha_test_options']['lha_content_scan_cursor'] );
        lha_assert_same( '2026-07-15 12:05:00', $GLOBALS['lha_test_options']['lha_scan_started_at'] );
        lha_assert_same( '2026-07-15 12:00:00', $GLOBALS['lha_test_options']['lha_last_scan_time'] );
    }
);

lha_test(
    'cleans stale sources without discarding occurrences before extraction succeeds',
    static function(): void {
        $scanner = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php' );
        $db      = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php' );

        lha_assert_same( 2, substr_count( is_string( $scanner ) ? $scanner : '', '$this->cleanup_stale_sources( $post_types )' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, 'LHA_DB::cleanup_stale_occurrences( $post_types, $taxonomies )' ) );
        lha_assert_same( true, is_string( $scanner ) && substr_count( $scanner, 'LHA_DB::cleanup_orphaned_links()' ) >= 2 );
        lha_assert_same( true, is_string( $db ) && str_contains( $db, 'public static function cleanup_stale_occurrences' ) );
        lha_assert_same( true, is_string( $db ) && str_contains( $db, '$wpdb->postmeta' ) );
        lha_assert_same( true, is_string( $db ) && str_contains( $db, '$wpdb->term_taxonomy' ) );
        lha_assert_same( true, is_string( $db ) && str_contains( $db, 'BINARY p.post_type = BINARY o.object_type' ) );

        $item_start = strpos( is_string( $scanner ) ? $scanner : '', 'private function process_queue_item(' );
        $item_end = strpos( is_string( $scanner ) ? $scanner : '', 'private function check_links_batch(', (int) $item_start );
        $item_section = false !== $item_start && false !== $item_end ? substr( $scanner, $item_start, $item_end - $item_start ) : '';
        $empty_position = strpos( $item_section, 'if ( empty( $content ) )' );
        $extract_position = strpos( $item_section, '$this->extractor->extract(' );
        $delete_position = strpos( $item_section, 'LHA_DB::delete_occurrences_by_object( $object_type, $object_id )' );
        lha_assert_same(
            true,
            false !== $empty_position &&
            false !== $extract_position &&
            false !== $delete_position &&
            $empty_position < $extract_position &&
            $extract_position < $delete_position
        );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "private string \$last_item_error = '';" ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "\$this->queue->increment_attempts( (int) \$item['id'], \$this->last_item_error, \$claim_token )" ) );
    }
);

lha_test(
    'cleans occurrence sources with bounded SQL branches and sanitized types',
    static function(): void {
        $previous_db = $GLOBALS['wpdb'] ?? null;
        $cleanup_db  = new LHA_Test_Cleanup_WPDB();
        $GLOBALS['wpdb'] = $cleanup_db;

        try {
            $deleted = LHA_DB::cleanup_stale_occurrences(
                array( 'post', 'POST', 'page', 'Bad Type' ),
                array( 'category', 'post_tag', 'category' )
            );

            lha_assert_same( 3, $deleted );
            lha_assert_same( 3, count( $cleanup_db->cleanup_queries ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[0], "IN ('post', 'page', 'badtype')" ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[0], 'BINARY p.post_type = BINARY o.object_type' ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[1], "o.object_type = 'nav_menu_item'" ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[2], "IN ('category', 'post_tag')" ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[2], "TRIM(tt.description) <> ''" ) );

            $cleanup_db->cleanup_queries = array();
            $deleted = LHA_DB::cleanup_stale_occurrences( array(), array() );

            lha_assert_same( 3, $deleted );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[0], "NOT IN ('nav_menu_item', 'taxonomy')" ) );
            lha_assert_same( true, str_contains( $cleanup_db->cleanup_queries[2], "WHERE object_type = 'taxonomy'" ) );
        } finally {
            if ( null === $previous_db ) {
                unset( $GLOBALS['wpdb'] );
            } else {
                $GLOBALS['wpdb'] = $previous_db;
            }
        }
    }
);

lha_test(
    'unlinks matching anchors while preserving unmatched markup and anchor text',
    static function(): void {
        $method = new ReflectionMethod( LHA_Repair::class, 'unlink_url_in_content' );
        $method->setAccessible( true );
        $content = '<p><a href="https://www.example.com/bad"><strong>Keep</strong> text</a> <a href="/bad">Relative</a> <a href="https://other.example/keep">Other</a></p>';

        $result = $method->invoke( new LHA_Repair(), $content, 'https://example.com/bad' );

        lha_assert_same( 2, $result['count'] );
        lha_assert_same( true, str_contains( $result['content'], '<strong>Keep</strong> text' ) );
        lha_assert_same( true, str_contains( $result['content'], 'Relative' ) );
        lha_assert_same( true, str_contains( $result['content'], '<a href="https://other.example/keep">Other</a>' ) );
        lha_assert_same( false, str_contains( $result['content'], 'href="https://www.example.com/bad"' ) );
        lha_assert_same( false, str_contains( $result['content'], 'href="/bad"' ) );
    }
);

lha_test(
    'replaces exact URL tokens without corrupting longer URLs',
    static function(): void {
        $method = new ReflectionMethod( LHA_Repair::class, 'replace_bounded_url' );
        $method->setAccessible( true );

        $old_url = 'https://example.com/bad';
        $new_url = 'https://example.com/fixed';
        $content = '<a href="' . $old_url . '">Exact</a>'
            . '<a href="' . $old_url . 'ger">Longer word</a>'
            . '<a href="' . $old_url . '/child">Child path</a>'
            . '<!-- wp:button {"url":"' . $old_url . '"} -->';
        $count = 0;
        $args = array( $content, $old_url, $new_url, &$count );

        $result = $method->invokeArgs( new LHA_Repair(), $args );

        lha_assert_same( 2, $count );
        lha_assert_same( true, str_contains( $result, 'href="' . $new_url . '"' ) );
        lha_assert_same( true, str_contains( $result, '"url":"' . $new_url . '"' ) );
        lha_assert_same( true, str_contains( $result, $old_url . 'ger' ) );
        lha_assert_same( true, str_contains( $result, $old_url . '/child' ) );
    }
);

lha_test(
    'persists repair snapshots before content writes and preserves paused scans',
    static function(): void {
        $repair = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-repair.php' );
        $db = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-db.php' );
        $queue = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-queue.php' );
        $scanner = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php' );

        $replace_start = strpos( is_string( $repair ) ? $repair : '', 'public function replace_url(' );
        $replace_end = strpos( is_string( $repair ) ? $repair : '', 'public function unlink(', (int) $replace_start );
        $replace_section = false !== $replace_start && false !== $replace_end
            ? substr( $repair, $replace_start, $replace_end - $replace_start )
            : '';
        $unlink_start = strpos( is_string( $repair ) ? $repair : '', 'public function unlink(' );
        $unlink_end = strpos( is_string( $repair ) ? $repair : '', 'public function rollback(', (int) $unlink_start );
        $unlink_section = false !== $unlink_start && false !== $unlink_end
            ? substr( $repair, $unlink_start, $unlink_end - $unlink_start )
            : '';
        $rollback_section = false !== $unlink_end ? substr( $repair, $unlink_end ) : '';

        foreach ( array( $replace_section, $unlink_section ) as $section ) {
            $snapshot_position = strpos( $section, '$repair_id = $this->record_repair(' );
            $write_position = strpos( $section, 'wp_update_post(' );
            lha_assert_same(
                true,
                false !== $snapshot_position && false !== $write_position && $snapshot_position < $write_position
            );
            lha_assert_same( true, str_contains( $section, 'LHA_DB::delete_repair( $repair_id )' ) );
        }

        lha_assert_same( true, is_string( $db ) && str_contains( $db, 'public static function delete_repair(' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, 'public function add_refresh(' ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, "status = 'pending' LIMIT 1" ) );
        lha_assert_same( true, is_string( $queue ) && str_contains( $queue, '$this->add( $object_type, $object_id, $object_url, $priority, false )' ) );
        lha_assert_same( false, str_contains( $rollback_section, "update_option( 'lha_scan_status', 'running' )" ) );
        lha_assert_same( true, str_contains( $rollback_section, 'queue_repair_refresh(' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, 'public function queue_repair_refresh(' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, '$this->queue->add_refresh(' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "self::write_scan_start( 'repair' )" ) );
    }
);

lha_test(
    'serializes complete upgrade transactions with a recoverable owner lock',
    static function(): void {
        $main        = file_get_contents( dirname( __DIR__ ) . '/linkvitals/linkvitals.php' );
        $admin       = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $deactivator = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-deactivator.php' );
        $uninstall   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/uninstall.php' );

        lha_assert_same( true, is_string( $main ) );
        $check_start = strpos( $main, 'public function check_version(): void' );
        $check_end   = strpos( $main, 'private function acquire_upgrade_lock()', (int) $check_start );
        $check       = false !== $check_start && false !== $check_end
            ? substr( $main, $check_start, $check_end - $check_start )
            : '';

        $first_read = strpos( $check, "get_option( 'lha_version', '0' )" );
        $lock       = strpos( $check, '$lock_token = $this->acquire_upgrade_lock();' );
        $second_read = false !== $first_read
            ? strpos( $check, "get_option( 'lha_version', '0' )", $first_read + 1 )
            : false;
        $provision = strpos( $check, 'LHA_Activator::activate( false, false );' );
        $migrate   = strpos( $check, '$this->run_upgrade_routines( $current_version )' );
        $commit    = strpos( $check, "update_option( 'lha_version', LHA_VERSION )" );
        $release   = strpos( $check, '$this->release_upgrade_lock( $lock_token );' );

        lha_assert_same(
            true,
            false !== $first_read
                && false !== $lock
                && false !== $second_read
                && false !== $provision
                && false !== $migrate
                && false !== $commit
                && false !== $release
                && $first_read < $lock
                && $lock < $second_read
                && $second_read < $provision
                && $provision < $migrate
                && $migrate < $commit
                && $commit < $release
        );
        lha_assert_same( true, str_contains( $main, "private const UPGRADE_LOCK_OPTION = 'lha_upgrade_lock';" ) );
        lha_assert_same( true, str_contains( $main, 'add_option( self::UPGRADE_LOCK_OPTION, $value, \'\', false )' ) );
        lha_assert_same( true, str_contains( $main, 'UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s' ) );
        lha_assert_same( true, str_contains( $main, 'maybe_serialize( $current )' ) );
        lha_assert_same( true, str_contains( $main, "wp_cache_delete( self::UPGRADE_LOCK_OPTION, 'options' )" ) );
        lha_assert_same( true, str_contains( $main, '$token === (string) ( $current[\'token\'] ?? \'\' )' ) );

        foreach ( array( $admin, $deactivator, $uninstall ) as $cleanup_source ) {
            lha_assert_same( true, is_string( $cleanup_source ) && str_contains( $cleanup_source, "delete_option( 'lha_upgrade_lock' )" ) );
        }
    }
);

lha_test(
    'shares scan notification completion and fully cleans uninstall state',
    static function(): void {
        $admin     = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $cron      = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-cron.php' );
        $scanner   = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-scanner.php' );
        $uninstall = file_get_contents( dirname( __DIR__ ) . '/linkvitals/uninstall.php' );

        lha_assert_same( false, is_string( $admin ) && str_contains( $admin, 'LHA_Cron::begin_notification_tracking( true )' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'LHA_Cron::complete_notification_tracking()' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, 'LHA_Cron::begin_notification_tracking( true )' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, 'private static function acquire_scan_state_lock()' ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "update_option( 'lha_scan_token', wp_generate_uuid4() )" ) );
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, 'record_scan_completion( string $expected_token ): bool' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'add_option( self::NOTIFICATION_LOCK_OPTION' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'if ( wp_mail( $email, $subject, $body ) )' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'public static function reset_notification_tracking' ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_transient( 'lha_notice_check' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_transient( 'lha_pre_scan_broken_count' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "'_transient_lha_ai_'" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "'_transient_timeout_lha_ai_'" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_option( 'lha_content_scan_cursor' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_option( 'lha_scan_token' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_option( 'lha_scan_state_lock' )" ) );
        lha_assert_same( false, is_string( $uninstall ) && str_contains( $uninstall, "if ( ! \$delete_data ) {\n    return;" ) );
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
