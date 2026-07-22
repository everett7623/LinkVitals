<?php
/**
 * Real WordPress integration smoke tests, executed through wp eval-file.
 *
 * @package LinkVitals
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "WordPress must be loaded before running integration tests.\n" );
    exit( 1 );
}

/** Fail the integration run when a contract is not satisfied. */
function lha_integration_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

/** Deterministically fail extraction when a fixture marker is present. */
final class LHA_Integration_Failing_Extractor extends LHA_Link_Extractor {
    public function extract( string $content, string $source_url = '' ): array {
        if ( str_contains( $content, 'LHA_FORCE_EXTRACTION_FAILURE' ) ) {
            throw new RuntimeException( 'Forced integration extraction failure.' );
        }

        return parent::extract( $content, $source_url );
    }
}

/** Process one real WordPress object through the scanner extraction path. */
function lha_integration_process_object( LHA_Scanner $scanner, string $object_type, int $object_id ): void {
    $method = new ReflectionMethod( LHA_Scanner::class, 'process_queue_item' );
    $method->setAccessible( true );
    $processed = $method->invoke(
        $scanner,
        array(
            'object_type' => $object_type,
            'object_id'   => $object_id,
        )
    );

    lha_integration_assert( true === $processed, "Scanner failed to process {$object_type} #{$object_id}." );
}

/** Count occurrences for one normalized URL and source object. */
function lha_integration_occurrence_count( string $url, string $object_type, int $object_id ): int {
    global $wpdb;

    $links       = LHA_DB::table( 'links' );
    $occurrences = LHA_DB::table( 'occurrences' );
    $url_hash    = hash( 'sha256', LHA_DB::normalize_url( $url ) );

    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$occurrences} o INNER JOIN {$links} l ON l.id = o.link_id WHERE l.url_hash = %s AND o.object_type = %s AND o.object_id = %d",
            $url_hash,
            $object_type,
            $object_id
        )
    );
}

/** Check whether a full scan queued a specific source object. */
function lha_integration_queue_contains( string $object_type, int $object_id ): bool {
    global $wpdb;

    $queue = LHA_DB::table( 'queue' );
    $count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue} WHERE object_type = %s AND object_id = %d",
            $object_type,
            $object_id
        )
    );

    return (int) $count > 0;
}

/** Count pending queue rows for one source object, excluding historical done rows. */
function lha_integration_pending_queue_count( string $object_type, int $object_id ): int {
    global $wpdb;

    $queue = LHA_DB::table( 'queue' );
    return (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$queue} WHERE object_type = %s AND object_id = %d AND status = %s",
            $object_type,
            $object_id,
            'pending'
        )
    );
}

/** Move a post's modification boundary beyond a completed content cursor. */
function lha_integration_force_modified_after( int $post_id, string $cursor ): void {
    global $wpdb;

    $cursor_timestamp = strtotime( $cursor );
    lha_integration_assert( false !== $cursor_timestamp, 'The content cursor is not a valid timestamp.' );
    $modified = gmdate( 'Y-m-d H:i:s', $cursor_timestamp + 2 );
    $updated  = $wpdb->update(
        $wpdb->posts,
        array(
            'post_modified'     => $modified,
            'post_modified_gmt' => $modified,
        ),
        array( 'ID' => $post_id ),
        array( '%s', '%s' ),
        array( '%d' )
    );

    lha_integration_assert( false !== $updated, 'Could not advance the post modification boundary.' );
    clean_post_cache( $post_id );
}

global $wpdb;

lha_integration_assert( class_exists( LHA_DB::class ), 'Plugin classes were not loaded.' );
lha_integration_assert( get_option( 'lha_version' ) === LHA_VERSION, 'Activation did not store the plugin version.' );
lha_integration_assert( get_option( 'lha_scan_status' ) === 'idle', 'Activation did not initialize scan status.' );

$settings = get_option( 'lha_settings', array() );
lha_integration_assert( is_array( $settings ), 'Activation did not create plugin settings.' );
lha_integration_assert( 20 === ( $settings['batch_size'] ?? null ), 'Activation defaults are incomplete.' );
lha_integration_assert( false !== wp_next_scheduled( 'lha_process_queue' ), 'Activation did not schedule the queue worker.' );

foreach ( array( 'links', 'occurrences', 'queue', 'logs', 'repairs' ) as $suffix ) {
    $table = LHA_DB::table( $suffix );
    $found = $wpdb->get_var(
        $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
    );
    lha_integration_assert( $found === $table, "Activation did not create {$table}." );
}

$queue = new LHA_Queue();
lha_integration_assert( $queue->clear(), 'Could not clear the integration queue.' );

$normal_id = $queue->add( 'post', 91001, 'https://example.test/normal', 5 );
$urgent_id = $queue->add( 'post', 91002, 'https://example.test/urgent', 1 );
lha_integration_assert( is_int( $normal_id ) && $normal_id > 0, 'Could not insert the normal queue item.' );
lha_integration_assert( is_int( $urgent_id ) && $urgent_id > 0, 'Could not insert the urgent queue item.' );
lha_integration_assert(
    $urgent_id === $queue->add( 'post', 91002, 'https://example.test/urgent', 1 ),
    'Queue duplicate detection returned a different item.'
);

