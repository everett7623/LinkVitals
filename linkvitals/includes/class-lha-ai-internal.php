<?php
/**
 * AI-assisted internal link suggestion service.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_AI_Internal {

    private const QUERY_LIMIT        = 100;
    private const CANDIDATE_LIMIT    = 10;
    private const SUGGESTION_LIMIT   = 3;
    private const TARGET_TEXT_LIMIT  = 1200;
    private const SOURCE_TEXT_LIMIT  = 700;

    /**
     * Generate validated suggestions for one published target post.
     *
     * @return array{success: bool, suggestions?: array, model?: string, tokens?: int, error?: string}
     */
    public function generate( int $target_post_id ): array {
        $target = get_post( $target_post_id );
        if ( ! $target || 'publish' !== $target->post_status || 'attachment' === $target->post_type ) {
            return array( 'success' => false, 'error' => __( 'The target page is not a published public post.', 'linkvitals' ) );
        }

        $post_type = get_post_type_object( $target->post_type );
        if ( ! $post_type || ! $post_type->public ) {
            return array( 'success' => false, 'error' => __( 'The target page is not a published public post.', 'linkvitals' ) );
        }

        $candidates = $this->build_candidates( $target );
        if ( empty( $candidates ) ) {
            return array(
                'success'     => true,
                'suggestions' => array(),
                'model'       => '',
                'tokens'      => 0,
            );
        }

        $target_context = array(
            'title'   => self::truncate( self::clean_text( $target->post_title ), 200 ),
            'excerpt' => self::truncate( $this->get_post_text( $target ), self::TARGET_TEXT_LIMIT ),
        );
        $ai_candidates = array_map(
            static function( array $candidate ): array {
                return array(
                    'source_post_id' => $candidate['source_post_id'],
                    'title'          => $candidate['title'],
                    'excerpt'        => $candidate['excerpt'],
                );
            },
            $candidates
        );

        $ai_result = ( new LHA_AI() )->analyze_internal_links( $target_context, $ai_candidates );
        if ( ! $ai_result['success'] ) {
            return $ai_result;
        }

        $raw_suggestions = $ai_result['suggestions'] ?? array();
        $suggestions     = self::normalize_suggestions( $raw_suggestions, $candidates );
        if ( ! empty( $raw_suggestions ) && empty( $suggestions ) ) {
            return array(
                'success' => false,
                'error'   => __( 'AI suggestions did not match the approved candidate pages.', 'linkvitals' ),
            );
        }

        return array(
            'success'     => true,
            'suggestions' => $suggestions,
            'model'       => $ai_result['model'] ?? '',
            'tokens'      => (int) ( $ai_result['tokens'] ?? 0 ),
        );
    }

    /**
     * Build a bounded, preloaded candidate set without per-row database calls.
     *
     * @return array<int, array<string, mixed>>
     */
    private function build_candidates( WP_Post $target ): array {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        unset( $post_types['attachment'] );

        $query = new WP_Query(
            array(
                'post_type'              => array_values( $post_types ),
                'post_status'            => 'publish',
                'posts_per_page'         => self::QUERY_LIMIT,
                'post__not_in'           => array( $target->ID ),
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => true,
            )
        );

        $existing_sources = array_fill_keys( $this->get_existing_source_ids( get_permalink( $target ) ), true );
        $ranked            = array();
        $target_text       = $this->get_post_text( $target );

        foreach ( $query->posts as $candidate ) {
            if ( isset( $existing_sources[ (int) $candidate->ID ] ) ) {
                continue;
            }

            $candidate_text = $this->get_post_text( $candidate );
            $ranked[] = array(
                'post'    => $candidate,
                'excerpt' => self::truncate( $candidate_text, self::SOURCE_TEXT_LIMIT ),
                'score'   => $this->score_candidate( $target->post_title, $target_text, $candidate->post_title, $candidate_text ),
            );
        }

        usort(
            $ranked,
            static function( array $left, array $right ): int {
                $score_order = $right['score'] <=> $left['score'];
                if ( 0 !== $score_order ) {
                    return $score_order;
                }
                return strcmp( $right['post']->post_modified_gmt, $left['post']->post_modified_gmt );
            }
        );

        $candidates = array();
        foreach ( $ranked as $item ) {
            $post     = $item['post'];
            $edit_url = get_edit_post_link( $post, 'raw' );
            if ( ! $edit_url ) {
                continue;
            }

            $candidates[] = array(
                'source_post_id' => (int) $post->ID,
                'title'          => self::truncate( self::clean_text( $post->post_title ), 200 ),
                'excerpt'        => $item['excerpt'],
                'permalink'      => get_permalink( $post ),
                'edit_url'       => $edit_url,
            );

            if ( count( $candidates ) >= self::CANDIDATE_LIMIT ) {
                break;
            }
        }

        return $candidates;
    }

    /**
     * Find pages already linking to the target in one indexed lookup.
     *
     * @return array<int, int>
     */
    private function get_existing_source_ids( string $target_url ): array {
        global $wpdb;

        $links       = LHA_DB::table( 'links' );
        $occurrences = LHA_DB::table( 'occurrences' );
        $url_hash    = hash( 'sha256', LHA_DB::normalize_url( $target_url ) );
        $ids         = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT o.object_id
                 FROM {$links} l
                 INNER JOIN {$occurrences} o ON o.link_id = l.id
                 INNER JOIN {$wpdb->posts} p ON p.ID = o.object_id AND p.post_type = o.object_type
                 WHERE l.url_hash = %s AND p.post_status = %s",
                $url_hash,
                'publish'
            )
        );

        return array_values( array_unique( array_map( 'absint', $ids ?: array() ) ) );
    }

    /**
     * Validate model output against the server-owned candidate map.
     *
     * @param array $suggestions Untrusted model output.
     * @param array $candidates Server-approved candidate data.
     * @return array<int, array<string, mixed>>
     */
    public static function normalize_suggestions( array $suggestions, array $candidates ): array {
        $candidate_map = array();
        foreach ( $candidates as $candidate ) {
            $candidate_map[ (int) $candidate['source_post_id'] ] = $candidate;
        }

        $normalized = array();
        $seen       = array();
        foreach ( $suggestions as $suggestion ) {
            if ( ! is_array( $suggestion ) ) {
                continue;
            }

            $source_id = absint( $suggestion['source_post_id'] ?? 0 );
            if ( ! isset( $candidate_map[ $source_id ] ) || isset( $seen[ $source_id ] ) ) {
                continue;
            }

            $anchor    = self::truncate( sanitize_text_field( $suggestion['anchor_text'] ?? '' ), 100 );
            $placement = self::truncate( sanitize_text_field( $suggestion['placement_hint'] ?? '' ), 240 );
            $reason    = self::truncate( sanitize_text_field( $suggestion['reason'] ?? '' ), 300 );
            if ( '' === $anchor || '' === $placement || '' === $reason ) {
                continue;
            }

            $candidate = $candidate_map[ $source_id ];
            $normalized[] = array(
                'source_post_id' => $source_id,
                'source_title'   => (string) $candidate['title'],
                'source_url'     => (string) $candidate['permalink'],
                'source_edit_url' => (string) $candidate['edit_url'],
                'anchor_text'    => $anchor,
                'placement_hint' => $placement,
                'reason'         => $reason,
            );
            $seen[ $source_id ] = true;

            if ( count( $normalized ) >= self::SUGGESTION_LIMIT ) {
                break;
            }
        }

        return $normalized;
    }

    private function get_post_text( WP_Post $post ): string {
        $text = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : $post->post_content;
        return self::clean_text( $text );
    }

    private function score_candidate( string $target_title, string $target_text, string $source_title, string $source_text ): int {
        $source_tokens = array_fill_keys( $this->tokenize( $source_title . ' ' . $source_text ), true );
        $score         = 0;

        foreach ( $this->tokenize( $target_title ) as $token ) {
            $score += isset( $source_tokens[ $token ] ) ? 4 : 0;
        }
        foreach ( $this->tokenize( $target_text ) as $token ) {
            $score += isset( $source_tokens[ $token ] ) ? 1 : 0;
        }

        $clean_title = mb_strtolower( self::clean_text( $target_title ) );
        $source_all  = mb_strtolower( self::clean_text( $source_title . ' ' . $source_text ) );
        if ( mb_strlen( $clean_title ) >= 3 && str_contains( $source_all, $clean_title ) ) {
            $score += 20;
        }

        return $score;
    }

    /** @return array<int, string> */
    private function tokenize( string $text ): array {
        preg_match_all( '/[\p{L}\p{N}]+/u', mb_strtolower( $text ), $matches );
        $tokens = array();

        foreach ( array_slice( $matches[0] ?? array(), 0, 200 ) as $segment ) {
            $length = mb_strlen( $segment );
            if ( $length >= 2 ) {
                $tokens[ $segment ] = true;
            }
            if ( preg_match( '/\p{Han}/u', $segment ) ) {
                for ( $index = 0; $index < min( $length - 1, 40 ); $index++ ) {
                    $tokens[ mb_substr( $segment, $index, 2 ) ] = true;
                }
            }
        }

        return array_keys( $tokens );
    }

    private static function clean_text( string $text ): string {
        $text = wp_strip_all_tags( strip_shortcodes( $text ) );
        return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
    }

    private static function truncate( string $text, int $length ): string {
        return mb_substr( trim( $text ), 0, $length );
    }
}
