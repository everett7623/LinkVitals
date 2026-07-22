<?php
/**
 * Verify network uninstall honors each site's retention setting.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "WordPress must be loaded before verifying multisite uninstall.\n" );
    exit( 1 );
}

/** Fail when multisite uninstall does not match the per-site policy. */
function lha_multisite_assert_uninstalled( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

$expectations = get_site_option( 'lha_integration_multisite_expectations', array() );
lha_multisite_assert_uninstalled( ! empty( $expectations['delete'] ), 'Delete-site expectations are missing.' );
lha_multisite_assert_uninstalled( ! empty( $expectations['preserve'] ), 'Preserve-site expectations are missing.' );

foreach ( array( 'delete', 'preserve' ) as $policy ) {
    foreach ( $expectations[ $policy ] as $site_id ) {
        switch_to_blog( (int) $site_id );
        try {
            global $wpdb;

            foreach ( array( 'links', 'occurrences', 'queue', 'logs', 'repairs' ) as $suffix ) {
                $table = $wpdb->prefix . 'lha_' . $suffix;
                $found = $wpdb->get_var(
                    $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
                );
                if ( 'delete' === $policy ) {
                    lha_multisite_assert_uninstalled( null === $found, "Uninstall left {$table} behind." );
                } else {
                    lha_multisite_assert_uninstalled( $found === $table, "Uninstall removed retained table {$table}." );
                }
            }

            if ( 'delete' === $policy ) {
                lha_multisite_assert_uninstalled( false === get_option( 'lha_settings', false ), "Site {$site_id} settings remain." );
                lha_multisite_assert_uninstalled( false === get_transient( 'lha_ai_job_multisite' ), "Site {$site_id} AI transient remains." );
            } else {
                $settings = get_option( 'lha_settings', false );
                lha_multisite_assert_uninstalled( is_array( $settings ), "Site {$site_id} retained settings were removed." );
                lha_multisite_assert_uninstalled( empty( $settings['delete_data_on_uninstall'] ), "Site {$site_id} retention policy changed." );
                lha_multisite_assert_uninstalled( false !== get_transient( 'lha_ai_job_multisite' ), "Site {$site_id} retained AI transient was removed." );
            }
        } finally {
            restore_current_blog();
        }
    }
}

delete_site_option( 'lha_integration_multisite_expectations' );
echo "LinkVitals multisite uninstall checks passed.\n";