$first_claim  = $queue->get_pending( 1 );
$second_claim = $queue->get_pending( 1 );
$third_claim  = $queue->get_pending( 1 );

lha_integration_assert( 1 === count( $first_claim ), 'The first worker did not claim exactly one item.' );
lha_integration_assert( 1 === count( $second_claim ), 'The second worker did not claim exactly one item.' );
lha_integration_assert( array() === $third_claim, 'A queue item was claimed more than once.' );
lha_integration_assert( 91002 === (int) $first_claim[0]['object_id'], 'Queue priority ordering is incorrect.' );
lha_integration_assert( 91001 === (int) $second_claim[0]['object_id'], 'The second queue claim is incorrect.' );
lha_integration_assert(
    '' !== $first_claim[0]['claim_token'] && $first_claim[0]['claim_token'] !== $second_claim[0]['claim_token'],
    'Queue workers did not receive isolated claim tokens.'
);

$queue_table = LHA_DB::table( 'queue' );
lha_integration_assert( $queue->clear(), 'Could not clear the queue before retry tests.' );
$retry_id = $queue->add( 'post', 92001, 'https://example.test/retry', 1 );
lha_integration_assert( is_int( $retry_id ) && $retry_id > 0, 'Could not create the retry fixture.' );

for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
    $retry_claim = $queue->get_pending( 1 );
    lha_integration_assert( 1 === count( $retry_claim ), "Retry attempt {$attempt} was not claimed." );
    lha_integration_assert( $retry_id === (int) $retry_claim[0]['id'], "Retry attempt {$attempt} claimed the wrong item." );
    lha_integration_assert( '' !== $retry_claim[0]['claim_token'], "Retry attempt {$attempt} has no claim token." );

    $queue->increment_attempts( $retry_id, "integration failure {$attempt}" );
    $retry_row = $wpdb->get_row(
        $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $retry_id ),
        ARRAY_A
    );
    $expected_status = $attempt < 3 ? 'pending' : 'failed';
    lha_integration_assert( $attempt === (int) $retry_row['attempts'], "Retry attempt {$attempt} was not persisted." );
    lha_integration_assert( $expected_status === $retry_row['status'], "Retry attempt {$attempt} has the wrong status." );
    lha_integration_assert( '' === $retry_row['claim_token'], "Retry attempt {$attempt} left its claim token behind." );
    lha_integration_assert( "integration failure {$attempt}" === $retry_row['last_error'], "Retry attempt {$attempt} lost its error." );
}

lha_integration_assert( array() === $queue->get_pending( 1 ), 'A terminally failed item was claimed again.' );
$retry_counts = $queue->get_counts();
lha_integration_assert( 1 === $retry_counts['failed'], 'The terminal failure was not included in queue counts.' );
lha_integration_assert( 0 === $retry_counts['pending'], 'The terminal failure remained pending.' );
lha_integration_assert( 0 === $retry_counts['processing'], 'The terminal failure remained processing.' );

lha_integration_assert( $queue->clear(), 'Could not clear the queue before stuck-claim tests.' );
$stale_id = $queue->add( 'post', 93001, 'https://example.test/stale', 1 );
$fresh_id = $queue->add( 'post', 93002, 'https://example.test/fresh', 2 );
lha_integration_assert( is_int( $stale_id ) && is_int( $fresh_id ), 'Could not create stuck-claim fixtures.' );
$processing_claims = $queue->get_pending( 2 );
lha_integration_assert( 2 === count( $processing_claims ), 'Could not claim both stuck-claim fixtures.' );
$initial_claim_token = (string) $processing_claims[0]['claim_token'];
lha_integration_assert( '' !== $initial_claim_token, 'The initial stuck-claim token is empty.' );
lha_integration_assert( $initial_claim_token === $processing_claims[1]['claim_token'], 'One worker batch received multiple claim tokens.' );

$stale_time = gmdate( 'Y-m-d H:i:s', time() - ( 20 * MINUTE_IN_SECONDS ) );
$wpdb->update(
    $queue_table,
    array( 'updated_at' => $stale_time ),
    array( 'id' => $stale_id ),
    array( '%s' ),
    array( '%d' )
);
$reset_count = $queue->reset_stuck( 10 );
lha_integration_assert( 1 === $reset_count, 'Stuck recovery reset the wrong number of items.' );

$stale_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $stale_id ), ARRAY_A );
$fresh_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $fresh_id ), ARRAY_A );
lha_integration_assert( 'pending' === $stale_row['status'], 'The stale claim was not returned to pending.' );
lha_integration_assert( '' === $stale_row['claim_token'], 'The stale claim token was not cleared.' );
lha_integration_assert( 'processing' === $fresh_row['status'], 'Fresh processing work was reset as stuck.' );
lha_integration_assert( $initial_claim_token === $fresh_row['claim_token'], 'Fresh processing work lost its claim token.' );

$reclaimed = $queue->get_pending( 1 );
lha_integration_assert( 1 === count( $reclaimed ) && $stale_id === (int) $reclaimed[0]['id'], 'The stale item was not reclaimable.' );
lha_integration_assert( $initial_claim_token !== $reclaimed[0]['claim_token'], 'The reclaimed item reused its stale claim token.' );

