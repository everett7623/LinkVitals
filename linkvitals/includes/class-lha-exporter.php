<?php
/**
 * CSV Exporter class
 *
 * Exports link report data as CSV file download.
 * Applies the same filters active in the report view at export time.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LHA_Exporter {

    /**
     * Export links as CSV file download.
     *
     * Queries all matching links (no pagination), retrieves the first occurrence
     * for each link to populate source context columns, and streams a CSV file
     * with proper HTTP headers for browser download.
     *
     * @param array $filters {
     *     Optional. Filters matching the report view.
     *
     *     @type string $status  Status filter (e.g., 'broken', '404', 'redirect').
     *     @type string $search  Search term to filter by URL or domain.
     *     @type string $orderby Sort column.
     *     @type string $order   Sort direction (ASC or DESC).
     * }
     */
    public static function export( array $filters = array() ): void {
        if ( ! LHA_Security::check_permission() ) {
            wp_die( esc_html__( 'Permission denied.', 'linkvitals' ) );
        }

        $defaults = array(
            'status'  => '',
            'search'  => '',
            'orderby' => 'last_checked',
            'order'   => 'DESC',
        );

        $filters = wp_parse_args( $filters, $defaults );

        // Get all matching links without pagination for export.
        $args = array(
            'status'   => $filters['status'],
            'search'   => $filters['search'],
            'orderby'  => $filters['orderby'],
            'order'    => $filters['order'],
            'per_page' => 10000,
            'offset'   => 0,
        );

        $data = LHA_DB::get_links( $args );

        // Build filename with site name and date.
        $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
        if ( empty( $site_name ) ) {
            $site_name = 'wordpress';
        }
        $filename = 'link-health-report-' . $site_name . '-' . gmdate( 'Y-m-d' ) . '.csv';

        // Set HTTP headers for CSV download.
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel compatibility.
        fwrite( $output, "\xEF\xBB\xBF" );

        // Header row matching requirement 18.2.
        fputcsv( $output, array(
            'status',
            'url',
            'link_type',
            'source_title',
            'source_type',
            'edit_url',
            'anchor_text',
            'http_code',
            'error_type',
            'final_url',
            'last_checked',
        ) );

        $occurrence_summaries = LHA_DB::get_occurrence_summaries(
            array_map( 'absint', array_column( $data['items'], 'id' ) )
        );

        // Data rows.
        foreach ( $data['items'] as $link ) {
            $first_occurrence = $occurrence_summaries[ (int) $link['id'] ] ?? array();

            fputcsv( $output, array(
                $link['status'] ?? '',
                $link['url'] ?? '',
                $link['link_type'] ?? '',
                $first_occurrence['source_title'] ?? '',
                $first_occurrence['object_type'] ?? '',
                $first_occurrence['edit_url'] ?? '',
                $first_occurrence['anchor_text'] ?? '',
                $link['http_code'] ?? '',
                $link['error_type'] ?? '',
                $link['final_url'] ?? '',
                $link['last_checked'] ?? '',
            ) );
        }

        fclose( $output );
        exit;
    }
}
