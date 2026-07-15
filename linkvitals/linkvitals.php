<?php
/**
 * Plugin Name: LinkVitals – Link Health & SEO Auditor
 * Plugin URI: https://github.com/everett7623/LinkVitals
 * Description: Comprehensive link health audit plugin for WordPress. Detects broken links, redirects, timeouts, SSL errors, orphaned pages, and SEO link risks across posts, pages, menus, and custom post types.
 * Version: 0.3.3
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author: everettlabs
 * Author URI: https://github.com/everett7623
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: linkvitals
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'LHA_VERSION', '0.3.3' );
define( 'LHA_PLUGIN_FILE', __FILE__ );
define( 'LHA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LHA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LHA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Include required files
require_once LHA_PLUGIN_DIR . 'includes/class-lha-activator.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-deactivator.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-db.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-security.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-scanner.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-link-extractor.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-link-checker.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-internal-analyzer.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-admin.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-settings.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-list-table.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-queue.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-cron.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-exporter.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-repair.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-image-repair.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-logger.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-anchor-checker.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-seo-checker.php';
require_once LHA_PLUGIN_DIR . 'includes/class-lha-ai.php';

// Activation and deactivation hooks
register_activation_hook( __FILE__, array( 'LHA_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'LHA_Deactivator', 'deactivate' ) );

/**
 * LinkVitals main plugin class - singleton pattern
 */
final class LinkVitals_Plugin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        add_action( 'init', array( $this, 'load_textdomain' ) );
        add_action( 'admin_init', array( $this, 'check_version' ) );

        // Add settings link on plugins page
        add_filter( 'plugin_action_links_' . LHA_PLUGIN_BASENAME, array( $this, 'add_action_links' ) );

        // Initialize admin
        if ( is_admin() ) {
            new LHA_Admin();
            new LHA_Settings();
        }

        // Initialize cron
        new LHA_Cron();
    }

    /**
     * Add quick links on the Plugins page
     */
    public function add_action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'tools.php?page=lha-dashboard' ) ),
            esc_html__( 'Dashboard', 'linkvitals' )
        );
        $settings_link2 = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'tools.php?page=lha-dashboard&tab=settings' ) ),
            esc_html__( 'Settings', 'linkvitals' )
        );
        array_unshift( $links, $settings_link, $settings_link2 );
        return $links;
    }

    /**
     * Normalize stored language values without lowercasing locale case.
     */
    private function normalize_language_setting( mixed $language ): string {
        $language = str_replace( '-', '_', (string) $language );
        $normalized = strtolower( $language );

        if ( '' === $normalized || 'auto' === $normalized ) {
            return 'auto';
        }

        if ( 'zh_cn' === $normalized ) {
            return 'zh_CN';
        }

        if ( 'en_us' === $normalized ) {
            return 'en_US';
        }

        return 'auto';
    }

    /**
     * Move old saved language values to Auto unless a future manual choice is marked.
     */
    private function migrate_legacy_language_setting( array $settings ): array {
        if ( array_key_exists( 'language_manually_selected', $settings ) ) {
            return $settings;
        }

        $settings['language'] = 'auto';
        $settings['language_manually_selected'] = 0;
        update_option( 'lha_settings', $settings );

        return $settings;
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain(): void {
        $settings = get_option( 'lha_settings', false );
        if ( is_array( $settings ) ) {
            $settings = $this->migrate_legacy_language_setting( $settings );
        }

        $stored_language     = is_array( $settings ) ? $this->normalize_language_setting( $settings['language'] ?? 'auto' ) : 'auto';
        $normalized_language = strtolower( $stored_language );

        if ( '' === $normalized_language || 'auto' === $normalized_language ) {
            $site_locale         = strtolower( str_replace( '-', '_', get_locale() ) );
            $normalized_language = str_starts_with( $site_locale, 'zh' ) ? 'zh_cn' : 'en_us';
        }

        $selected_locale = 'zh_cn' === $normalized_language ? 'zh_CN' : 'en_US';
        add_filter(
            'plugin_locale',
            static function( string $locale, string $domain ) use ( $selected_locale ): string {
                return 'linkvitals' === $domain ? $selected_locale : $locale;
            },
            10,
            2
        );

        unload_textdomain( 'linkvitals' );

        if ( 'zh_cn' === $normalized_language ) {
            $mofile = LHA_PLUGIN_DIR . 'languages/linkvitals-zh_CN.mo';
            if ( file_exists( $mofile ) ) {
                load_textdomain( 'linkvitals', $mofile );
            }
            return;
        }
    }

    /**
     * Check if plugin version has changed and run upgrade routines
     */
    public function check_version(): void {
        $current_version = get_option( 'lha_version', '0' );
        if ( version_compare( $current_version, LHA_VERSION, '<' ) ) {
            LHA_Activator::activate();
            $this->run_upgrade_routines( $current_version );
            update_option( 'lha_version', LHA_VERSION );
        }
    }

    /**
     * Run version-specific upgrade routines
     *
     * @param string $old_version The previous plugin version.
     */
    private function run_upgrade_routines( string $old_version ): void {
        // Recheck all links when upgrading to fix status classification changes
        // (e.g. corrected redirect detection). Anything below the current version
        // predates the latest classification logic and should be re-evaluated.
        if ( version_compare( $old_version, LHA_VERSION, '<' ) ) {
            $this->recheck_all_links();
        }
    }

    /**
     * Queue all links for rechecking via the existing scan pipeline.
     *
     * Rather than performing blocking HTTP requests inline (which would stall
     * admin_init and risk timeouts on large sites), this resets every
     * non-ignored link to 'pending'. The background cron pipeline
     * (lha_process_queue -> LHA_DB::get_unchecked_links -> check_links_batch)
     * then rechecks them in batches, correctly applying ignore lists, per-type
     * settings, non-HTTP skipping, and the atomic check_count increment.
     */
    private function recheck_all_links(): void {
        $reset = LHA_DB::reset_links_for_recheck();

        if ( $reset > 0 ) {
            // Put the scanner into a running state so the queue cron drains the
            // links that were just reset to 'pending'.
            update_option( 'lha_scan_status', 'running' );
        }
    }
}

// Initialize plugin
add_action( 'plugins_loaded', function() {
    LinkVitals_Plugin::get_instance();
});