lha_integration_assert( $queue->clear(), 'Could not clear the queue before scanner failure tests.' );
$preserved_url = 'mailto:preserved@example.test';
$successful_url = 'mailto:successful@example.test';
$failed_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Extraction Failure Fixture',
        'post_content' => '<p><a href="' . $preserved_url . '">Preserved link</a></p>',
    ),
    true
);
$successful_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Extraction Success Fixture',
        'post_content' => '<p><a href="' . $successful_url . '">Successful link</a></p>',
    ),
    true
);
lha_integration_assert( is_int( $failed_post_id ) && $failed_post_id > 0, 'Could not create the extraction failure fixture.' );
lha_integration_assert( is_int( $successful_post_id ) && $successful_post_id > 0, 'Could not create the extraction success fixture.' );

$seed_scanner = new LHA_Scanner();
lha_integration_process_object( $seed_scanner, 'post', $failed_post_id );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $preserved_url, 'post', $failed_post_id ),
    'Could not seed the last known-good occurrence before extraction failure.'
);

$failed_update = wp_update_post(
    array(
        'ID'           => $failed_post_id,
        'post_content' => '<p>LHA_FORCE_EXTRACTION_FAILURE</p>',
    ),
    true
);
lha_integration_assert( ! is_wp_error( $failed_update ), 'Could not arm the extraction failure fixture.' );

$settings['batch_size'] = 2;
update_option( 'lha_settings', $settings );
$successful_queue_id = $queue->add( 'post', $successful_post_id, get_permalink( $successful_post_id ) ?: '', 1 );
$failed_queue_id = $queue->add( 'post', $failed_post_id, get_permalink( $failed_post_id ) ?: '', 1 );
lha_integration_assert( is_int( $successful_queue_id ) && is_int( $failed_queue_id ), 'Could not queue the mixed scanner fixtures.' );

LHA_Scanner::record_scan_start( 'full' );
$mixed_scanner = new LHA_Scanner();
$extractor_property = new ReflectionProperty( LHA_Scanner::class, 'extractor' );
$extractor_property->setAccessible( true );
$extractor_property->setValue( $mixed_scanner, new LHA_Integration_Failing_Extractor() );

$mixed_result = $mixed_scanner->process_queue_batch();
lha_integration_assert( 'running' === $mixed_result['status'] && 2 === $mixed_result['processed'], 'The mixed scanner batch did not process both items.' );
$successful_queue_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $successful_queue_id ), ARRAY_A );
$failed_queue_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $failed_queue_id ), ARRAY_A );
lha_integration_assert( 'done' === $successful_queue_row['status'], 'A successful item in the mixed batch was not completed.' );
lha_integration_assert( 0 === (int) $successful_queue_row['attempts'], 'A successful item in the mixed batch gained a retry.' );
lha_integration_assert( 'pending' === $failed_queue_row['status'], 'The extraction failure did not return to pending.' );
lha_integration_assert( 1 === (int) $failed_queue_row['attempts'], 'The extraction failure did not record its first attempt.' );
lha_integration_assert( 'Forced integration extraction failure.' === $failed_queue_row['last_error'], 'The scanner did not persist the extraction error.' );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $successful_url, 'post', $successful_post_id ),
    'The successful item in the mixed batch lost its extracted occurrence.'
);
lha_integration_assert(
    1 === lha_integration_occurrence_count( $preserved_url, 'post', $failed_post_id ),
    'Extraction failure discarded the last known-good occurrence.'
);

for ( $attempt = 2; $attempt <= 3; $attempt++ ) {
    $retry_result = $mixed_scanner->process_queue_batch();
    lha_integration_assert( 'running' === $retry_result['status'] && 1 === $retry_result['processed'], "Scanner retry {$attempt} did not process the failed item." );
    $failed_queue_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$queue_table} WHERE id = %d", $failed_queue_id ), ARRAY_A );
    $expected_status = $attempt < 3 ? 'pending' : 'failed';
    lha_integration_assert( $expected_status === $failed_queue_row['status'], "Scanner retry {$attempt} has the wrong status." );
    lha_integration_assert( $attempt === (int) $failed_queue_row['attempts'], "Scanner retry {$attempt} was not persisted." );
    lha_integration_assert( 'Forced integration extraction failure.' === $failed_queue_row['last_error'], "Scanner retry {$attempt} lost its error." );
}

$mixed_completion = $mixed_scanner->process_queue_batch();
lha_integration_assert( 'completed' === $mixed_completion['status'], 'A terminal extraction failure prevented scan completion.' );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $preserved_url, 'post', $failed_post_id ),
    'Scanner retries modified the preserved occurrence.'
);

wp_delete_post( $failed_post_id, true );
wp_delete_post( $successful_post_id, true );
LHA_DB::delete_occurrences_by_object( 'post', $failed_post_id );
LHA_DB::delete_occurrences_by_object( 'post', $successful_post_id );
LHA_DB::cleanup_orphaned_links();
lha_integration_assert( $queue->clear(), 'Could not clear the queue after scanner failure tests.' );
$settings['batch_size'] = 20;
update_option( 'lha_settings', $settings );

$subscriber_id = username_exists( 'lha-integration-subscriber' );
if ( ! $subscriber_id ) {
    $subscriber_id = wp_create_user( 'lha-integration-subscriber', wp_generate_password( 24 ), 'subscriber@example.test' );
}
lha_integration_assert( is_int( $subscriber_id ) && $subscriber_id > 0, 'Could not create the subscriber fixture.' );

