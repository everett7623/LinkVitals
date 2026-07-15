<?php
/**
 * Security helper class
 *
 * Provides reusable permission checks, nonce verification,
 * input sanitization, and output escaping methods.
 *
 * @package LinkVitals
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LHA_Security
 *
 * Static helper methods for security operations across all admin/AJAX handlers.
 * Implements Requirements 21.1, 21.2, 21.3, 21.4, 21.6, 21.7.
 */
class LHA_Security {

    /**
     * Verify user has the manage_options capability.
     *
     * Used for all admin page renders, AJAX handlers, and REST operations.
     * Requirement 21.1.
     *
     * @return bool True if current user can manage options.
     */
    public static function check_permission(): bool {
        return current_user_can( 'manage_options' );
    }

    /**
     * Verify nonce for admin form submissions.
     *
     * Requirement 21.2.
     *
     * @param string $action      The nonce action name.
     * @param string $nonce_field The nonce field name in the request. Default '_lha_nonce'.
     * @return bool True if nonce is valid, false otherwise.
     */
    public static function verify_nonce( string $action, string $nonce_field = '_lha_nonce' ): bool {
        $nonce = isset( $_REQUEST[ $nonce_field ] )
            ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_field ] ) )
            : '';

        return (bool) wp_verify_nonce( $nonce, $action );
    }

    /**
     * Verify AJAX nonce.
     *
     * Uses check_ajax_referer with die=false so we can handle the failure ourselves.
     * Requirement 21.2.
     *
     * @param string $action The nonce action. Default 'lha_ajax_nonce'.
     * @return bool True if AJAX nonce is valid.
     */
    public static function verify_ajax_nonce( string $action = 'lha_ajax_nonce' ): bool {
        return (bool) check_ajax_referer( $action, 'nonce', false );
    }

    /**
     * Sanitize user input by dispatching to WordPress sanitization functions.
     *
     * Supported types:
     * - 'text'    => sanitize_text_field()
     * - 'url'     => sanitize_url()
     * - 'int'     => absint()
     * - 'key'     => sanitize_key()
     * - 'html'    => wp_kses_post()
     * - 'email'   => sanitize_email()
     * - 'file'    => sanitize_file_name()
     * - 'textarea'=> sanitize_textarea_field()
     *
     * Requirement 21.3.
     *
     * @param mixed  $value The value to sanitize.
     * @param string $type  The sanitization type. Default 'text'.
     * @return mixed The sanitized value.
     */
    public static function sanitize_input( mixed $value, string $type = 'text' ): mixed {
        return match ( $type ) {
            'text'     => sanitize_text_field( $value ),
            'url'      => sanitize_url( $value ),
            'int'      => absint( $value ),
            'key'      => sanitize_key( $value ),
            'html'     => wp_kses_post( $value ),
            'email'    => sanitize_email( $value ),
            'file'     => sanitize_file_name( $value ),
            'textarea' => sanitize_textarea_field( $value ),
            default    => sanitize_text_field( $value ),
        };
    }

    /**
     * Escape output by dispatching to WordPress escaping functions.
     *
     * Supported types:
     * - 'html' => esc_html()
     * - 'attr' => esc_attr()
     * - 'url'  => esc_url()
     * - 'js'   => esc_js()
     * - 'kses' => wp_kses_post()
     *
     * Requirement 21.4.
     *
     * @param string $value The value to escape.
     * @param string $type  The escaping context. Default 'html'.
     * @return string The escaped value.
     */
    public static function escape_output( string $value, string $type = 'html' ): string {
        return match ( $type ) {
            'html' => esc_html( $value ),
            'attr' => esc_attr( $value ),
            'url'  => esc_url( $value ),
            'js'   => esc_js( $value ),
            'kses' => wp_kses_post( $value ),
            default => esc_html( $value ),
        };
    }

    /**
     * Full security check for admin page actions (permission + nonce).
     *
     * Calls wp_die() with 403 on failure — used for non-AJAX admin form submissions.
     *
     * @param string $action      The nonce action name.
     * @param string $nonce_field The nonce field name in the request. Default '_lha_nonce'.
     * @return bool True if both checks pass. Dies on failure.
     */
    public static function admin_action_check( string $action, string $nonce_field = '_lha_nonce' ): bool {
        if ( ! self::check_permission() ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.', 'linkvitals' ),
                403
            );
        }

        if ( ! self::verify_nonce( $action, $nonce_field ) ) {
            wp_die(
                esc_html__( 'Security check failed. Please try again.', 'linkvitals' ),
                403
            );
        }

        return true;
    }

    /**
     * Full security check for AJAX requests (permission + nonce).
     *
     * Returns HTTP 403 via wp_send_json_error() on failure.
     * Requirements 21.6, 21.7.
     *
     * @param string $action The nonce action. Default 'lha_ajax_nonce'.
     * @return bool True if both checks pass. Sends JSON error and exits on failure.
     */
    public static function ajax_check( string $action = 'lha_ajax_nonce' ): bool {
        if ( ! self::check_permission() ) {
            wp_send_json_error(
                array( 'message' => __( 'Permission denied.', 'linkvitals' ) ),
                403
            );
            return false; // Unreachable, but satisfies static analysis.
        }

        if ( ! self::verify_ajax_nonce( $action ) ) {
            wp_send_json_error(
                array( 'message' => __( 'Security check failed.', 'linkvitals' ) ),
                403
            );
            return false; // Unreachable, but satisfies static analysis.
        }

        return true;
    }

    /**
     * Sanitize an array of values by their specified types.
     *
     * Useful for batch-sanitizing form submissions.
     *
     * @param array<string, mixed> $data  Associative array of field => value.
     * @param array<string, string> $types Associative array of field => sanitization type.
     * @return array<string, mixed> Sanitized values.
     */
    public static function sanitize_array( array $data, array $types ): array {
        $sanitized = array();

        foreach ( $types as $field => $type ) {
            if ( array_key_exists( $field, $data ) ) {
                $sanitized[ $field ] = self::sanitize_input( $data[ $field ], $type );
            }
        }

        return $sanitized;
    }
}
