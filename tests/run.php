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
    function update_option( string $name, mixed $value ): bool {
        $GLOBALS['lha_test_options'][ $name ] = $value;
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
        unset( $args );
        return array(
            (object) array( 'term_id' => 11, 'description' => 'Category description' ),
            (object) array( 'term_id' => 12, 'description' => '' ),
        );
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

    public function add( string $object_type, int $object_id, string $object_url = '', int $priority = 5 ): int|false {
        $this->added[] = compact( 'object_type', 'object_id', 'object_url', 'priority' );
        return count( $this->added );
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
        lha_assert_same( true, is_string( $scanner ) && str_contains( $scanner, "\$this->queue->increment_attempts( (int) \$item['id'], \$this->last_item_error )" ) );
    }
);

lha_test(
    'shares scan notification completion and fully cleans uninstall state',
    static function(): void {
        $admin     = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-admin.php' );
        $cron      = file_get_contents( dirname( __DIR__ ) . '/linkvitals/includes/class-lha-cron.php' );
        $uninstall = file_get_contents( dirname( __DIR__ ) . '/linkvitals/uninstall.php' );

        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'LHA_Cron::begin_notification_tracking( true )' ) );
        lha_assert_same( true, is_string( $admin ) && str_contains( $admin, 'LHA_Cron::complete_notification_tracking()' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'add_option( self::NOTIFICATION_LOCK_OPTION' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'if ( wp_mail( $email, $subject, $body ) )' ) );
        lha_assert_same( true, is_string( $cron ) && str_contains( $cron, 'public static function reset_notification_tracking' ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_transient( 'lha_notice_check' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_transient( 'lha_pre_scan_broken_count' )" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "'_transient_lha_ai_'" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "'_transient_timeout_lha_ai_'" ) );
        lha_assert_same( true, is_string( $uninstall ) && str_contains( $uninstall, "delete_option( 'lha_content_scan_cursor' )" ) );
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