wp_set_current_user( $subscriber_id );
lha_integration_assert( ! LHA_Security::check_permission(), 'A subscriber received AJAX administration permission.' );

$administrator_ids = get_users(
    array(
        'role'   => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    )
);
lha_integration_assert( ! empty( $administrator_ids ), 'The WordPress administrator fixture is missing.' );
wp_set_current_user( (int) $administrator_ids[0] );
lha_integration_assert( LHA_Security::check_permission(), 'An administrator failed the AJAX permission check.' );

$_REQUEST['nonce'] = wp_create_nonce( 'lha_ajax_nonce' );
lha_integration_assert( LHA_Security::verify_ajax_nonce(), 'A valid AJAX nonce was rejected.' );
$_REQUEST['nonce'] = 'invalid-integration-nonce';
lha_integration_assert( ! LHA_Security::verify_ajax_nonce(), 'An invalid AJAX nonce was accepted.' );
unset( $_REQUEST['nonce'] );

$batch_url = 'https://batch.example.test/missing';
$batch_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Background Batch Fixture',
        'post_content' => '<p><a href="' . $batch_url . '">Known missing URL</a></p>',
    ),
    true
);
lha_integration_assert( is_int( $batch_post_id ) && $batch_post_id > 0, 'Could not create the background batch fixture.' );

$settings['batch_size']          = 1;
$settings['email_notifications'] = 1;
$settings['notification_email']  = get_option( 'admin_email' );
update_option( 'lha_settings', $settings );

$GLOBALS['lha_integration_http_requests'] = 0;
$GLOBALS['lha_integration_mail_count']    = 0;
$GLOBALS['lha_integration_http_urls']     = array( $batch_url );
$http_filter = static function( mixed $response, array $request, string $url ): mixed {
    unset( $request );
    if ( ! in_array( $url, $GLOBALS['lha_integration_http_urls'], true ) ) {
        return $response;
    }

    $GLOBALS['lha_integration_http_requests']++;
    return array(
        'headers'  => array( 'content-type' => 'text/html; charset=UTF-8' ),
        'body'     => '',
        'response' => array( 'code' => 404, 'message' => 'Not Found' ),
        'cookies'  => array(),
        'filename' => null,
    );
};
$mail_filter = static function( mixed $return, array $mail ): bool {
    unset( $return );
    $GLOBALS['lha_integration_mail_count']++;
    lha_integration_assert( str_contains( $mail['subject'], 'Link Health Audit Report' ), 'Notification subject is incorrect.' );
    return true;
};
add_filter( 'pre_http_request', $http_filter, 10, 3 );
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

LHA_Cron::begin_notification_tracking( true );
$background_scanner = new LHA_Scanner();
$background_result  = $background_scanner->start_full_scan();
lha_integration_assert( 'started' === $background_result['status'], 'The background full scan did not start.' );

$cron       = new LHA_Cron();
$iterations = 0;
while ( 'running' === get_option( 'lha_scan_status' ) && $iterations < 50 ) {
    $cron->process_queue();
    $iterations++;
}

lha_integration_assert( $iterations > 1, 'The batch-size fixture did not require multiple Cron batches.' );
lha_integration_assert( $iterations < 50, 'The background scan did not converge.' );
lha_integration_assert( 'completed' === get_option( 'lha_scan_status' ), 'The background scan did not complete.' );
lha_integration_assert( 1 === $GLOBALS['lha_integration_http_requests'], 'The pending URL was not checked exactly once.' );
lha_integration_assert( 1 === $GLOBALS['lha_integration_mail_count'], 'Scan completion did not send exactly one email.' );

$links_table = LHA_DB::table( 'links' );
$batch_link = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$links_table} WHERE url_hash = %s",
        hash( 'sha256', LHA_DB::normalize_url( $batch_url ) )
    ),
    ARRAY_A
);
lha_integration_assert( is_array( $batch_link ), 'The background link result is missing.' );
lha_integration_assert( 'broken' === $batch_link['status'], 'The deterministic 404 was not classified as broken.' );
lha_integration_assert( 404 === (int) $batch_link['http_code'], 'The deterministic HTTP code was not stored.' );
lha_integration_assert( 1 === (int) $batch_link['check_count'], 'The background link check count is incorrect.' );

$queue_counts = ( new LHA_Queue() )->get_counts();
lha_integration_assert( 0 === $queue_counts['pending'], 'Completed scan left pending queue items.' );
lha_integration_assert( 0 === $queue_counts['processing'], 'Completed scan left claimed queue items.' );
lha_integration_assert( false !== get_option( 'lha_last_scan_time', false ), 'Completed scan did not store its completion time.' );
lha_integration_assert( false !== get_option( 'lha_content_scan_cursor', false ), 'Completed scan did not promote its content cursor.' );
lha_integration_assert( false === get_transient( 'lha_pre_scan_broken_count' ), 'Completion did not consume the notification baseline.' );
lha_integration_assert( false === get_option( 'lha_notification_lock', false ), 'Completion left the notification lock behind.' );

