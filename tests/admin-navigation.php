<?php
/**
 * 后台入口与批量动作的无依赖行为回归测试。
 */

define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'LHA_PLUGIN_URL', 'https://example.test/wp-content/plugins/linkvitals/' );
define( 'LHA_VERSION', 'test' );

$GLOBALS['nav_actions'] = array();
$GLOBALS['nav_assets'] = array();
$GLOBALS['nav_permission'] = true;
$GLOBALS['nav_tables'] = array();
$GLOBALS['nav_ignored'] = array();
$GLOBALS['nav_queries'] = array();
$assertions = 0;

function check( bool $condition, string $message ): void {
    $GLOBALS['assertions']++;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

class NavigationRedirect extends RuntimeException {
    public function __construct( public string $url, public int $status ) {
        parent::__construct( '已拦截跳转，避免真实 exit。' );
    }
}
class NavigationDenied extends RuntimeException {}

function wp_safe_redirect( string $url, int $status = 302 ): void {
    check( ! headers_sent() && '' === ( ob_get_contents() ?: '' ), '跳转必须发生在后台页头输出前。' );
    throw new NavigationRedirect( $url, $status );
}
function redirect_result( callable $callback ): NavigationRedirect {
    try {
        $callback();
    } catch ( NavigationRedirect $redirect ) {
        return $redirect;
    }
    throw new RuntimeException( '预期跳转没有发生。' );
}
function denied_result( callable $callback ): void {
    try {
        $callback();
    } catch ( NavigationDenied $denied ) {
        check( 'Permission denied.' === $denied->getMessage(), '拒绝未授权后台访问。' );
        return;
    }
    throw new RuntimeException( '未授权访问未被拒绝。' );
}
function add_action( string $hook, callable $callback ): void {
    $GLOBALS['nav_actions'][ $hook ][] = $callback;
}
function run_hook( string $hook ): void {
    foreach ( $GLOBALS['nav_actions'][ $hook ] ?? array() as $callback ) {
        $callback();
    }
}
function add_menu_page( ...$args ): string {
    $GLOBALS['nav_menu'] = $args;
    return 'toplevel_page_lha-dashboard';
}
function current_user_can( string $capability ): bool {
    check( 'manage_options' === $capability, '后台入口必须检查 manage_options。' );
    return $GLOBALS['nav_permission'];
}
function wp_die( string $message ): void { throw new NavigationDenied( $message ); }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES ); }
function esc_attr( string $text ): string { return esc_html( $text ); }
function esc_url( string $text ): string { return esc_html( $text ); }
function esc_html_e( string $text, string $domain = '' ): void { echo esc_html( $text ); }
function admin_url( string $path ): string { return 'https://example.test/wp-admin/' . $path; }
function wp_unslash( mixed $value ): mixed { return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( $value ); }
function slash_request( mixed $value ): mixed { return is_array( $value ) ? array_map( 'slash_request', $value ) : addslashes( $value ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function absint( mixed $value ): int { return abs( (int) $value ); }
function wp_create_nonce( string $action ): string { return 'nonce-' . $action; }
function wp_verify_nonce( string $nonce, string $action ): bool { return $nonce === wp_create_nonce( $action ); }
function add_query_arg( array $query, string $url ): string { return $url . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); }
function get_option( string $name, mixed $default = false ): mixed { return $default; }
function wp_enqueue_style( ...$args ): void { $GLOBALS['nav_assets'][] = $args; }
function wp_enqueue_script( ...$args ): void { $GLOBALS['nav_assets'][] = $args; }
function wp_localize_script( ...$args ): void { $GLOBALS['nav_localized'] = $args; }

// 只替代 WordPress 的表格框架与数据库边界；插件的路由和动作使用真实实现。
class WP_List_Table {
    public array $items = array();
    protected array $_column_headers = array();
    public function __construct( array $args ) { $GLOBALS['nav_tables'][] = $this; }
    public function current_action(): string|false {
        foreach ( array( 'action', 'action2' ) as $key ) {
            if ( ! empty( $_REQUEST[ $key ] ) && '-1' !== $_REQUEST[ $key ] ) {
                return $_REQUEST[ $key ];
            }
        }
        return false;
    }
    public function get_pagenum(): int { return 1; }
    public function set_pagination_args( array $args ): void {}
    public function search_box( string $text, string $id ): void {}
    public function display(): void { $GLOBALS['nav_displayed'] = $this; }
}
class LHA_DB {
    public static function get_links( array $args ): array {
        $GLOBALS['nav_queries'][] = $GLOBALS['nav_ignored'];
        return array( 'items' => array(), 'total' => 0 );
    }
    public static function get_occurrence_summaries( array $ids ): array { return array(); }
    public static function get_report_filter_keys(): array { return array( '', 'issues', 'ignored' ); }
    public static function sanitize_report_filter_key( string $key ): string { return $key; }
    public static function get_link( int $id ): array { return array( 'url' => 'https://example.test/' . $id ); }
    public static function ignore_link( int $id, string $reason ): void { $GLOBALS['nav_ignored'][] = $id; }
}
class LHA_Link_Checker {}
class LHA_Logger { public static function log( ...$args ): void {} }

require_once ABSPATH . 'src/includes/class-lha-security.php';
require_once ABSPATH . 'src/includes/class-lha-list-table.php';
require_once ABSPATH . 'src/includes/class-lha-admin.php';

function request_data( array $query, string $method = 'GET' ): void {
    $_GET = slash_request( $query );
    $_POST = array();
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_METHOD'] = $method;
}
function redirect_query( NavigationRedirect $redirect ): array {
    check( admin_url( 'admin.php' ) === strtok( $redirect->url, '?' ), '跳转必须使用新的一级菜单入口。' );
    check( null === parse_url( $redirect->url, PHP_URL_FRAGMENT ), '查询内容不能成为 URL 片段。' );
    parse_str( parse_url( $redirect->url, PHP_URL_QUERY ), $query );
    return $query;
}

ob_start();
$admin = new LHA_Admin();
$admin->enqueue_assets( 'toplevel_page_lha-dashboard' );
check( array() === $GLOBALS['nav_assets'], '注册菜单前不能加载资源。' );
run_hook( 'admin_menu' );
$menu = $GLOBALS['nav_menu'];
check( array( 'LinkVitals', 'LinkVitals', 'manage_options', 'lha-dashboard' ) === array_slice( $menu, 0, 4 ), '注册正确的一级菜单与权限。' );
check( array( $admin, 'render_page' ) === $menu[4] && 'dashicons-admin-links' === $menu[5], '菜单绑定正确的渲染器和图标。' );
$load_hook = 'load-toplevel_page_lha-dashboard';
check( array( array( $admin, 'load_page' ) ) === $GLOBALS['nav_actions'][ $load_hook ], '必须在菜单返回的 load hook 处理批量动作。' );
foreach ( array( 'dashboard', 'tools_page_lha-dashboard', 'toplevel_page_other-plugin' ) as $hook ) {
    $admin->enqueue_assets( $hook );
}
check( array() === $GLOBALS['nav_assets'], '其他后台页面和旧入口不能加载插件资源。' );
$admin->enqueue_assets( 'toplevel_page_lha-dashboard' );
check( array( 'lha-admin-css', 'lha-admin-js', 'lha-image-repair-js', 'lha-ai-admin-js' ) === array_column( $GLOBALS['nav_assets'], 0 ), '新入口加载完整后台资源。' );
check( 'lhaAdmin' === $GLOBALS['nav_localized'][1], '新入口必须提供 AJAX 配置。' );

$pagenow = 'tools.php';
$legacy = array( 'page' => 'lha-dashboard', 'tab' => 'report', 's' => "含空格 & # + % \' \\", 'link_ids' => array( '7', '11' ), '_wpnonce' => 'nonce-bulk-links' );
foreach ( array( 'GET' => 302, 'POST' => 307 ) as $method => $status ) {
    request_data( $legacy, $method );
    $_POST = array( '_wpnonce' => 'nonce-form', 'new_url' => 'https://example.test/?a=1&b=2' );
    $body = $_POST;
    $redirect = redirect_result( fn() => run_hook( 'admin_init' ) );
    check( $status === $redirect->status, 'GET 使用 302，POST 使用保留请求体的 307。' );
    check( $legacy === redirect_query( $redirect ), '旧入口应无损保留特殊字符、数组和 nonce。' );
    check( $body === $_POST, '旧入口不能修改 POST 请求体。' );
}
foreach ( array(
    array( 'admin.php', 'lha-dashboard', true ),
    array( 'index.php', 'lha-dashboard', true ),
    array( 'tools.php', 'lha-dashboard-other', true ),
    array( 'tools.php', array( 'lha-dashboard' ), true ),
    array( 'tools.php', 'lha-dashboard', false ),
) as [ $pagenow, $page, $permission ] ) {
    request_data( array( 'page' => $page ) );
    $GLOBALS['nav_permission'] = $permission;
    run_hook( 'admin_init' );
}
request_data( array() );
run_hook( 'admin_init' );
$GLOBALS['nav_permission'] = true;
check( '' === ob_get_contents(), '非目标页面与无权限旧入口不能输出内容。' );

foreach ( array( 'replace_url', 'unlink' ) as $action ) {
    request_data( array( 'tab' => 'report', 'action' => $action, 'link_ids' => array( '-7', '0', '11' ), '_wpnonce' => wp_create_nonce( 'bulk-links' ) ) );
    $redirect = redirect_result( fn() => run_hook( $load_hook ) );
    $query = redirect_query( $redirect );
    check( 302 === $redirect->status && $action === $query['lha_bulk_action'], '批量动作在 load hook 跳转到正确确认页。' );
    check( array( '7', '11' ) === $query['link_ids'] && wp_create_nonce( 'lha_bulk_confirm_nonce' ) === $query['_wpnonce'], '确认跳转清理 ID 并生成专用 nonce。' );
}

$GLOBALS['nav_permission'] = false;
denied_result( fn() => run_hook( $load_hook ) );
denied_result( fn() => $admin->render_page() );
( new LHA_List_Table() )->process_bulk_action();
$GLOBALS['nav_permission'] = true;
$table_count = count( $GLOBALS['nav_tables'] );
foreach ( array( array(), array( 'tab' => 'settings' ), array( 'tab' => 'report', 'lha_bulk_action' => 'unlink' ) ) as $query ) {
    request_data( $query );
    run_hook( $load_hook );
}
check( $table_count === count( $GLOBALS['nav_tables'] ), '其他标签和确认页不能重复处理报告动作。' );
foreach ( array( array( 'link_ids' => array( '7' ) ), array( '_wpnonce' => 'invalid', 'link_ids' => array( '7' ) ), array( '_wpnonce' => wp_create_nonce( 'bulk-links' ) ) ) as $query ) {
    request_data( array_merge( array( 'tab' => 'report', 'action' => 'unlink' ), $query ) );
    run_hook( $load_hook );
}

// 即使请求仍含动作，prepare_items 也只能读取数据，不能在渲染阶段再次跳转。
request_data( array( 'tab' => 'report', 'action' => 'replace_url', 'link_ids' => array( '7' ), '_wpnonce' => wp_create_nonce( 'bulk-links' ) ) );
( new LHA_List_Table() )->prepare_items();
request_data( array( 'tab' => 'report', 'action' => '-1', 'action2' => 'ignore', 'link_ids' => array( '7', '11' ), '_wpnonce' => wp_create_nonce( 'bulk-links' ) ) );
run_hook( $load_hook );
check( array( 7, 11 ) === $GLOBALS['nav_ignored'], '底部批量操作在页头之前处理。' );
$prepared_table = end( $GLOBALS['nav_tables'] );
$table_count = count( $GLOBALS['nav_tables'] );
$admin->render_page();
check( $table_count === count( $GLOBALS['nav_tables'] ) && $prepared_table === $GLOBALS['nav_displayed'], '渲染必须复用 load hook 创建的真实表格实例。' );
check( array( 7, 11 ) === end( $GLOBALS['nav_queries'] ), '报告查询立即读取批量操作后的数据。' );
check( array( 7, 11 ) === $GLOBALS['nav_ignored'], '渲染不能重复执行批量操作。' );
$html = ob_get_clean();
check( str_contains( $html, 'admin.php?page=lha-dashboard' ) && ! str_contains( $html, 'tools.php' ), '渲染的导航和筛选器使用新入口。' );

echo "PASS: {$assertions} admin navigation assertions.\n";
