<?php
/**
 * Repair class
 *
 * Handles bulk URL replacement and unlinking operations.
 * All modifications are logged and require confirmation.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Repair {

    /**
     * Resolve a link row by URL using exact hash first, then normalized URL.
     */
    private function find_link_by_url( string $url ): ?array {
        global $wpdb;

        $table_links = LHA_DB::table( 'links' );
        $normalized = LHA_DB::normalize_url( $url );
        if ( empty( $normalized ) ) {
            return null;
        }

        $url_hash = hash( 'sha256', $normalized );

        $link = $wpdb->get_row(
            $wpdb->prepare( "SELECT id, url, normalized_url FROM {$table_links} WHERE url_hash = %s", $url_hash ),
            ARRAY_A
        );

        if ( $link ) {
            return $link;
        }

        return $wpdb->get_row(
            $wpdb->prepare( "SELECT id, url, normalized_url FROM {$table_links} WHERE normalized_url = %s LIMIT 1", $normalized ),
            ARRAY_A
        );
    }

    /**
     * Compare URLs in a normalization-friendly way.
     */
    private function urls_match( string $a, string $b ): bool {
        $a_decoded = html_entity_decode( trim( $a ), ENT_QUOTES, 'UTF-8' );
        $b_decoded = html_entity_decode( trim( $b ), ENT_QUOTES, 'UTF-8' );

        if ( LHA_DB::normalize_url( $a_decoded ) === LHA_DB::normalize_url( $b_decoded ) ) {
            return true;
        }

        $a_parts = wp_parse_url( $a_decoded );
        $b_parts = wp_parse_url( $b_decoded );
        if ( ! is_array( $a_parts ) || ! is_array( $b_parts ) ) {
            return false;
        }

        $a_path_query = ( $a_parts['path'] ?? '' ) . ( isset( $a_parts['query'] ) ? '?' . $a_parts['query'] : '' );
        $b_path_query = ( $b_parts['path'] ?? '' ) . ( isset( $b_parts['query'] ) ? '?' . $b_parts['query'] : '' );
        if ( '' === $a_path_query || '' === $b_path_query || $a_path_query !== $b_path_query ) {
            return false;
        }

        $a_host = strtolower( (string) ( $a_parts['host'] ?? '' ) );
        $b_host = strtolower( (string) ( $b_parts['host'] ?? '' ) );
        $a_host = preg_replace( '/^www\./', '', $a_host );
        $b_host = preg_replace( '/^www\./', '', $b_host );

        if ( $a_host !== '' && $b_host !== '' ) {
            return $a_host === $b_host;
        }

        // For relative URLs in content, only allow matching against current site host.
        $site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        $site_host = preg_replace( '/^www\./', '', $site_host );

        return ( $a_host === '' && $b_host === $site_host ) || ( $b_host === '' && $a_host === $site_host );
    }

    /**
     * Check whether an occurrence maps to post_content that this class can edit.
     */
    private function is_supported_post_object_type( string $object_type ): bool {
        if ( in_array( $object_type, array( 'post', 'page' ), true ) ) {
            return true;
        }

        $post_types = get_post_types( array( 'public' => true ), 'names' );
        return in_array( $object_type, $post_types, true );
    }

    /**
     * Check whether the current user can edit the post content being repaired.
     */
    private function can_edit_post_content( WP_Post $post ): bool {
        return current_user_can( 'edit_post', $post->ID );
    }

    /**
     * Build a consistent post edit permission error.
     */
    private function get_edit_permission_message( WP_Post $post ): string {
        return sprintf( __( 'You do not have permission to edit post #%d.', 'linkvitals' ), $post->ID );
    }

    /**
     * Replace URL inside post content with direct and attribute-level matching.
     *
     * @return array{content:string,count:int}
     */
    private function replace_url_in_content( string $content, string $old_url, string $new_url ): array {
        $count = 0;
        $new_content = $content;

        // Fast-path direct replacements to cover plain text and common encoded forms.
        $old_variants = array_unique( array_filter( array(
            $old_url,
            untrailingslashit( $old_url ),
            trailingslashit( untrailingslashit( $old_url ) ),
            str_replace( '&amp;', '&', $old_url ),
            str_replace( '&', '&amp;', $old_url ),
        ) ) );

        foreach ( $old_variants as $variant ) {
            $new_content = str_replace( $variant, $new_url, $new_content, $direct_count );
            $count += (int) $direct_count;
        }

        // Fallback: replace href/src/data attribute values via normalized URL comparison.
        $new_content = preg_replace_callback(
            '/\b(href|src|data)\s*=\s*(["\'])(.*?)\2/i',
            function( array $matches ) use ( $old_url, $new_url, &$count ) {
                $attr_value = $matches[3];
                if ( ! $this->urls_match( $attr_value, $old_url ) ) {
                    return $matches[0];
                }

                $replacement = $new_url;
                if ( str_contains( $attr_value, '&amp;' ) ) {
                    $replacement = str_replace( '&', '&amp;', $replacement );
                }

                $count++;
                return $matches[1] . '=' . $matches[2] . $replacement . $matches[2];
            },
            $new_content
        );

        // Replace individual URL candidates inside srcset attributes.
        $new_content = preg_replace_callback(
            '/\bsrcset\s*=\s*(["\'])(.*?)\1/i',
            function( array $matches ) use ( $old_url, $new_url, &$count ) {
                $quote = $matches[1];
                $value = $matches[2];
                $entries = explode( ',', $value );
                $changed = false;

                foreach ( $entries as $index => $entry ) {
                    $entry = trim( $entry );
                    if ( '' === $entry ) {
                        continue;
                    }

                    if ( ! preg_match( '/^([^\s,]+)(\s+.+)?$/', $entry, $entry_parts ) ) {
                        continue;
                    }

                    $candidate_url = $entry_parts[1];
                    $descriptor = $entry_parts[2] ?? '';

                    if ( ! $this->urls_match( $candidate_url, $old_url ) ) {
                        continue;
                    }

                    $replacement = $new_url;
                    if ( str_contains( $candidate_url, '&amp;' ) ) {
                        $replacement = str_replace( '&', '&amp;', $replacement );
                    }

                    $entries[ $index ] = $replacement . $descriptor;
                    $count++;
                    $changed = true;
                }

                if ( ! $changed ) {
                    return $matches[0];
                }

                return 'srcset=' . $quote . implode( ', ', $entries ) . $quote;
            },
            $new_content
        );

        return array(
            'content' => $new_content,
            'count'   => $count,
        );
    }

    /**
     * Remove matching anchor tags and keep only their text.
     *
     * @return array{content:string,count:int}
     */
    private function unlink_url_in_content( string $content, string $target_url ): array {
        $count = 0;

        $new_content = preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function( array $matches ) use ( $target_url, &$count ) {
                $attributes = $matches[1];
                $inner_html = $matches[2];

                if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $attributes, $href_match ) ) {
                    return $matches[0];
                }

                $href = $href_match[2];
                if ( ! $this->urls_match( $href, $target_url ) ) {
                    return $matches[0];
                }

                $count++;
                return $inner_html;
            },
            $content
        );

        return array(
            'content' => $new_content,
            'count'   => $count,
        );
    }

    /**
     * Record a reversible repair snapshot.
     */
    private function record_repair(
        string $action_type,
        array $occurrence,
        WP_Post $post,
        string $old_url,
        string $new_url,
        string $old_content,
        string $new_content
    ): int|false {
        $edit_url = ! empty( $occurrence['edit_url'] )
            ? (string) $occurrence['edit_url']
            : (string) get_edit_post_link( $post->ID, 'raw' );

        return LHA_DB::insert_repair( array(
            'action_type'  => $action_type,
            'object_type'  => (string) $occurrence['object_type'],
            'object_id'    => (int) $occurrence['object_id'],
            'source_title' => (string) ( $occurrence['source_title'] ?: $post->post_title ),
            'edit_url'     => $edit_url,
            'old_url'      => $old_url,
            'new_url'      => $new_url,
            'old_content'  => $old_content,
            'new_content'  => $new_content,
        ) );
    }

    /**
     * Replace a URL across all occurrences or specific posts
     *
     * @param string $old_url URL to replace
     * @param string $new_url Replacement URL
     * @param int|null $object_id If set, only replace in this specific post
     * @return array Result with count of replacements
     */
    public function replace_url( string $old_url, string $new_url, ?int $object_id = null ): array {
        if ( ! LHA_Security::check_permission() ) {
            return array( 'success' => false, 'message' => __( 'Permission denied.', 'linkvitals' ) );
        }

        $old_url = esc_url_raw( $old_url );
        $new_url = esc_url_raw( $new_url );

        if ( empty( $old_url ) || empty( $new_url ) ) {
            return array( 'success' => false, 'message' => __( 'Invalid URLs provided.', 'linkvitals' ) );
        }

        global $wpdb;
        $table_occurrences = LHA_DB::table( 'occurrences' );

        $link = $this->find_link_by_url( $old_url );

        if ( ! $link ) {
            return array( 'success' => false, 'message' => __( 'URL not found in database.', 'linkvitals' ) );
        }

        $occurrences = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_occurrences} WHERE link_id = %d" . ( $object_id ? " AND object_id = %d" : "" ),
                ...array_filter( array( $link['id'], $object_id ) )
            ),
            ARRAY_A
        );

        if ( empty( $occurrences ) ) {
            // Remove stale link records that no longer exist in source content.
            LHA_DB::cleanup_orphaned_links();

            return array(
                'success'  => true,
                'replaced' => 0,
                'resolved' => true,
                'errors'   => array(),
                'message'  => __( 'No occurrences found. Stale record cleaned.', 'linkvitals' ),
            );
        }

        $replaced = 0;
        $errors = array();
        $resolved_targets = array();
        $is_semantically_same_url = $this->urls_match( $old_url, $new_url );

        foreach ( $occurrences as $occurrence ) {
            if ( ! $this->is_supported_post_object_type( (string) $occurrence['object_type'] ) ) {
                continue;
            }

            $post = get_post( (int) $occurrence['object_id'] );
            if ( ! $post ) {
                $errors[] = sprintf( __( 'Post #%d not found.', 'linkvitals' ), $occurrence['object_id'] );
                continue;
            }

            if ( ! $this->can_edit_post_content( $post ) ) {
                $errors[] = $this->get_edit_permission_message( $post );
                continue;
            }

            $old_content = $post->post_content;
            $replacement = $this->replace_url_in_content( $old_content, $old_url, $new_url );
            $new_content = $replacement['content'];

            if ( $new_content !== $old_content ) {
                $result = wp_update_post( array(
                    'ID'           => $post->ID,
                    'post_content' => $new_content,
                ), true );

                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( __( 'Failed to update post #%d.', 'linkvitals' ), $post->ID );
                } else {
                    $this->record_repair(
                        'url_replaced',
                        $occurrence,
                        $post,
                        $old_url,
                        $new_url,
                        $old_content,
                        $new_content
                    );

                    $replaced += max( 1, (int) $replacement['count'] );
                    $resolved_targets[ $occurrence['object_type'] . ':' . (int) $occurrence['object_id'] ] = array(
                        'object_type' => $occurrence['object_type'],
                        'object_id'   => (int) $occurrence['object_id'],
                    );

                    // Log the replacement.
                    LHA_Logger::log(
                        'url_replaced',
                        $old_url,
                        $old_url,
                        $new_url,
                        array( (int) $occurrence['object_id'] ),
                        sprintf( __( 'Replaced URL in %s', 'linkvitals' ), $post->post_title )
                    );
                }
            }
        }

        $resolved = false;
        if ( ! empty( $resolved_targets ) ) {
            foreach ( $resolved_targets as $target ) {
                $wpdb->delete(
                    $table_occurrences,
                    array(
                        'link_id'     => (int) $link['id'],
                        'object_type' => $target['object_type'],
                        'object_id'   => $target['object_id'],
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
            LHA_DB::cleanup_orphaned_links();
            $resolved = true;
        } elseif ( $is_semantically_same_url ) {
            // Treat slash/fragment-only "fixes" as resolved to avoid keeping false-positive redirects.
            LHA_DB::mark_link_resolved( (int) $link['id'] );
            $resolved = true;
        }

        return array(
            'success'  => true,
            'replaced' => $replaced,
            'resolved' => $resolved,
            'errors'   => $errors,
            'message'  => sprintf( __( 'Replaced %d occurrence(s).', 'linkvitals' ), $replaced ),
        );
    }

    /**
     * Remove a link (unlink) from content - converts to plain text
     *
     * @param int $link_id Link ID to unlink
     * @param int|null $object_id Specific post ID or null for all
     * @return array Result
     */
    public function unlink( int $link_id, ?int $object_id = null ): array {
        if ( ! LHA_Security::check_permission() ) {
            return array( 'success' => false, 'message' => __( 'Permission denied.', 'linkvitals' ) );
        }

        $link = LHA_DB::get_link( $link_id );
        if ( ! $link ) {
            return array( 'success' => false, 'message' => __( 'Link not found.', 'linkvitals' ) );
        }

        $occurrences = LHA_DB::get_occurrences( $link_id );
        if ( empty( $occurrences ) ) {
            LHA_DB::cleanup_orphaned_links();

            return array(
                'success'  => true,
                'unlinked' => 0,
                'message'  => __( 'No occurrences found. Stale record cleaned.', 'linkvitals' ),
            );
        }

        $unlinked = 0;
        $resolved_targets = array();
        $errors = array();

        foreach ( $occurrences as $occurrence ) {
            if ( $object_id && (int) $occurrence['object_id'] !== $object_id ) {
                continue;
            }

            if ( ! $this->is_supported_post_object_type( (string) $occurrence['object_type'] ) ) {
                continue;
            }

            $post = get_post( (int) $occurrence['object_id'] );
            if ( ! $post ) {
                $errors[] = sprintf( __( 'Post #%d not found.', 'linkvitals' ), $occurrence['object_id'] );
                continue;
            }

            if ( ! $this->can_edit_post_content( $post ) ) {
                $errors[] = $this->get_edit_permission_message( $post );
                continue;
            }

            $old_content = $post->post_content;

            // Remove matching link tags but keep anchor text.
            $unlink_result = $this->unlink_url_in_content( $old_content, $link['url'] );
            $new_content = $unlink_result['content'];

            if ( $new_content !== $old_content ) {
                $result = wp_update_post( array(
                    'ID'           => $post->ID,
                    'post_content' => $new_content,
                ), true );

                if ( is_wp_error( $result ) ) {
                    $errors[] = sprintf( __( 'Failed to update post #%d.', 'linkvitals' ), $post->ID );
                    continue;
                }

                $this->record_repair(
                    'link_unlinked',
                    $occurrence,
                    $post,
                    $link['url'],
                    '',
                    $old_content,
                    $new_content
                );

                $unlinked += max( 1, (int) $unlink_result['count'] );
                $resolved_targets[ $occurrence['object_type'] . ':' . (int) $occurrence['object_id'] ] = array(
                    'object_type' => $occurrence['object_type'],
                    'object_id'   => (int) $occurrence['object_id'],
                );

                LHA_Logger::log(
                    'link_unlinked',
                    $link['url'],
                    $link['url'],
                    '',
                    array( (int) $occurrence['object_id'] ),
                    sprintf( __( 'Unlinked URL from %s', 'linkvitals' ), $post->post_title )
                );
            }
        }

        if ( ! empty( $resolved_targets ) ) {
            global $wpdb;
            $table_occurrences = LHA_DB::table( 'occurrences' );

            foreach ( $resolved_targets as $target ) {
                $wpdb->delete(
                    $table_occurrences,
                    array(
                        'link_id'     => $link_id,
                        'object_type' => $target['object_type'],
                        'object_id'   => $target['object_id'],
                    ),
                    array( '%d', '%s', '%d' )
                );
            }
            LHA_DB::cleanup_orphaned_links();
        }

        return array(
            'success'  => true,
            'unlinked' => $unlinked,
            'errors'   => $errors,
            'message'  => sprintf( __( 'Unlinked %d occurrence(s).', 'linkvitals' ), $unlinked ),
        );
    }

    /**
     * Roll back a previously recorded repair if content has not changed since.
     */
    public function rollback( int $repair_id ): array {
        if ( ! LHA_Security::check_permission() ) {
            return array( 'success' => false, 'message' => __( 'Permission denied.', 'linkvitals' ) );
        }

        $repair_id = absint( $repair_id );
        if ( ! $repair_id ) {
            return array( 'success' => false, 'message' => __( 'Invalid repair ID.', 'linkvitals' ) );
        }

        $repair = LHA_DB::get_repair( $repair_id );
        if ( ! $repair ) {
            return array( 'success' => false, 'message' => __( 'Repair record not found.', 'linkvitals' ) );
        }

        if ( 'active' !== (string) $repair['status'] ) {
            return array( 'success' => false, 'message' => __( 'This repair has already been rolled back.', 'linkvitals' ) );
        }

        if ( ! $this->is_supported_post_object_type( (string) $repair['object_type'] ) ) {
            return array( 'success' => false, 'message' => __( 'This repair type cannot be rolled back automatically.', 'linkvitals' ) );
        }

        $post = get_post( (int) $repair['object_id'] );
        if ( ! $post ) {
            return array( 'success' => false, 'message' => __( 'Post not found.', 'linkvitals' ) );
        }

        if ( ! $this->can_edit_post_content( $post ) ) {
            return array( 'success' => false, 'message' => $this->get_edit_permission_message( $post ) );
        }

        $current_content = (string) $post->post_content;
        $current_hash    = hash( 'sha256', $current_content );
        $expected_hash   = (string) $repair['new_content_hash'];

        if ( ! hash_equals( $expected_hash, $current_hash ) || $current_content !== (string) $repair['new_content'] ) {
            return array(
                'success' => false,
                'message' => __( 'The content has changed since this repair was recorded. Review the post before rolling back.', 'linkvitals' ),
            );
        }

        $result = wp_update_post( array(
            'ID'           => $post->ID,
            'post_content' => (string) $repair['old_content'],
        ), true );

        if ( is_wp_error( $result ) ) {
            return array(
                'success' => false,
                'message' => __( 'Failed to roll back the repair.', 'linkvitals' ),
            );
        }

        LHA_DB::mark_repair_rolled_back( $repair_id, __( 'Rolled back from repair history.', 'linkvitals' ) );

        LHA_Logger::log(
            'repair_rolled_back',
            (string) $repair['old_url'],
            (string) $repair['new_url'],
            (string) $repair['old_url'],
            array( (int) $repair['object_id'] ),
            sprintf( __( 'Rolled back repair in %s', 'linkvitals' ), $post->post_title )
        );

        $queue = new LHA_Queue();
        $queue->add(
            (string) $repair['object_type'],
            (int) $repair['object_id'],
            get_permalink( $post ) ?: '',
            1
        );
        // Preserve the cursor boundary when this repair joins an active scan.
        if ( in_array( get_option( 'lha_scan_status', 'idle' ), array( 'running', 'paused' ), true ) ) {
            update_option( 'lha_scan_status', 'running' );
        } else {
            LHA_Scanner::record_scan_start( 'repair' );
        }

        return array(
            'success' => true,
            'message' => __( 'Repair rolled back.', 'linkvitals' ),
        );
    }

    /**
     * Get preview of what will be affected by a replacement
     *
     * @param string $old_url URL to replace
     * @param int|null $object_id Specific post or null for all
     * @return array Preview data
     */
    public function get_replace_preview( string $old_url, ?int $object_id = null ): array {
        global $wpdb;

        $table_occurrences = LHA_DB::table( 'occurrences' );

        $link = $this->find_link_by_url( $old_url );

        if ( ! $link ) {
            return array( 'count' => 0, 'posts' => array() );
        }

        $query = "SELECT DISTINCT object_id, object_type, source_title, edit_url FROM {$table_occurrences} WHERE link_id = %d";
        $params = array( $link['id'] );

        if ( $object_id ) {
            $query .= " AND object_id = %d";
            $params[] = $object_id;
        }

        $affected = $wpdb->get_results(
            $wpdb->prepare( $query, ...$params ),
            ARRAY_A
        );

        return array(
            'count' => count( $affected ),
            'posts' => $affected ?: array(),
        );
    }
}