$logs_table = LHA_DB::table( 'logs' );
$notification_logs = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'notification' )
);
lha_integration_assert( 1 === $notification_logs, 'Notification success was not logged exactly once.' );

$cron->process_queue();
LHA_Cron::complete_notification_tracking();
lha_integration_assert( 1 === $GLOBALS['lha_integration_mail_count'], 'A second completion path sent a duplicate email.' );
$notification_logs = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'notification' )
);
lha_integration_assert( 1 === $notification_logs, 'A second completion path wrote a duplicate notification log.' );

$scheduled_logs_before = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'scheduled_scan' )
);
$completed_at_before = get_option( 'lha_last_scan_time' );
$started_at_before   = get_option( 'lha_scan_started_at' );
$cursor_before       = get_option( 'lha_content_scan_cursor' );
$settings['auto_scan'] = 1;
update_option( 'lha_settings', $settings );

$cron->run_scheduled_scan();

lha_integration_assert( 'completed' === get_option( 'lha_scan_status' ), 'No-change scheduled scan changed the completed status.' );
lha_integration_assert( $completed_at_before === get_option( 'lha_last_scan_time' ), 'No-change scheduled scan changed completion time.' );
lha_integration_assert( $started_at_before === get_option( 'lha_scan_started_at' ), 'No-change scheduled scan changed start time.' );
lha_integration_assert( $cursor_before === get_option( 'lha_content_scan_cursor' ), 'No-change scheduled scan advanced the content cursor.' );
lha_integration_assert( false === get_transient( 'lha_pre_scan_broken_count' ), 'No-change scheduled scan left a notification baseline.' );
lha_integration_assert( 1 === $GLOBALS['lha_integration_mail_count'], 'No-change scheduled scan sent an email.' );
$scheduled_logs_after = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'scheduled_scan' )
);
lha_integration_assert( $scheduled_logs_before === $scheduled_logs_after, 'No-change scheduled scan logged a scan start.' );

remove_filter( 'pre_http_request', $http_filter, 10 );
remove_filter( 'pre_wp_mail', $mail_filter, 10 );
$settings['auto_scan']           = 0;
$settings['email_notifications'] = 0;
update_option( 'lha_settings', $settings );

$incremental_changed_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Changed Incremental Fixture',
        'post_content' => '<p>Initial content without links.</p>',
    ),
    true
);
$incremental_unchanged_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Unchanged Incremental Fixture',
        'post_content' => '<p>This content remains unchanged.</p>',
    ),
    true
);
lha_integration_assert( is_int( $incremental_changed_id ) && $incremental_changed_id > 0, 'Could not create the changed incremental fixture.' );
lha_integration_assert( is_int( $incremental_unchanged_id ) && $incremental_unchanged_id > 0, 'Could not create the unchanged incremental fixture.' );

$pause_scanner = new LHA_Scanner();
$pause_result  = $pause_scanner->start_full_scan();
lha_integration_assert( 'started' === $pause_result['status'], 'The pause/resume scan did not start.' );
$cron->process_queue();
$pause_scanner->pause();
$paused_progress = $pause_scanner->get_progress();
lha_integration_assert( 'paused' === $paused_progress['status'], 'The scan did not enter paused state.' );

$cron->process_queue();
$still_paused_progress = $pause_scanner->get_progress();
lha_integration_assert( $paused_progress === $still_paused_progress, 'Cron advanced queue state while the scan was paused.' );

$pause_scanner->resume();
lha_integration_assert( 'running' === get_option( 'lha_scan_status' ), 'The scan did not resume.' );
$resume_iterations = 0;
while ( 'running' === get_option( 'lha_scan_status' ) && $resume_iterations < 50 ) {
    $cron->process_queue();
    $resume_iterations++;
}
lha_integration_assert( $resume_iterations > 0 && $resume_iterations < 50, 'The resumed scan did not converge.' );
lha_integration_assert( 'completed' === get_option( 'lha_scan_status' ), 'The resumed scan did not complete.' );

$incremental_url = 'https://batch.example.test/incremental-missing';
$incremental_update = wp_update_post(
    array(
        'ID'           => $incremental_changed_id,
        'post_content' => '<p><a href="' . $incremental_url . '">New issue</a></p>',
    ),
    true
);
lha_integration_assert( ! is_wp_error( $incremental_update ), 'Could not modify the incremental fixture.' );
lha_integration_force_modified_after(
    $incremental_changed_id,
    (string) get_option( 'lha_content_scan_cursor' )
);

$GLOBALS['lha_integration_http_urls'][] = $incremental_url;
$settings['auto_scan']           = 1;
$settings['email_notifications'] = 1;
update_option( 'lha_settings', $settings );
add_filter( 'pre_http_request', $http_filter, 10, 3 );
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

$first_incremental_mail_before = $GLOBALS['lha_integration_mail_count'];
$first_incremental_http_before = $GLOBALS['lha_integration_http_requests'];
$incremental_logs_before = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'scheduled_scan' )
);
$cron->run_scheduled_scan();
lha_integration_assert( 'running' === get_option( 'lha_scan_status' ), 'The changed incremental scan did not start.' );
lha_integration_assert( 1 === lha_integration_pending_queue_count( 'post', $incremental_changed_id ), 'The changed post was not queued.' );
lha_integration_assert( 0 === lha_integration_pending_queue_count( 'post', $incremental_unchanged_id ), 'An unchanged post was queued.' );

