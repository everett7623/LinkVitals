<?php
/**
 * Settings class
 *
 * Manages plugin settings page rendering and validation.
 * Uses a manual form with check_admin_referer for nonce verification
 * and LHA_Security for permission checks.
 *
 * @package LinkVitals
 * @since   1.0.0
 * @requires PHP 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LHA_Settings
 *
 * Renders the Settings tab and handles saving/validation.
 * Option name: 'lha_settings' (single array option).
 * Nonce action: 'lha_settings_nonce'.
 *
 * Implements Requirements 20.1, 20.2, 20.3, 20.4, 20.5, 20.6.
 */
class LHA_Settings {

    /**
     * WordPress option name storing all plugin settings.
     *
     * @var string
     */
    private string $option_name = 'lha_settings';

    /**
     * Nonce action used for the settings form.
     *
     * @var string
     */
    private string $nonce_action = 'lha_settings_nonce';

    /**
     * Normalize stored or submitted language codes without lowercasing locale.
     */
    private function normalize_language( mixed $language ): string {
        $language = (string) $language;
        $language = str_replace( '-', '_', $language );

        if ( '' === $language || 'auto' === strtolower( $language ) ) {
            return 'auto';
        }

        if ( 'zh_cn' === strtolower( $language ) ) {
            return 'zh_CN';
        }

        if ( 'en_us' === strtolower( $language ) ) {
            return 'en_US';
        }

        return 'auto';
    }

    /**
     * Handle settings form submission if POST data is present.
     *
     * Should be called early in the settings page render flow
     * (before any HTML output) so admin notices can be displayed.
     *
     * @return bool True if settings were saved, false otherwise.
     */
    public function save_settings(): bool {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            return false;
        }

        if ( ! isset( $_POST['lha_save_settings'] ) ) {
            return false;
        }

        // Verify nonce — dies with 403 on failure (Req 20.5, 21.2).
        check_admin_referer( $this->nonce_action, '_lha_settings_nonce' );

        // Check permission via LHA_Security (Req 20.5, 21.1).
        if ( ! LHA_Security::check_permission() ) {
            wp_die(
                esc_html__( 'You do not have permission to manage settings.', 'linkvitals' ),
                403
            );
        }

        $old_settings = get_option( $this->option_name, array() );

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized below field by field.
        $input = isset( $_POST['lha_settings'] ) && is_array( $_POST['lha_settings'] )
            ? wp_unslash( $_POST['lha_settings'] )
            : array();

        $sanitized = $this->validate_and_sanitize( $input );

        update_option( $this->option_name, $sanitized );

        // Trigger cron reschedule if auto_scan or scan_frequency changed (Req 10.3, 10.4).
        $old_auto_scan  = $old_settings['auto_scan'] ?? 0;
        $old_frequency  = $old_settings['scan_frequency'] ?? 'weekly';
        $new_auto_scan  = $sanitized['auto_scan'];
        $new_frequency  = $sanitized['scan_frequency'];

        if ( $old_auto_scan !== $new_auto_scan || $old_frequency !== $new_frequency ) {
            $cron = new LHA_Cron();
            $cron->schedule_events( $sanitized );
        }

        // Add success notice.
        add_settings_error(
            'lha_settings',
            'lha_settings_saved',
            __( 'Settings saved.', 'linkvitals' ),
            'success'
        );

