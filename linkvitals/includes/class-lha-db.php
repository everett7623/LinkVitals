<?php
/**
 * Database helper class
 *
 * Provides table creation via dbDelta and all CRUD operations
 * for the plugin's custom tables. All queries use $wpdb->prepare().
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_DB {

    /**
     * Get link statuses that represent actionable link issues.
     *
     * @return array<int, string>
     */
    public static function get_issue_statuses(): array {
        return array( 'broken', 'server_error', 'timeout', 'ssl_error', 'dns_error', 'forbidden' );
    }

    /**
     * Get supported report filter keys.
     *
     * @return array<int, string>
     */
    public static function get_report_filter_keys(): array {
        return array(
            '',
            'issues',
            'broken',
            '404',
            '5xx',
            'server_error',
            'redirect',
            'timeout',
            'ssl_error',
            'dns_error',
            'forbidden',
            'internal',
            'external',
            'image',
            'anchor',
            'ignored',
        );
    }

    /**
     * Sanitize a report filter key against the supported filter list.
     */
    public static function sanitize_report_filter_key( mixed $key ): string {
        if ( is_array( $key ) ) {
            $key = reset( $key );
        }

        $key = sanitize_key( (string) $key );
        return in_array( $key, self::get_report_filter_keys(), true ) ? $key : '';
    }

    /**
     * Get table name with prefix.
     *
     * @param string $name Table suffix (links, occurrences, queue, logs).
     * @return string Full table name.
     */
    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'lha_' . $name;
    }

    /**
     * Create all custom database tables using dbDelta.
     *
     * Tables: wp_lha_links, wp_lha_occurrences, wp_lha_queue, wp_lha_logs,
     * wp_lha_repairs.
     * Uses utf8mb4_unicode_ci charset and includes all required indexes.
     */
    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

        $table_links       = self::table( 'links' );
        $table_occurrences = self::table( 'occurrences' );
        $table_queue       = self::table( 'queue' );
        $table_logs        = self::table( 'logs' );
        $table_repairs     = self::table( 'repairs' );

        // Links table - stores unique URLs and their check results.
        $sql_links = "CREATE TABLE {$table_links} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url_hash varchar(64) NOT NULL DEFAULT '',
            url text NOT NULL,
            normalized_url text NOT NULL,
            domain varchar(255) NOT NULL DEFAULT '',
            link_type varchar(20) NOT NULL DEFAULT 'external',
            http_code smallint(6) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'pending',
            error_type varchar(50) NOT NULL DEFAULT '',
            final_url text NOT NULL,
            redirect_count tinyint(3) unsigned NOT NULL DEFAULT 0,
            response_time float NOT NULL DEFAULT 0,
            content_type varchar(100) NOT NULL DEFAULT '',
            first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_checked datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            check_count int(11) unsigned NOT NULL DEFAULT 0,
            is_ignored tinyint(1) NOT NULL DEFAULT 0,
            ignore_reason varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY status (status),
            KEY link_type (link_type),
            KEY domain (domain),
            KEY is_ignored (is_ignored),
            KEY http_code (http_code)
        ) {$charset_collate};";

        // Occurrences table - stores where each link appears.
        $sql_occurrences = "CREATE TABLE {$table_occurrences} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            link_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_type varchar(50) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_title varchar(255) NOT NULL DEFAULT '',
            source_url text NOT NULL,
            edit_url text NOT NULL,
            html_tag varchar(20) NOT NULL DEFAULT 'a',
            attribute_name varchar(20) NOT NULL DEFAULT 'href',
            anchor_text text NOT NULL,
            raw_html text NOT NULL,
            context_snippet text NOT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY link_id (link_id),
            KEY object_type_id (object_type, object_id),
            KEY object_id (object_id)
        ) {$charset_collate};";

        // Queue table - manages the scan queue.
        $sql_queue = "CREATE TABLE {$table_queue} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(50) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            object_url text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            priority tinyint(3) unsigned NOT NULL DEFAULT 0,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_error text NOT NULL,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY status (status),
            KEY priority (priority),
            KEY object_type_id (object_type, object_id)
        ) {$charset_collate};";

        // Logs table - records scan and repair actions.
        $sql_logs = "CREATE TABLE {$table_logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL DEFAULT '',
            url text NOT NULL,
            old_value text NOT NULL,
            new_value text NOT NULL,
            object_ids text NOT NULL,
            message text NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY action_type (action_type),
            KEY created_at (created_at)
        ) {$charset_collate};";

        // Repairs table - stores reversible content changes.
        $sql_repairs = "CREATE TABLE {$table_repairs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action_type varchar(50) NOT NULL DEFAULT '',
            object_type varchar(50) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            source_title varchar(255) NOT NULL DEFAULT '',
            edit_url text NOT NULL,
            old_url text NOT NULL,
            new_url text NOT NULL,
            old_content longtext NOT NULL,
            new_content longtext NOT NULL,
            old_content_hash varchar(64) NOT NULL DEFAULT '',
            new_content_hash varchar(64) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'active',
            rollback_message text NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rolled_back_by bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            rolled_back_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY action_type (action_type),
            KEY object_type_id (object_type, object_id),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_links );
        dbDelta( $sql_occurrences );
        dbDelta( $sql_queue );
        dbDelta( $sql_logs );
        dbDelta( $sql_repairs );
    }

    /**
     * Normalize URL for deduplication.
     *
     * Algorithm:
     * 1. Trim whitespace and convert to lowercase
     * 2. Remove fragment (#... to end)
     * 3. Remove trailing slash (except for root path '/')
     * 4. Remove default ports (:80, :443)
     * 5. Remove 'www.' prefix from host
     *
     * @param string $url The URL to normalize.
     * @return string Normalized URL.
     */
    public static function normalize_url( string $url ): string {
        // 1. Trim whitespace and convert to lowercase.
        $url = strtolower( trim( $url ) );

        // 2. Remove fragment (#... to end).
        $fragment_pos = strpos( $url, '#' );
        if ( false !== $fragment_pos ) {
            $url = substr( $url, 0, $fragment_pos );
        }

        // 3. Remove trailing slash (except for root path '/').
        if ( preg_match( '#^https?://[^/]+/$#', $url ) ) {
            // This is a root path like https://example.com/ — keep it.
        } else {
            $url = rtrim( $url, '/' );
        }

        // 4. Remove default ports (:80, :443).
        $url = preg_replace( '#:(80|443)(?=/|$)#', '', $url );

        // 5. Remove 'www.' prefix from host.
        $url = preg_replace( '#^(https?://)www\.#', '$1', $url );

        return $url;
    }

    /**
     * Insert or update a link record.
     *
     * Uses SHA-256 hash of the URL for deduplication. If the link already
     * exists (by url_hash), updates last_seen. Otherwise inserts a new record.
     *
     * @param array $data Link data with at minimum 'url' key.
     * @return int|false Link ID on success, false on failure.
     */
    public static function upsert_link( array $data ): int|false {
        global $wpdb;

        $table      = self::table( 'links' );
        $url        = $data['url'] ?? '';
        $normalized = self::normalize_url( $url );
        $url_hash   = hash( 'sha256', $normalized );
        $now        = current_time( 'mysql' );

        // Check if link already exists by url_hash.
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE url_hash = %s",
                $url_hash
            )
        );

        if ( $existing_id ) {
            // Update last_seen timestamp on duplicate.
            $wpdb->update(
                $table,
                array( 'last_seen' => $now ),
                array( 'id' => $existing_id ),
                array( '%s' ),
                array( '%d' )
            );
            return (int) $existing_id;
        }

        // Insert new link record.
        $domain = wp_parse_url( $url, PHP_URL_HOST ) ?: '';

        $result = $wpdb->insert(
            $table,
            array(
                'url_hash'       => $url_hash,
                'url'            => $url,
                'normalized_url' => $normalized,
                'domain'         => $domain,
                'link_type'      => $data['link_type'] ?? 'external',
                'http_code'      => 0,
                'status'         => 'pending',
                'error_type'     => '',
                'final_url'      => '',
                'redirect_count' => 0,
                'response_time'  => 0,
                'content_type'   => '',
                'first_seen'     => $now,
                'last_seen'      => $now,
                'last_checked'   => '0000-00-00 00:00:00',
                'check_count'    => 0,
                'is_ignored'     => 0,
                'ignore_reason'  => '',
            ),
            array(
                '%s', '%s', '%s', '%s', '%s',
                '%d', '%s', '%s', '%s', '%d',
                '%f', '%s', '%s', '%s', '%s',
                '%d', '%d', '%s',
            )
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Insert a link occurrence record.
     *
     * Records where a link appears (the source content object).
     *
     * @param array $data Occurrence data with link_id, object_type, object_id.
     * @return int|false Occurrence ID on success, false on failure.
     */
    public static function insert_occurrence( array $data ): int|false {
        global $wpdb;

        $table = self::table( 'occurrences' );
        $now   = current_time( 'mysql' );

        $result = $wpdb->insert(
            $table,
            array(
                'link_id'         => $data['link_id'],
                'object_type'     => $data['object_type'],
                'object_id'       => $data['object_id'],
                'source_title'    => $data['source_title'] ?? '',
                'source_url'      => $data['source_url'] ?? '',
                'edit_url'        => $data['edit_url'] ?? '',
                'html_tag'        => $data['html_tag'] ?? 'a',
                'attribute_name'  => $data['attribute_name'] ?? 'href',
                'anchor_text'     => $data['anchor_text'] ?? '',
                'raw_html'        => $data['raw_html'] ?? '',
                'context_snippet' => $data['context_snippet'] ?? '',
                'created_at'      => $now,
                'updated_at'      => $now,
            ),
            array(
                '%d', '%s', '%d', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s',
            )
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update link HTTP check results.
     *
     * Updates the link record with the results of an HTTP check
     * and increments the check_count atomically.
     *
     * @param int   $link_id The link ID to update.
     * @param array $result  Check result data.
     * @return bool True on success.
     */
    public static function update_link_result( int $link_id, array $result ): bool {
        global $wpdb;

        $table = self::table( 'links' );
        $now   = current_time( 'mysql' );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET
                    http_code = %d,
                    status = %s,
                    error_type = %s,
                    final_url = %s,
                    redirect_count = %d,
                    response_time = %f,
                    content_type = %s,
                    last_checked = %s,
                    check_count = check_count + 1
                WHERE id = %d",
                (int) ( $result['http_code'] ?? 0 ),
                $result['status'] ?? 'unknown',
                $result['error_type'] ?? '',
                $result['final_url'] ?? '',
                (int) ( $result['redirect_count'] ?? 0 ),
                (float) ( $result['response_time'] ?? 0 ),
                $result['content_type'] ?? '',
                $now,
                $link_id
            )
        );

        return true;
    }

    /**
     * Mark a link as resolved without requiring a full re-scan.
     *
     * This is used by manual repair actions when a link issue is fixed directly
     * in content, so report filters/counts reflect the change immediately.
     */
    public static function mark_link_resolved( int $link_id ): bool {
        global $wpdb;

        $table = self::table( 'links' );
        $now   = current_time( 'mysql' );

        return false !== $wpdb->update(
            $table,
            array(
                'status'         => 'ok',
                'error_type'     => '',
                'final_url'      => '',
                'redirect_count' => 0,
                'last_checked'   => $now,
            ),
            array( 'id' => $link_id ),
            array( '%s', '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get links for report table with filtering, searching, sorting, and pagination.
     *
     * Excluded ignored links from all views except the 'ignored' filter.
     *
     * @param array $args Query arguments.
     * @return array {items: array, total: int}
     */
    public static function get_links( array $args = array() ): array {
        global $wpdb;

        $table_links = self::table( 'links' );

        $defaults = array(
            'status'    => '',
            'link_type' => '',
            'search'    => '',
            'orderby'   => 'last_checked',
            'order'     => 'DESC',
            'per_page'  => 20,
            'offset'    => 0,
        );

        $args = wp_parse_args( $args, $defaults );

        $where  = array( '1=1' );
        $values = array();

        // Filter by status.
        if ( ! empty( $args['status'] ) ) {
            switch ( $args['status'] ) {
                case 'issues':
                    $issue_statuses      = self::get_issue_statuses();
                    $status_placeholders = implode( ', ', array_fill( 0, count( $issue_statuses ), '%s' ) );
                    $where[]             = "(l.status IN ({$status_placeholders}) OR (l.http_code >= 500 AND l.http_code < 600))";
                    array_push( $values, ...$issue_statuses );
                    break;
                case 'broken':
                    $where[]  = 'l.status = %s';
                    $values[] = 'broken';
                    break;
                case '404':
                    $where[]  = 'l.http_code = %d';
                    $values[] = 404;
                    break;
                case '5xx':
                    $where[] = 'l.http_code >= 500 AND l.http_code < 600';
                    break;
                case 'server_error':
                    $where[]  = 'l.status = %s';
                    $values[] = 'server_error';
                    break;
                case 'redirect':
                    $where[]  = 'l.status = %s';
                    $values[] = 'redirect';
                    break;
                case 'timeout':
                    $where[]  = 'l.status = %s';
                    $values[] = 'timeout';
                    break;
                case 'ssl_error':
                    $where[]  = 'l.status = %s';
                    $values[] = 'ssl_error';
                    break;
                case 'dns_error':
                    $where[]  = 'l.status = %s';
                    $values[] = 'dns_error';
                    break;
                case 'forbidden':
                    $where[]  = 'l.status = %s';
                    $values[] = 'forbidden';
                    break;
                case 'internal':
                    $where[]  = 'l.link_type = %s';
                    $values[] = 'internal';
                    break;
                case 'external':
                    $where[]  = 'l.link_type = %s';
                    $values[] = 'external';
                    break;
                case 'image':
                    $where[]  = 'l.link_type = %s';
                    $values[] = 'image';
                    break;
                case 'anchor':
                    $where[]  = 'l.link_type = %s';
                    $values[] = 'anchor';
                    break;
                case 'ignored':
                    $where[]  = 'l.is_ignored = %d';
                    $values[] = 1;
                    break;
            }
        }

        // Exclude ignored by default (unless viewing ignored filter).
        if ( 'ignored' !== $args['status'] ) {
            $where[] = 'l.is_ignored = 0';
        }

        // Search by URL or domain.
        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $where[]  = '(l.url LIKE %s OR l.domain LIKE %s)';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Sanitize orderby to prevent SQL injection.
        $allowed_orderby = array(
            'url', 'status', 'http_code', 'last_checked', 'link_type', 'response_time',
        );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true )
            ? $args['orderby']
            : 'last_checked';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        // Count total matching records.
        $count_sql = "SELECT COUNT(*) FROM {$table_links} l WHERE {$where_sql}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, ...$values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        // Get paginated results.
        $per_page = absint( $args['per_page'] );
        $offset   = absint( $args['offset'] );

        $query    = "SELECT l.* FROM {$table_links} l WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        if ( ! empty( $values ) ) {
            $query = $wpdb->prepare( $query, ...$values );
        }

        $links = $wpdb->get_results( $query, ARRAY_A );

        return array(
            'items' => $links ?: array(),
            'total' => $total,
        );
    }

    /**
     * Delete all occurrences for a specific object.
     *
     * Used during re-scan to clear stale occurrence data before
     * recording newly extracted links.
     *
     * @param string $object_type The object type (post, page, etc.).
     * @param int    $object_id   The WordPress object ID.
     */
    public static function delete_occurrences_by_object( string $object_type, int $object_id ): void {
        global $wpdb;

        $table = self::table( 'occurrences' );

        $wpdb->delete(
            $table,
            array(
                'object_type' => $object_type,
                'object_id'   => $object_id,
            ),
            array( '%s', '%d' )
        );
    }

    /**
     * Backward-compatible alias for delete_occurrences_by_object.
     *
     * @param string $object_type The object type.
     * @param int    $object_id   The WordPress object ID.
     */
    public static function delete_occurrences_for_object( string $object_type, int $object_id ): void {
        self::delete_occurrences_by_object( $object_type, $object_id );
    }

    /**
     * Get dashboard statistics.
     *
     * @return array Associative array of stat counts.
     */
    public static function get_stats(): array {
        global $wpdb;

        $table = self::table( 'links' );

        $stats = array(
            'total'     => 0,
            'internal'  => 0,
            'external'  => 0,
            'broken'    => 0,
            'code_404'  => 0,
            'code_5xx'  => 0,
            'server_error' => 0,
            'redirect'  => 0,
            'timeout'   => 0,
            'ssl_error' => 0,
            'dns_error' => 0,
            'forbidden' => 0,
            'ignored'   => 0,
        );

        $stats['total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $stats['internal'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_type = %s", 'internal' )
        );
        $stats['external'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE link_type = %s", 'external' )
        );
        $stats['broken'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'broken' )
        );
        $stats['code_404'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE http_code = %d AND is_ignored = 0", 404 )
        );
        $stats['code_5xx'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE http_code >= 500 AND http_code < 600 AND is_ignored = 0"
        );
        $stats['server_error'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'server_error' )
        );
        $stats['redirect'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'redirect' )
        );
        $stats['timeout'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'timeout' )
        );
        $stats['ssl_error'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'ssl_error' )
        );
        $stats['dns_error'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'dns_error' )
        );
        $stats['forbidden'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s AND is_ignored = 0", 'forbidden' )
        );
        $stats['ignored'] = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_ignored = %d", 1 )
        );

        return $stats;
    }

    /**
     * Get the aggregate count of actionable link issues from a stats array.
     *
     * HTTP-code-derived counts such as 404 and 5xx are intentionally not added
     * here because they are subsets of status-derived issue counts.
     */
    public static function get_issue_total_from_stats( array $stats ): int {
        return (int) ( $stats['broken'] ?? 0 )
            + (int) ( $stats['server_error'] ?? 0 )
            + (int) ( $stats['timeout'] ?? 0 )
            + (int) ( $stats['ssl_error'] ?? 0 )
            + (int) ( $stats['dns_error'] ?? 0 )
            + (int) ( $stats['forbidden'] ?? 0 );
    }

    /**
     * Get occurrences for a specific link.
     *
     * @param int $link_id The link ID.
     * @return array Array of occurrence records.
     */
    public static function get_occurrences( int $link_id ): array {
        global $wpdb;

        $table = self::table( 'occurrences' );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE link_id = %d ORDER BY created_at DESC",
                $link_id
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get one representative occurrence and total occurrence count per link.
     *
     * This avoids repeated per-row occurrence queries in report rendering and
     * CSV export while still providing the first source context and (+N) count.
     *
     * @param array $link_ids Link IDs to summarize.
     * @return array Occurrence summaries keyed by link ID.
     */
    public static function get_occurrence_summaries( array $link_ids ): array {
        global $wpdb;

        $link_ids = array_values( array_unique( array_filter( array_map( 'absint', $link_ids ) ) ) );
        if ( empty( $link_ids ) ) {
            return array();
        }

        $table     = self::table( 'occurrences' );
        $summaries = array();

        foreach ( array_chunk( $link_ids, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT o.*, counts.occurrence_count
                     FROM {$table} o
                     INNER JOIN (
                         SELECT link_id, MAX(id) AS occurrence_id, COUNT(*) AS occurrence_count
                         FROM {$table}
                         WHERE link_id IN ({$placeholders})
                         GROUP BY link_id
                     ) counts ON o.id = counts.occurrence_id",
                    ...$chunk
                ),
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                continue;
            }

            foreach ( $rows as $row ) {
                $row['occurrence_count'] = (int) $row['occurrence_count'];
                $summaries[ (int) $row['link_id'] ] = $row;
            }
        }

        return $summaries;
    }

    /**
     * Get unchecked links that need HTTP verification.
     *
     * @param int $limit Maximum number of links to retrieve.
     * @return array Array of link records with status 'pending'.
     */
    public static function get_unchecked_links( int $limit = 20 ): array {
        global $wpdb;

        $table = self::table( 'links' );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND is_ignored = 0 ORDER BY first_seen ASC LIMIT %d",
                'pending',
                $limit
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Get broken links for rechecking.
     *
     * @param int $limit Maximum number of links to retrieve.
     * @return array Array of broken/error link records.
     */
    public static function get_broken_links( int $limit = 20 ): array {
        global $wpdb;

        $table = self::table( 'links' );
        $issue_statuses = self::get_issue_statuses();
        $placeholders   = implode( ', ', array_fill( 0, count( $issue_statuses ), '%s' ) );
        $values         = $issue_statuses;
        $values[]       = $limit;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status IN ({$placeholders}) AND is_ignored = 0 ORDER BY last_checked ASC LIMIT %d",
                ...$values
            ),
            ARRAY_A
        );

        return $results ?: array();
    }

    /**
     * Reset non-ignored links back to 'pending' so the scan pipeline rechecks them.
     *
     * Used by version upgrade routines when status classification logic changes.
     * Ignored links (is_ignored = 1) are left untouched so the user's ignore
     * choices are preserved. The existing queue/cron pipeline picks up pending
     * links via get_unchecked_links() and re-runs check_links_batch(), which
     * applies all settings and ignore lists correctly.
     *
     * @return int Number of links reset.
     */
    public static function reset_links_for_recheck(): int {
        global $wpdb;

        $table = self::table( 'links' );

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET status = %s WHERE is_ignored = 0",
                'pending'
            )
        );

        return (int) $updated;
    }

    /**
     * Set a link as ignored.
     *
     * @param int    $link_id The link ID.
     * @param string $reason  Ignore reason (user, domain_ignored, pattern_ignored).
     * @return bool True on success.
     */
    public static function ignore_link( int $link_id, string $reason = 'user' ): bool {
        global $wpdb;

        $table = self::table( 'links' );

        return (bool) $wpdb->update(
            $table,
            array(
                'is_ignored'    => 1,
                'ignore_reason' => $reason,
            ),
            array( 'id' => $link_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Remove ignored status from a link.
     *
     * @param int $link_id The link ID.
     * @return bool True on success.
     */
    public static function unignore_link( int $link_id ): bool {
        global $wpdb;

        $table = self::table( 'links' );

        return (bool) $wpdb->update(
            $table,
            array(
                'is_ignored'    => 0,
                'ignore_reason' => '',
            ),
            array( 'id' => $link_id ),
            array( '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get a single link by ID.
     *
     * @param int $link_id The link ID.
     * @return array|null Link record or null if not found.
     */
    public static function get_link( int $link_id ): ?array {
        global $wpdb;

        $table = self::table( 'links' );

        $result = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $link_id ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Store a reversible repair record.
     *
     * @param array $data Repair data.
     * @return int|false Repair ID on success, false on failure.
     */
    public static function insert_repair( array $data ): int|false {
        global $wpdb;

        $table       = self::table( 'repairs' );
        $old_content = (string) ( $data['old_content'] ?? '' );
        $new_content = (string) ( $data['new_content'] ?? '' );
        $now         = current_time( 'mysql' );

        $result = $wpdb->insert(
            $table,
            array(
                'action_type'      => sanitize_key( $data['action_type'] ?? '' ),
                'object_type'      => sanitize_key( $data['object_type'] ?? '' ),
                'object_id'        => absint( $data['object_id'] ?? 0 ),
                'source_title'     => sanitize_text_field( $data['source_title'] ?? '' ),
                'edit_url'         => esc_url_raw( $data['edit_url'] ?? '' ),
                'old_url'          => esc_url_raw( $data['old_url'] ?? '' ),
                'new_url'          => esc_url_raw( $data['new_url'] ?? '' ),
                'old_content'      => $old_content,
                'new_content'      => $new_content,
                'old_content_hash' => hash( 'sha256', $old_content ),
                'new_content_hash' => hash( 'sha256', $new_content ),
                'status'           => 'active',
                'rollback_message' => '',
                'user_id'          => get_current_user_id(),
                'rolled_back_by'   => 0,
                'created_at'       => $now,
                'rolled_back_at'   => '0000-00-00 00:00:00',
            ),
            array(
                '%s', '%s', '%d', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%d', '%d',
                '%s', '%s',
            )
        );

        return $result ? (int) $wpdb->insert_id : false;
    }

    /**
     * Get paginated repair history rows.
     *
     * @return array{items: array, total: int}
     */
    public static function get_repairs( int $page = 1, int $per_page = 20 ): array {
        global $wpdb;

        $table    = self::table( 'repairs' );
        $page     = max( 1, $page );
        $per_page = max( 1, $per_page );
        $offset   = ( $page - 1 ) * $per_page;

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, action_type, object_type, object_id, source_title, edit_url, old_url, new_url, status, rollback_message, user_id, rolled_back_by, created_at, rolled_back_at FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    /**
     * Get a repair history row by ID.
     */
    public static function get_repair( int $repair_id ): ?array {
        global $wpdb;

        $table = self::table( 'repairs' );

        $result = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $repair_id ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Mark a repair record as rolled back.
     */
    public static function mark_repair_rolled_back( int $repair_id, string $message = '' ): bool {
        global $wpdb;

        $table = self::table( 'repairs' );

        return (bool) $wpdb->update(
            $table,
            array(
                'status'           => 'rolled_back',
                'rollback_message' => sanitize_text_field( $message ),
                'rolled_back_by'   => get_current_user_id(),
                'rolled_back_at'   => current_time( 'mysql' ),
            ),
            array( 'id' => $repair_id ),
            array( '%s', '%s', '%d', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Clean up old rolled-back repair records.
     *
     * Active repair records are kept so their rollback snapshots remain
     * available until the administrator rolls them back or resets plugin data.
     *
     * @return int Number of deleted repair records.
     */
    public static function cleanup_repair_history( int $days = 180 ): int {
        global $wpdb;

        $days = absint( $days );
        if ( $days < 1 ) {
            return 0;
        }

        $table  = self::table( 'repairs' );
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = %s AND ((rolled_back_at IS NOT NULL AND rolled_back_at < %s) OR (rolled_back_at IS NULL AND created_at < %s))",
                'rolled_back',
                $cutoff,
                $cutoff
            )
        );

        return (int) $result;
    }

    /**
     * Clean up orphaned links (links with no occurrences).
     *
     * @return int Number of deleted link records.
     */
    public static function cleanup_orphaned_links(): int {
        global $wpdb;

        $table_links       = self::table( 'links' );
        $table_occurrences = self::table( 'occurrences' );

        $result = $wpdb->query(
            "DELETE l FROM {$table_links} l LEFT JOIN {$table_occurrences} o ON l.id = o.link_id WHERE o.id IS NULL"
        );

        return (int) $result;
    }
}