$first_incremental_iterations = 0;
while ( 'running' === get_option( 'lha_scan_status' ) && $first_incremental_iterations < 50 ) {
    $cron->process_queue();
    $first_incremental_iterations++;
}
lha_integration_assert( $first_incremental_iterations > 0 && $first_incremental_iterations < 50, 'The first incremental scan did not converge.' );
lha_integration_assert( 'completed' === get_option( 'lha_scan_status' ), 'The first incremental scan did not complete.' );
lha_integration_assert( $first_incremental_http_before + 1 === $GLOBALS['lha_integration_http_requests'], 'The new incremental issue was not checked exactly once.' );
lha_integration_assert( $first_incremental_mail_before + 1 === $GLOBALS['lha_integration_mail_count'], 'The new incremental issue did not send one notification.' );

$incremental_link = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$links_table} WHERE url_hash = %s",
        hash( 'sha256', LHA_DB::normalize_url( $incremental_url ) )
    ),
    ARRAY_A
);
lha_integration_assert( is_array( $incremental_link ) && 'broken' === $incremental_link['status'], 'The incremental 404 result is missing.' );

$second_incremental_update = wp_update_post(
    array(
        'ID'           => $incremental_changed_id,
        'post_content' => '<p><a href="' . $incremental_url . '">Same issue, revised text</a></p>',
    ),
    true
);
lha_integration_assert( ! is_wp_error( $second_incremental_update ), 'Could not revise the incremental fixture.' );
lha_integration_force_modified_after(
    $incremental_changed_id,
    (string) get_option( 'lha_content_scan_cursor' )
);

$second_incremental_mail_before = $GLOBALS['lha_integration_mail_count'];
$second_incremental_http_before = $GLOBALS['lha_integration_http_requests'];
$cron->run_scheduled_scan();
lha_integration_assert( 'running' === get_option( 'lha_scan_status' ), 'The second incremental scan did not start.' );
lha_integration_assert( 1 === lha_integration_pending_queue_count( 'post', $incremental_changed_id ), 'The revised post was not queued.' );
lha_integration_assert( 0 === lha_integration_pending_queue_count( 'post', $incremental_unchanged_id ), 'The unchanged post was queued on the second scan.' );

$second_incremental_iterations = 0;
while ( 'running' === get_option( 'lha_scan_status' ) && $second_incremental_iterations < 50 ) {
    $cron->process_queue();
    $second_incremental_iterations++;
}
lha_integration_assert( $second_incremental_iterations > 0 && $second_incremental_iterations < 50, 'The second incremental scan did not converge.' );
lha_integration_assert( 'completed' === get_option( 'lha_scan_status' ), 'The second incremental scan did not complete.' );
lha_integration_assert( $second_incremental_http_before === $GLOBALS['lha_integration_http_requests'], 'An unchanged issue was checked again.' );
lha_integration_assert( $second_incremental_mail_before === $GLOBALS['lha_integration_mail_count'], 'An unchanged issue sent another notification.' );
lha_integration_assert( false === get_transient( 'lha_pre_scan_broken_count' ), 'The second incremental scan left a notification baseline.' );

$incremental_logs_after = (int) $wpdb->get_var(
    $wpdb->prepare( "SELECT COUNT(*) FROM {$logs_table} WHERE action_type = %s", 'scheduled_scan' )
);
lha_integration_assert( $incremental_logs_before + 2 === $incremental_logs_after, 'Changed incremental scans were not logged.' );

remove_filter( 'pre_http_request', $http_filter, 10 );
remove_filter( 'pre_wp_mail', $mail_filter, 10 );
$settings['batch_size']          = 20;
$settings['auto_scan']           = 0;
$settings['email_notifications'] = 0;
update_option( 'lha_settings', $settings );
wp_delete_post( $batch_post_id, true );
wp_delete_post( $incremental_changed_id, true );
wp_delete_post( $incremental_unchanged_id, true );

$shared_url   = 'https://content.example.test/shared';
$taxonomy_url = 'https://content.example.test/taxonomy';
$menu_url     = 'https://content.example.test/menu';
$new_menu_url = 'https://content.example.test/menu-updated';

$post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Integration Post',
        'post_content' => '<p><a href="' . $shared_url . '">First</a> and <a href="' . $shared_url . '">Second</a></p>',
    ),
    true
);
lha_integration_assert( is_int( $post_id ) && $post_id > 0, 'Could not create the post fixture.' );

$term_result = wp_insert_term(
    'LinkVitals Integration Category',
    'category',
    array( 'description' => '<a href="' . $taxonomy_url . '">Taxonomy link</a>' )
);
lha_integration_assert( is_array( $term_result ), 'Could not create the taxonomy fixture.' );
$term_id = (int) $term_result['term_id'];

$menu_id = wp_create_nav_menu( 'LinkVitals Integration Menu' );
lha_integration_assert( is_int( $menu_id ) && $menu_id > 0, 'Could not create the menu fixture.' );
$menu_item_id = wp_update_nav_menu_item(
    $menu_id,
    0,
    array(
        'menu-item-title'  => 'Integration Link',
        'menu-item-url'    => $menu_url,
        'menu-item-status' => 'publish',
        'menu-item-type'   => 'custom',
    )
);
lha_integration_assert( is_int( $menu_item_id ) && $menu_item_id > 0, 'Could not create the menu-link fixture.' );

