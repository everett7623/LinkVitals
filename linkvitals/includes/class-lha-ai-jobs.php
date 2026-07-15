<?php
/**
 * Background job state for AI internal link suggestions.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_AI_Jobs {

    public const HOOK = 'lha_process_ai_orphan_job';

    private const ACTIVE_TTL = 15 * MINUTE_IN_SECONDS;
    private const RESULT_TTL = DAY_IN_SECONDS;

    /** Register the provider-neutral background worker. */
    public static function register(): void {
        add_action( self::HOOK, array( self::class, 'process' ), 10, 3 );
    }

    /**
     * Trigger or reuse a background suggestion job.
     *
     * @return array<string, mixed> Stable public job state.
     */
    public static function trigger( int $target_post_id, int $user_id ): array {
        if ( ! LHA_AI::is_available() ) {
            return self::make_state(
                '',
                'failed',
                $target_post_id,
                array( 'error' => __( 'AI not configured. Please set an API key in Settings.', 'linkvitals' ) )
            );
        }

        $post = get_post( $target_post_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return self::make_state(
                '',
                'failed',
                $target_post_id,
                array( 'error' => __( 'The target page is not a published public post.', 'linkvitals' ) )
            );
        }

        $active_job_id = sanitize_key( (string) get_transient( self::active_key( $target_post_id ) ) );
        if ( '' !== $active_job_id ) {
            $active_state = get_transient( self::state_key( $active_job_id ) );
            if ( is_array( $active_state ) && in_array( $active_state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
                self::index_job( $user_id, $target_post_id, $active_job_id );
                return self::public_state( $active_state );
            }
        }

        $job_id = sanitize_key( str_replace( '-', '', wp_generate_uuid4() ) );
        $state  = self::make_state(
            $job_id,
            'queued',
            $target_post_id,
            array(
                'message'  => __( 'AI suggestion job queued.', 'linkvitals' ),
                '_user_id' => $user_id,
            )
        );

        set_transient( self::state_key( $job_id ), $state, self::ACTIVE_TTL );
        set_transient( self::active_key( $target_post_id ), $job_id, self::ACTIVE_TTL );
        self::index_job( $user_id, $target_post_id, $job_id );

        $scheduled = wp_schedule_single_event(
            time() + 1,
            self::HOOK,
            array( $job_id, $target_post_id, $user_id ),
            true
        );

        if ( is_wp_error( $scheduled ) || false === $scheduled ) {
            delete_transient( self::state_key( $job_id ) );
            delete_transient( self::active_key( $target_post_id ) );
            self::remove_indexed_job( $user_id, $target_post_id );
            return self::make_state(
                $job_id,
                'failed',
                $target_post_id,
                array( 'error' => __( 'Could not schedule the AI suggestion job.', 'linkvitals' ) )
            );
        }

        return self::public_state( $state );
    }

    /** Process one queued job from WP-Cron. */
    public static function process( string $job_id, int $target_post_id, int $user_id ): void {
        $job_id = sanitize_key( $job_id );
        $state  = get_transient( self::state_key( $job_id ) );
        if ( ! is_array( $state ) || 'queued' !== ( $state['status'] ?? '' ) ) {
            return;
        }

        $state['status']     = 'running';
        $state['message']    = __( 'Generating internal link suggestions...', 'linkvitals' );
        $state['updated_at'] = time();
        set_transient( self::state_key( $job_id ), $state, self::ACTIVE_TTL );

        $previous_user_id = get_current_user_id();
        wp_set_current_user( $user_id );

        try {
            $result = ( new LHA_AI_Internal() )->generate( $target_post_id );
            if ( $result['success'] ) {
                $count = count( $result['suggestions'] ?? array() );
                $state = self::make_state(
                    $job_id,
                    'success',
                    $target_post_id,
                    array(
                        'suggestions' => $result['suggestions'] ?? array(),
                        'model'       => $result['model'] ?? '',
                        'tokens'      => (int) ( $result['tokens'] ?? 0 ),
                        'message'     => 0 === $count
                            ? __( 'No suitable source pages were found.', 'linkvitals' )
                            : sprintf(
                                /* translators: %d: number of internal link suggestions. */
                                __( 'Generated %d internal link suggestion(s).', 'linkvitals' ),
                                $count
                            ),
                        '_user_id'    => $user_id,
                    )
                );
            } else {
                $state = self::make_state(
                    $job_id,
                    'failed',
                    $target_post_id,
                    array(
                        'error'    => $result['error'] ?? __( 'AI request failed.', 'linkvitals' ),
                        '_user_id' => $user_id,
                    )
                );
            }
        } catch ( Throwable ) {
            $state = self::make_state(
                $job_id,
                'failed',
                $target_post_id,
                array(
                    'error'    => __( 'The AI suggestion job failed unexpectedly.', 'linkvitals' ),
                    '_user_id' => $user_id,
                )
            );
        } finally {
            wp_set_current_user( $previous_user_id );
        }

        set_transient( self::state_key( $job_id ), $state, self::RESULT_TTL );
        if ( $job_id === get_transient( self::active_key( $target_post_id ) ) ) {
            delete_transient( self::active_key( $target_post_id ) );
        }
    }

    /** Get one public job state for AJAX polling. */
    public static function get_status( string $job_id ): array {
        $job_id = sanitize_key( $job_id );
        $state  = get_transient( self::state_key( $job_id ) );
        if ( ! is_array( $state ) ) {
            return self::make_state(
                $job_id,
                'failed',
                0,
                array( 'error' => __( 'AI suggestion job expired or was not found.', 'linkvitals' ) )
            );
        }

        return self::public_state( $state );
    }

    /**
     * Read all page job IDs for the current table in one option lookup.
     *
     * @param array<int, int> $post_ids Visible orphaned post IDs.
     * @return array<int, string> Map of post ID to job ID.
     */
    public static function get_indexed_jobs( array $post_ids, int $user_id ): array {
        $index   = get_transient( self::index_key( $user_id ) );
        $allowed = array_fill_keys( array_map( 'absint', $post_ids ), true );
        $result  = array();

        foreach ( is_array( $index ) ? $index : array() as $post_id => $job_id ) {
            $post_id = absint( $post_id );
            if ( isset( $allowed[ $post_id ] ) ) {
                $result[ $post_id ] = sanitize_key( (string) $job_id );
            }
        }

        return $result;
    }

    /** Build a stable job state for all success and failure paths. */
    public static function make_state( string $job_id, string $status, int $target_post_id, array $overrides = array() ): array {
        return array_merge(
            array(
                'job_id'         => sanitize_key( $job_id ),
                'status'         => sanitize_key( $status ),
                'target_post_id' => $target_post_id,
                'suggestions'    => array(),
                'model'          => '',
                'tokens'         => 0,
                'message'        => '',
                'error'          => '',
                'updated_at'     => time(),
            ),
            $overrides
        );
    }

    private static function index_job( int $user_id, int $post_id, string $job_id ): void {
        $key   = self::index_key( $user_id );
        $index = get_transient( $key );
        $index = is_array( $index ) ? $index : array();
        $index[ $post_id ] = $job_id;

        if ( count( $index ) > 100 ) {
            $index = array_slice( $index, -100, null, true );
        }
        set_transient( $key, $index, self::RESULT_TTL );
    }

    private static function remove_indexed_job( int $user_id, int $post_id ): void {
        $key   = self::index_key( $user_id );
        $index = get_transient( $key );
        if ( ! is_array( $index ) || ! isset( $index[ $post_id ] ) ) {
            return;
        }

        unset( $index[ $post_id ] );
        set_transient( $key, $index, self::RESULT_TTL );
    }

    private static function public_state( array $state ): array {
        unset( $state['_user_id'] );
        return $state;
    }

    private static function state_key( string $job_id ): string {
        return 'lha_ai_job_' . $job_id;
    }

    private static function active_key( int $post_id ): string {
        return 'lha_ai_active_' . $post_id;
    }

    private static function index_key( int $user_id ): string {
        return 'lha_ai_jobs_user_' . $user_id;
    }
}