        return true;
    }

    /**
     * Validate and sanitize input values.
     *
     * Enforces ranges: batch_size [1,100], http_timeout [1,30], max_redirects [1,10].
     * Values outside ranges are clamped to the nearest boundary (Req 20.6).
     *
     * @param array $input Raw input array from the form.
     * @return array Sanitized and validated settings array.
     */
    private function validate_and_sanitize( array $input ): array {
        $sanitized = array();

        // --- Scanning section ---
        $sanitized['auto_scan']      = ! empty( $input['auto_scan'] ) ? 1 : 0;
        $sanitized['scan_frequency'] = in_array( $input['scan_frequency'] ?? '', array( 'daily', 'weekly', 'monthly' ), true )
            ? sanitize_key( $input['scan_frequency'] )
            : 'weekly';
        $sanitized['batch_size']     = isset( $input['batch_size'] )
            ? min( 100, max( 1, absint( $input['batch_size'] ) ) )
            : 20;

        // --- Checking section ---
        $sanitized['http_timeout']   = isset( $input['http_timeout'] )
            ? min( 30, max( 1, absint( $input['http_timeout'] ) ) )
            : 8;
        $sanitized['max_redirects']  = isset( $input['max_redirects'] )
            ? min( 10, max( 1, absint( $input['max_redirects'] ) ) )
            : 5;
        $sanitized['check_external'] = ! empty( $input['check_external'] ) ? 1 : 0;
        $sanitized['check_images']   = ! empty( $input['check_images'] ) ? 1 : 0;
        $sanitized['check_media']    = ! empty( $input['check_media'] ) ? 1 : 0;
        $sanitized['check_anchors']  = ! empty( $input['check_anchors'] ) ? 1 : 0;
        $sanitized['check_nofollow'] = ! empty( $input['check_nofollow'] ) ? 1 : 0;

        // --- HTTP Proxy section ---
        $sanitized['proxy_enabled'] = ! empty( $input['proxy_enabled'] ) ? 1 : 0;
        $sanitized['proxy_host']    = isset( $input['proxy_host'] ) ? sanitize_text_field( $input['proxy_host'] ) : '';
        $sanitized['proxy_port']    = isset( $input['proxy_port'] ) ? min( 65535, max( 1, absint( $input['proxy_port'] ) ) ) : 0;
        $sanitized['proxy_type']    = in_array( $input['proxy_type'] ?? '', array( 'http', 'socks5' ), true ) ? sanitize_key( $input['proxy_type'] ) : 'http';

        // --- Ignore Lists section ---
        $sanitized['ignore_domains']  = isset( $input['ignore_domains'] )
            ? sanitize_textarea_field( $input['ignore_domains'] )
            : '';
        $sanitized['ignore_patterns'] = isset( $input['ignore_patterns'] )
            ? sanitize_textarea_field( $input['ignore_patterns'] )
            : '';

        // --- Notifications section ---
        $sanitized['email_notifications']    = ! empty( $input['email_notifications'] ) ? 1 : 0;
        $sanitized['notification_email']     = isset( $input['notification_email'] )
            ? sanitize_email( $input['notification_email'] )
            : '';

        // --- Data section ---
        $sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? 1 : 0;
        $sanitized['repair_history_retention_days'] = isset( $input['repair_history_retention_days'] )
            ? min( 3650, max( 0, absint( $input['repair_history_retention_days'] ) ) )
            : 180;

        // --- Language section ---
        $sanitized['language'] = $this->normalize_language( $input['language'] ?? 'auto' );
        $sanitized['language_manually_selected'] = 1;

        return $sanitized;
    }

    /**
     * Render the settings page.
     *
     * Outputs the form with sections: Scanning, Checking, Ignore Lists, Notifications, Data.
     * All output values are escaped with esc_attr/esc_html/esc_textarea (Req 21.4).
     * Uses WordPress native form styling (form-table class).
     */
    public function render_page(): void {
        // Process save before rendering so notices appear.
        $this->save_settings();

        $settings         = get_option( $this->option_name, array() );
        $current_language = $this->normalize_language( $settings['language'] ?? 'auto' );

        ?>
        <div id="lha-settings">
            <?php settings_errors( 'lha_settings' ); ?>

            <form method="post" action="">
                <?php wp_nonce_field( $this->nonce_action, '_lha_settings_nonce' ); ?>

                <!-- Scanning Section -->
                <h2><?php esc_html_e( 'Scanning', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Automatic Scanning', 'linkvitals' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="lha_settings[auto_scan]" value="1" <?php checked( $settings['auto_scan'] ?? 0, 1 ); ?> />
                                <?php esc_html_e( 'Enable scheduled automatic scanning', 'linkvitals' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-scan-frequency"><?php esc_html_e( 'Scan Frequency', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <select name="lha_settings[scan_frequency]" id="lha-scan-frequency">
                                <option value="daily" <?php selected( $settings['scan_frequency'] ?? 'weekly', 'daily' ); ?>><?php esc_html_e( 'Daily', 'linkvitals' ); ?></option>
                                <option value="weekly" <?php selected( $settings['scan_frequency'] ?? 'weekly', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'linkvitals' ); ?></option>
                                <option value="monthly" <?php selected( $settings['scan_frequency'] ?? 'weekly', 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'linkvitals' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-batch-size"><?php esc_html_e( 'Batch Size', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="lha_settings[batch_size]" id="lha-batch-size" value="<?php echo esc_attr( $settings['batch_size'] ?? 20 ); ?>" min="1" max="100" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Number of items to process per batch (1-100).', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Checking Section -->
                <h2><?php esc_html_e( 'Checking', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="lha-http-timeout"><?php esc_html_e( 'HTTP Timeout (seconds)', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="lha_settings[http_timeout]" id="lha-http-timeout" value="<?php echo esc_attr( $settings['http_timeout'] ?? 8 ); ?>" min="1" max="30" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Seconds to wait for a response (1-30).', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-max-redirects"><?php esc_html_e( 'Max Redirects', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="lha_settings[max_redirects]" id="lha-max-redirects" value="<?php echo esc_attr( $settings['max_redirects'] ?? 5 ); ?>" min="1" max="10" class="small-text" />
                            <p class="description"><?php esc_html_e( 'Maximum number of redirects to follow (1-10).', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Check Options', 'linkvitals' ); ?></th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><?php esc_html_e( 'Check Options', 'linkvitals' ); ?></legend>
                                <label>
                                    <input type="checkbox" name="lha_settings[check_external]" value="1" <?php checked( $settings['check_external'] ?? 1, 1 ); ?> />
                                    <?php esc_html_e( 'Check external links', 'linkvitals' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="lha_settings[check_images]" value="1" <?php checked( $settings['check_images'] ?? 1, 1 ); ?> />
                                    <?php esc_html_e( 'Check image links', 'linkvitals' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="lha_settings[check_media]" value="1" <?php checked( $settings['check_media'] ?? 1, 1 ); ?> />
                                    <?php esc_html_e( 'Check media files (video/audio)', 'linkvitals' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="lha_settings[check_anchors]" value="1" <?php checked( $settings['check_anchors'] ?? 0, 1 ); ?> />
                                    <?php esc_html_e( 'Check anchor links (#fragments)', 'linkvitals' ); ?>
                                </label><br>
                                <label>
                                    <input type="checkbox" name="lha_settings[check_nofollow]" value="1" <?php checked( $settings['check_nofollow'] ?? 0, 1 ); ?> />
                                    <?php esc_html_e( 'Check nofollow/sponsored attributes', 'linkvitals' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- HTTP Proxy Section -->
                <h2><?php esc_html_e( 'HTTP Proxy', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable Proxy', 'linkvitals' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="lha_settings[proxy_enabled]" value="1" <?php checked( $settings['proxy_enabled'] ?? 0, 1 ); ?> />
                                <?php esc_html_e( 'Use HTTP proxy for external link checking', 'linkvitals' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Enable if your server needs a proxy to access external websites (e.g., behind GFW).', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-proxy-host"><?php esc_html_e( 'Proxy Host', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="text" name="lha_settings[proxy_host]" id="lha-proxy-host" value="<?php echo esc_attr( $settings['proxy_host'] ?? '' ); ?>" class="regular-text" placeholder="127.0.0.1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-proxy-port"><?php esc_html_e( 'Proxy Port', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="lha_settings[proxy_port]" id="lha-proxy-port" value="<?php echo esc_attr( $settings['proxy_port'] ?? '' ); ?>" min="1" max="65535" class="small-text" placeholder="7890" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-proxy-type"><?php esc_html_e( 'Proxy Type', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <select name="lha_settings[proxy_type]" id="lha-proxy-type">
                                <option value="http" <?php selected( $settings['proxy_type'] ?? 'http', 'http' ); ?>><?php esc_html_e( 'HTTP / HTTPS', 'linkvitals' ); ?></option>
                                <option value="socks5" <?php selected( $settings['proxy_type'] ?? 'http', 'socks5' ); ?>><?php esc_html_e( 'SOCKS5', 'linkvitals' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <!-- Ignore Lists Section -->
                <h2><?php esc_html_e( 'Ignore Lists', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="lha-ignore-domains"><?php esc_html_e( 'Ignore Domains', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <textarea name="lha_settings[ignore_domains]" id="lha-ignore-domains" rows="5" class="large-text code"><?php echo esc_textarea( $settings['ignore_domains'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One domain per line. Use *.example.com for wildcard subdomains.', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-ignore-patterns"><?php esc_html_e( 'Ignore URL Patterns', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <textarea name="lha_settings[ignore_patterns]" id="lha-ignore-patterns" rows="5" class="large-text code"><?php echo esc_textarea( $settings['ignore_patterns'] ?? '' ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'One pattern per line. Supports * wildcard (e.g., https://example.com/private/*).', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Notifications Section -->
                <h2><?php esc_html_e( 'Notifications', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Email Notifications', 'linkvitals' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="lha_settings[email_notifications]" value="1" <?php checked( $settings['email_notifications'] ?? 0, 1 ); ?> />
                                <?php esc_html_e( 'Send email when new broken links are found', 'linkvitals' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="lha-notification-email"><?php esc_html_e( 'Notification Email', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="email" name="lha_settings[notification_email]" id="lha-notification-email" value="<?php echo esc_attr( $settings['notification_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                            <p class="description"><?php esc_html_e( 'Leave empty to use the site administrator email.', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Language Section -->
                <h2><?php esc_html_e( 'Language', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="lha-language"><?php esc_html_e( 'Interface Language', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <select name="lha_settings[language]" id="lha-language">
                                <option value="auto" <?php selected( $current_language, 'auto' ); ?>><?php esc_html_e( 'Auto (site language)', 'linkvitals' ); ?></option>
                                <option value="en_US" <?php selected( $current_language, 'en_US' ); ?>><?php esc_html_e( 'English', 'linkvitals' ); ?></option>
                                <option value="zh_CN" <?php selected( $current_language, 'zh_CN' ); ?>><?php esc_html_e( 'Chinese (Simplified)', 'linkvitals' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Auto follows the WordPress site language.', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Data Section -->
                <h2><?php esc_html_e( 'Data', 'linkvitals' ); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="lha-repair-history-retention-days"><?php esc_html_e( 'Repair History Retention', 'linkvitals' ); ?></label>
                        </th>
                        <td>
                            <input type="number" name="lha_settings[repair_history_retention_days]" id="lha-repair-history-retention-days" value="<?php echo esc_attr( $settings['repair_history_retention_days'] ?? 180 ); ?>" min="0" max="3650" class="small-text" />
                            <?php esc_html_e( 'days', 'linkvitals' ); ?>
                            <p class="description"><?php esc_html_e( 'Keep rolled-back repair records for this many days. Enter 0 to keep them forever. Active rollback records are never purged by this maintenance action.', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Uninstall', 'linkvitals' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="lha_settings[delete_data_on_uninstall]" value="1" <?php checked( $settings['delete_data_on_uninstall'] ?? 0, 1 ); ?> />
                                <?php esc_html_e( 'Delete all plugin data when plugin is uninstalled', 'linkvitals' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Warning: This will permanently delete all scan data, logs, and settings.', 'linkvitals' ); ?></p>
                        </td>
                    </tr>
                </table>

                <input type="hidden" name="lha_save_settings" value="1" />
                <?php submit_button( __( 'Save Settings', 'linkvitals' ) ); ?>
            </form>
        </div>
        <?php
    }
}