$scanner     = new LHA_Scanner();
$scan_result = $scanner->start_full_scan();
lha_integration_assert( 'started' === $scan_result['status'], 'The full scan did not start.' );
lha_integration_assert( lha_integration_queue_contains( 'post', $post_id ), 'The full scan did not queue the post fixture.' );
lha_integration_assert( lha_integration_queue_contains( 'taxonomy', $term_id ), 'The full scan did not queue the taxonomy fixture.' );
lha_integration_assert( lha_integration_queue_contains( 'nav_menu_item', $menu_item_id ), 'The full scan did not queue the menu fixture.' );
lha_integration_assert( $queue->clear(), 'Could not clear the populated full-scan queue.' );

lha_integration_process_object( $scanner, 'post', $post_id );
lha_integration_assert(
    2 === lha_integration_occurrence_count( $shared_url, 'post', $post_id ),
    'Duplicate post links were not stored as separate occurrences.'
);

$updated = wp_update_post(
    array(
        'ID'           => $post_id,
        'post_content' => '<p><a href="' . $shared_url . '">Only occurrence</a></p>',
    ),
    true
);
lha_integration_assert( ! is_wp_error( $updated ), 'Could not update the post fixture.' );
lha_integration_process_object( $scanner, 'post', $post_id );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $shared_url, 'post', $post_id ),
    'Rescanning did not replace the post occurrence set.'
);

lha_integration_process_object( $scanner, 'taxonomy', $term_id );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $taxonomy_url, 'taxonomy', $term_id ),
    'The taxonomy-description link was not extracted.'
);
$term_update = wp_update_term( $term_id, 'category', array( 'description' => '' ) );
lha_integration_assert( is_array( $term_update ), 'Could not clear the taxonomy description.' );
lha_integration_process_object( $scanner, 'taxonomy', $term_id );
lha_integration_assert(
    0 === lha_integration_occurrence_count( $taxonomy_url, 'taxonomy', $term_id ),
    'Clearing a taxonomy description did not remove its old occurrence.'
);

lha_integration_process_object( $scanner, 'nav_menu_item', $menu_item_id );
lha_integration_assert(
    1 === lha_integration_occurrence_count( $menu_url, 'nav_menu_item', $menu_item_id ),
    'The custom menu link was not extracted.'
);
$menu_item_update = wp_update_nav_menu_item(
    $menu_id,
    $menu_item_id,
    array(
        'menu-item-title'  => 'Integration Link',
        'menu-item-url'    => $new_menu_url,
        'menu-item-status' => 'publish',
        'menu-item-type'   => 'custom',
    )
);
lha_integration_assert( is_int( $menu_item_update ) && $menu_item_update > 0, 'Could not update the menu-link fixture.' );
lha_integration_process_object( $scanner, 'nav_menu_item', $menu_item_id );
lha_integration_assert(
    0 === lha_integration_occurrence_count( $menu_url, 'nav_menu_item', $menu_item_id ),
    'Rescanning did not remove the old menu occurrence.'
);
lha_integration_assert(
    1 === lha_integration_occurrence_count( $new_menu_url, 'nav_menu_item', $menu_item_id ),
    'Rescanning did not store the updated menu occurrence.'
);

$draft_update = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
lha_integration_assert( ! is_wp_error( $draft_update ), 'Could not unpublish the post fixture.' );
$scanner->start_full_scan();
lha_integration_assert(
    0 === lha_integration_occurrence_count( $shared_url, 'post', $post_id ),
    'A full scan did not clean occurrences from an unpublished post.'
);
lha_integration_assert( $queue->clear(), 'Could not clear the stale-source scan queue.' );
update_option( 'lha_scan_status', 'completed' );

$old_repair_url = 'https://repair.example.test/old';
$new_repair_url = 'https://repair.example.test/new';
$repair_content = '<p><a href="' . $old_repair_url . '">Repair target</a></p>';
$repair_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Repair Fixture',
        'post_content' => $repair_content,
    ),
    true
);
lha_integration_assert( is_int( $repair_post_id ) && $repair_post_id > 0, 'Could not create the repair fixture.' );
lha_integration_process_object( $scanner, 'post', $repair_post_id );

$repair        = new LHA_Repair();
$repair_result = $repair->replace_url( $old_repair_url, $new_repair_url, $repair_post_id );
lha_integration_assert( true === $repair_result['success'], 'The URL replacement failed.' );
lha_integration_assert( 1 === $repair_result['replaced'], 'The URL replacement count is incorrect.' );
lha_integration_assert(
    str_contains( get_post( $repair_post_id )->post_content, $new_repair_url ),
    'The replacement URL was not written to post content.'
);

$repairs_table = LHA_DB::table( 'repairs' );
$repair_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT id FROM {$repairs_table} WHERE object_id = %d AND action_type = %s ORDER BY id DESC LIMIT 1",
        $repair_post_id,
        'url_replaced'
    )
);
$repair_record = LHA_DB::get_repair( $repair_id );
lha_integration_assert( is_array( $repair_record ), 'The repair snapshot was not recorded.' );

