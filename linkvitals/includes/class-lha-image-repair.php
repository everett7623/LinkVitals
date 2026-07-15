<?php
/**
 * Missing image variant repair service.
 *
 * Finds a verified original for internal WordPress image-size URLs and reuses
 * the guarded URL replacement workflow to update source content.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Image_Repair {

    /**
     * Match a WordPress-generated WIDTHxHEIGHT suffix immediately before an
     * image extension, while preserving any query string or fragment.
     */
    private const SIZE_SUFFIX_PATTERN = '/-([1-9][0-9]{1,4})x([1-9][0-9]{1,4})(?=\.(?:jpe?g|png|gif|webp|avif)(?:[?#]|$))/i';

    /**
     * Build deterministic candidate originals for a sized image URL.
     *
     * The first candidate removes only the size suffix. A second candidate is
     * offered for WordPress large-image URLs that also contain `-scaled`.
     *
     * @return array<int, string>
     */
    public static function get_candidate_urls( string $url ): array {
        $url = trim( $url );
        if ( '' === $url ) {
            return array();
        }

        $candidate = preg_replace( self::SIZE_SUFFIX_PATTERN, '', $url, 1, $replacement_count );
        if ( 1 !== $replacement_count || ! is_string( $candidate ) || $candidate === $url ) {
            return array();
        }

        $candidates = array( $candidate );
        $unscaled   = preg_replace(
            '/-scaled(?=\.(?:jpe?g|png|gif|webp|avif)(?:[?#]|$))/i',
            '',
            $candidate,
            1,
            $scaled_count
        );

        if ( 1 === $scaled_count && is_string( $unscaled ) && $unscaled !== $candidate ) {
            $candidates[] = $unscaled;
        }

        return array_values( array_unique( $candidates ) );
    }

    /**
     * Determine whether a stored link is eligible for automatic repair.
     */
    public static function is_repairable_link( array $link ): bool {
        return 'image' === (string) ( $link['link_type'] ?? '' )
            && 'broken' === (string) ( $link['status'] ?? '' )
            && 404 === (int) ( $link['http_code'] ?? 0 )
            && empty( $link['is_ignored'] )
            && LHA_Link_Checker::is_internal_url( (string) ( $link['url'] ?? '' ) )
            && ! empty( self::get_candidate_urls( (string) ( $link['url'] ?? '' ) ) );
    }

    /**
     * Find a verified original and replace the broken sized URL everywhere it
     * appears in supported post content.
     *
     * @return array{success:bool,status:string,link_id:int,old_url:string,new_url:string,replaced:int,resolved:bool,message:string}
     */
    public function repair_link( int $link_id ): array {
        if ( $link_id < 1 ) {
            return $this->error_result( 0, '', __( 'Invalid link ID.', 'linkvitals' ) );
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            return $this->error_result( $link_id, '', __( 'Link not found.', 'linkvitals' ) );
        }

        $old_url = (string) $link['url'];
        if ( ! self::is_repairable_link( $link ) ) {
            return $this->error_result(
                $link_id,
                $old_url,
                __( 'This link is not an eligible internal 404 image size.', 'linkvitals' )
            );
        }

        $candidate = $this->find_verified_candidate( self::get_candidate_urls( $old_url ) );
        if ( '' === $candidate ) {
            return $this->error_result(
                $link_id,
                $old_url,
                __( 'No verified original image was found.', 'linkvitals' )
            );
        }

        $replacement = ( new LHA_Repair() )->replace_url( $old_url, $candidate );
        $replaced    = (int) ( $replacement['replaced'] ?? 0 );
        $resolved    = ! empty( $replacement['resolved'] );

        if ( empty( $replacement['success'] ) || ( 0 === $replaced && ! $resolved ) ) {
            $errors  = isset( $replacement['errors'] ) && is_array( $replacement['errors'] )
                ? $replacement['errors']
                : array();
            $message = (string) ( $errors[0] ?? $replacement['message'] ?? __( 'The image URL could not be repaired.', 'linkvitals' ) );
            return $this->error_result( $link_id, $old_url, $message, $candidate );
        }

        $message = $replaced > 0
            ? sprintf(
                /* translators: 1: replacement count, 2: verified image URL */
                __( 'Repaired %1$d occurrence(s) with verified image: %2$s', 'linkvitals' ),
                $replaced,
                $candidate
            )
            : (string) ( $replacement['message'] ?? __( 'Stale image record removed.', 'linkvitals' ) );

        return array(
            'success'  => true,
            'status'   => 'repaired',
            'link_id'  => $link_id,
            'old_url'  => $old_url,
            'new_url'  => $candidate,
            'replaced' => $replaced,
            'resolved' => $resolved,
            'message'  => $message,
        );
    }

    /**
     * Return the first candidate that responds successfully as an image.
     *
     * @param array<int, string> $candidates Candidate URLs in preference order.
     */
    private function find_verified_candidate( array $candidates ): string {
        $settings = get_option( 'lha_settings', array() );
        $checker  = new LHA_Link_Checker();

        foreach ( $candidates as $candidate ) {
            if ( ! LHA_Link_Checker::is_internal_url( $candidate ) ) {
                continue;
            }

            $result       = $checker->check( $candidate, $settings );
            $http_code    = (int) ( $result['http_code'] ?? 0 );
            $content_type = strtolower( trim( (string) ( $result['content_type'] ?? '' ) ) );

            if ( 'ok' === ( $result['status'] ?? '' )
                && $http_code >= 200
                && $http_code < 300
                && str_starts_with( $content_type, 'image/' )
            ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Build the stable failure contract used by the AJAX endpoint.
     *
     * @return array{success:bool,status:string,link_id:int,old_url:string,new_url:string,replaced:int,resolved:bool,message:string}
     */
    private function error_result( int $link_id, string $old_url, string $message, string $new_url = '' ): array {
        return array(
            'success'  => false,
            'status'   => 'failed',
            'link_id'  => $link_id,
            'old_url'  => $old_url,
            'new_url'  => $new_url,
            'replaced' => 0,
            'resolved' => false,
            'message'  => $message,
        );
    }
}