$changed_content = (string) $repair_record['new_content'] . '<p>Concurrent edit</p>';
$changed_update = wp_update_post( array( 'ID' => $repair_post_id, 'post_content' => $changed_content ), true );
lha_integration_assert( ! is_wp_error( $changed_update ), 'Could not create the concurrent-edit fixture.' );
$guarded_rollback = $repair->rollback( $repair_id );
lha_integration_assert( false === $guarded_rollback['success'], 'Rollback ignored a newer content edit.' );
lha_integration_assert(
    $changed_content === get_post( $repair_post_id )->post_content,
    'A guarded rollback changed newer post content.'
);

$snapshot_update = wp_update_post(
    array(
        'ID'           => $repair_post_id,
        'post_content' => (string) $repair_record['new_content'],
    ),
    true
);
lha_integration_assert( ! is_wp_error( $snapshot_update ), 'Could not restore the repair snapshot.' );
$rollback_result = $repair->rollback( $repair_id );
lha_integration_assert( true === $rollback_result['success'], 'The guarded repair rollback failed.' );
lha_integration_assert(
    $repair_content === get_post( $repair_post_id )->post_content,
    'Rollback did not restore the original post content.'
);
lha_integration_assert(
    'rolled_back' === LHA_DB::get_repair( $repair_id )['status'],
    'Rollback did not update the repair-history status.'
);

$unlink_url = 'https://repair-unlink.example.test/target';
$unlink_content = '<p><a href="' . $unlink_url . '"><strong>Keep</strong> text</a> and <a href="' . $unlink_url . '">Second</a> plus <a href="https://repair-unlink.example.test/keep">Other</a></p>';
$unlink_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'LinkVitals Unlink Fixture',
        'post_content' => $unlink_content,
    ),
    true
);
lha_integration_assert( is_int( $unlink_post_id ) && $unlink_post_id > 0, 'Could not create the unlink fixture.' );
lha_integration_process_object( $scanner, 'post', $unlink_post_id );
$unlink_link_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT id FROM " . LHA_DB::table( 'links' ) . " WHERE url_hash = %s",
        hash( 'sha256', LHA_DB::normalize_url( $unlink_url ) )
    )
);
lha_integration_assert( $unlink_link_id > 0, 'Could not find the link to unlink.' );
$unlink_result = $repair->unlink( $unlink_link_id, $unlink_post_id );
lha_integration_assert( true === $unlink_result['success'], 'The unlink operation failed.' );
lha_integration_assert( 2 === $unlink_result['unlinked'], 'The unlink count did not include duplicate occurrences.' );
$unlink_updated_content = (string) get_post( $unlink_post_id )->post_content;
lha_integration_assert( str_contains( $unlink_updated_content, '<strong>Keep</strong> text' ), 'The unlink did not preserve anchor text.' );
lha_integration_assert( str_contains( $unlink_updated_content, 'href="https://repair-unlink.example.test/keep"' ), 'The unlink changed an unmatched anchor.' );
lha_integration_assert( ! str_contains( $unlink_updated_content, $unlink_url ), 'The unlink left the target URL in post content.' );
lha_integration_assert( 0 === lha_integration_occurrence_count( $unlink_url, 'post', $unlink_post_id ), 'The unlink left a stale target occurrence.' );
$unlink_repair_id = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT id FROM {$repairs_table} WHERE object_id = %d AND action_type = %s ORDER BY id DESC LIMIT 1",
        $unlink_post_id,
        'link_unlinked'
    )
);
$unlink_repair_record = LHA_DB::get_repair( $unlink_repair_id );
lha_integration_assert( is_array( $unlink_repair_record ), 'The unlink repair snapshot was not recorded.' );
lha_integration_assert( $unlink_content === $unlink_repair_record['old_content'], 'The unlink snapshot lost original content.' );
lha_integration_assert( $unlink_updated_content === $unlink_repair_record['new_content'], 'The unlink snapshot did not store new content.' );
$unlink_rollback = $repair->rollback( $unlink_repair_id );
lha_integration_assert( true === $unlink_rollback['success'], 'The unlink repair did not roll back.' );
lha_integration_assert( $unlink_content === get_post( $unlink_post_id )->post_content, 'Unlink rollback did not restore original content.' );
wp_delete_post( $unlink_post_id, true );
LHA_DB::delete_occurrences_by_object( 'post', $unlink_post_id );
lha_integration_assert( $queue->clear(), 'Could not clear the queue after unlink tests.' );

$settings['delete_data_on_uninstall'] = 1;
update_option( 'lha_settings', $settings );
update_option( 'lha_scan_started_at', '2026-07-21 12:00:00' );
update_option( 'lha_scan_type', 'full' );
update_option( 'lha_content_scan_cursor', '2026-07-20 12:00:00' );
update_option( 'lha_notification_lock', time() );
set_transient( 'lha_broken_notice_shown', 1, HOUR_IN_SECONDS );
set_transient( 'lha_notice_check', 1, HOUR_IN_SECONDS );
set_transient( 'lha_pre_scan_broken_count', 1, HOUR_IN_SECONDS );
set_transient( 'lha_ai_job_integration', array( 'status' => 'queued' ), HOUR_IN_SECONDS );

echo "LinkVitals WordPress integration checks passed.\n";
