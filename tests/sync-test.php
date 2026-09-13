<?php

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

$scheduled_actions = array();
$transients = array();
$registered_actions = array();
$schedule_failure = false;

class WP_Error {
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) { $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_generate_uuid4() { static $id = 0; ++$id; return '00000000-0000-4000-8000-' . str_pad( (string) $id, 12, '0', STR_PAD_LEFT ); }
function wp_count_posts() { return (object) array( 'publish' => 2 ); }
function get_post_status() { return 'publish'; }
function absint( $value ) { return abs( (int) $value ); }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); return true; }
function as_schedule_single_action( $timestamp, $hook, $args, $group, $unique ) {
	global $scheduled_actions, $unique_hooks_in_flight, $schedule_failure;
	if ( $schedule_failure ) { return 0; }

	// Mirror Action Scheduler: unique is hook+group only (args ignored).
	$key = $hook . '|' . $group;
	if ( $unique && ! empty( $unique_hooks_in_flight[ $key ] ) ) {
		return 0;
	}

	$scheduled_actions[] = compact( 'timestamp', 'hook', 'args', 'group', 'unique' );
	return count( $scheduled_actions );
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $registered_actions;
	$registered_actions[$hook] = array( $callback, $accepted_args );
}

final class Woo_Sortillus_Lite_Settings {
	public $state = array();
	public $connected = true;
	public $category_state = array( 'status' => 'succeeded' );
	public function get_category_state() { return $this->category_state; }
	public function set_category_state( array $state ) { $this->category_state = $state; }
	public function categories_imported() { return 'succeeded' === ( $this->category_state['status'] ?? '' ); }
	public function is_connected() { return $this->connected; }
	public function get_sync_state() { return $this->state; }
	public function set_sync_state( array $state ) { $this->state = $state; }
}

final class Woo_Sortillus_Lite_Client {
	public $category_batches = array();
	public $category_result = array();
	public function send_categories( array $categories, $key ) { $this->category_batches[] = array( $categories, $key ); return $this->category_result; }
	public $reports = array();
	public $batches = array();
	public $deltas = array();
	public $batch_result = null;
	public function report_sync( array $payload, $key ) { $this->reports[] = array( $payload, $key ); return array(); }
	public function send_offers( array $offers, $key ) {
		$this->batches[] = array( $offers, $key );
		return null !== $this->batch_result ? $this->batch_result : array();
	}
	public function send_offer( array $offer, $key ) { $this->deltas[] = array( $offer, $key ); return array(); }
}

final class Woo_Sortillus_Lite_Offer_Builder {
	public function build( $product_id ) { return array( 'external_offer_id' => (string) $product_id ); }
}

final class Fake_Wpdb {
	public $posts = 'wp_posts';
	public $reads = 0;
	public function prepare( $sql ) { return $sql; }
	public function get_col() { ++$this->reads; return 1 === $this->reads ? array( 1, 2 ) : array(); }
}
$wpdb = new Fake_Wpdb();
$unique_hooks_in_flight = array();

require dirname( __DIR__ ) . '/includes/class-woo-sortillus-lite-sync.php';

function assert_sync( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$settings = new Woo_Sortillus_Lite_Settings();
$client   = new Woo_Sortillus_Lite_Client();
$builder  = new Woo_Sortillus_Lite_Offer_Builder();
$sync     = new Woo_Sortillus_Lite_Sync( $settings, $client, $builder );
$state    = $sync->start_import();

assert_sync( 'queued' === $state['status'], 'Import must begin queued.' );
assert_sync( 2 === $state['total'], 'Import must record the published-product total.' );
assert_sync( 1 === count( $scheduled_actions ), 'Import must schedule its first background batch.' );
assert_sync( true === $scheduled_actions[0]['unique'], 'The first import action may be unique.' );
assert_sync( 'started' === $client->reports[0][0]['status'], 'Import must report started to Sortillus.' );

$first = $scheduled_actions[0]['args'];
// Simulate the first batch still running in Action Scheduler while it chains the next one.
$unique_hooks_in_flight[ Woo_Sortillus_Lite_Sync::IMPORT_HOOK . '|' . Woo_Sortillus_Lite_Sync::GROUP ] = true;
$sync->run_import_batch( $first[0], $first[1], $first[2], $first[3] );
assert_sync( 2 === $settings->state['processed'], 'First batch must advance processed count.' );
assert_sync( 2 === $settings->state['accepted'], 'Accepted offers must be accumulated.' );
assert_sync( 2 === count( $scheduled_actions ), 'A completed batch must schedule the next cursor.' );
assert_sync( false === $scheduled_actions[1]['unique'], 'Chained import batches must not use AS unique=true.' );

$unique_hooks_in_flight = array();
$next = $scheduled_actions[1]['args'];
$sync->run_import_batch( $next[0], $next[1], $next[2], $next[3] );
assert_sync( 'succeeded' === $settings->state['status'], 'An error-free import must succeed.' );
assert_sync( ! empty( $settings->state['finished_at'] ), 'A completed import must store its finish time.' );
assert_sync( 'succeeded' === $client->reports[1][0]['status'], 'Import completion must be reported to Sortillus.' );

$sync->run_product_delta( 2, 0, 'delta-key' );
assert_sync( 1 === count( $client->deltas ), 'A product delta must send one offer.' );
assert_sync( 'succeeded' === $client->reports[2][0]['status'], 'A successful delta must report success.' );

$scheduled_actions      = array();
$wpdb->reads            = 0;
$unique_hooks_in_flight = array();
$retry_settings         = new Woo_Sortillus_Lite_Settings();
$retry_client           = new Woo_Sortillus_Lite_Client();
$retry_client->batch_result = new WP_Error( 'temporary', 'Temporary failure', array( 'retryable' => true ) );
$retry_sync             = new Woo_Sortillus_Lite_Sync( $retry_settings, $retry_client, $builder );
$retry_state            = $retry_sync->start_import();
$retry_first            = $scheduled_actions[0]['args'];
$unique_hooks_in_flight[ Woo_Sortillus_Lite_Sync::IMPORT_HOOK . '|' . Woo_Sortillus_Lite_Sync::GROUP ] = true;
$retry_sync->run_import_batch( $retry_first[0], $retry_first[1], $retry_first[2], $retry_first[3] );
assert_sync( 2 === count( $scheduled_actions ), 'A retryable API failure must schedule a retry action.' );
assert_sync( 1 === $scheduled_actions[1]['args'][2], 'The retry action must increment its attempt number.' );
assert_sync( false === $scheduled_actions[1]['unique'], 'Import retries must not use AS unique=true.' );
assert_sync( $retry_first[3] === $scheduled_actions[1]['args'][3], 'Retries must preserve the idempotency key.' );
assert_sync( 0 === $retry_settings->state['processed'], 'A retry must not advance import progress before delivery.' );

echo "Sortillus Lite sync lifecycle test passed.\n";

$category_terms = array(
	2 => (object) array( 'term_id' => 2, 'parent' => 1, 'name' => 'Child', 'description' => '' ),
	1 => (object) array( 'term_id' => 1, 'parent' => 0, 'name' => 'Root', 'description' => '' ),
);
function get_terms( $args ) { global $category_terms; assert_sync( false === $args['hide_empty'], 'Empty categories must be included.' ); return array_values( $category_terms ); }
function get_term( $id, $taxonomy ) { global $category_terms; return $category_terms[$id] ?? null; }
function get_locale() { return 'hr_HR'; }

$unique_hooks_in_flight = array();
$cat_settings = new Woo_Sortillus_Lite_Settings();
$cat_settings->category_state = array();
$cat_client = new Woo_Sortillus_Lite_Client();
$cat_sync = new Woo_Sortillus_Lite_Sync( $cat_settings, $cat_client, $builder );
assert_sync( is_wp_error( $cat_sync->start_import() ), 'Products must be blocked before category import.' );
$cat_state = $cat_sync->start_category_import();
assert_sync( array(1, 2) === $cat_state['term_ids'], 'Category import must order parents before children.' );
assert_sync( ! $cat_settings->categories_imported(), 'Queued categories must not unlock products.' );
$cat_client->category_result = new WP_Error( 'partial', 'A category failed' );
$cat_sync->run_category_batch( $cat_state['run_id'] );
assert_sync( 'failed' === $cat_settings->category_state['status'], 'Partial category failure must fail the import.' );
assert_sync( is_wp_error( $cat_sync->start_import() ), 'Failed categories must not unlock products.' );
$cat_client->category_result = array();
$cat_state = $cat_sync->start_category_import();
$cat_sync->run_category_batch( $cat_state['run_id'] );
assert_sync( $cat_settings->categories_imported(), 'Confirmed category import must unlock products.' );
assert_sync( 2 === $cat_settings->category_state['processed'], 'All confirmed categories must count.' );
assert_sync( 0 === $cat_client->category_batches[0][0][0]['external_parent_id'], 'Root must send zero parent.' );
assert_sync( 1 === $cat_client->category_batches[0][0][1]['external_parent_id'], 'Child must send API external_parent_id.' );
assert_sync( ! is_wp_error( $cat_sync->start_import() ), 'Product import must work after category success.' );

// A hierarchy larger than one batch must retain ordering and retry without advancing.
$category_terms = array();
for ( $i = 105; $i > 0; --$i ) {
	$category_terms[$i] = (object) array( 'term_id' => $i, 'parent' => $i - 1, 'name' => 'Category ' . $i, 'description' => '' );
}
$cat_settings->state = array();
$cat_state = $cat_sync->start_category_import();
$cat_client->category_result = new WP_Error( 'temporary', 'Retry', array( 'retryable' => true ) );
$cat_sync->run_category_batch( $cat_state['run_id'] );
assert_sync( 0 === $cat_settings->category_state['processed'], 'Retry must not advance category progress.' );
$retry_key = end( $cat_client->category_batches )[1];
$cat_client->category_result = array();
$cat_sync->run_category_batch( $cat_state['run_id'], 1 );
assert_sync( $retry_key === end( $cat_client->category_batches )[1], 'Category retry must preserve idempotency key.' );
assert_sync( 100 === $cat_settings->category_state['processed'], 'Categories must be batched.' );
assert_sync( ! $cat_settings->categories_imported(), 'Products must stay blocked between batches.' );
$cat_sync->run_category_batch( $cat_state['run_id'] );
assert_sync( $cat_settings->categories_imported(), 'Final batch must unlock products.' );
assert_sync( 100 === end( $cat_client->category_batches )[0][0]['external_parent_id'], 'Second batch must reference a parent saved in the first batch.' );
$cat_sync->run_category_batch( $cat_state['run_id'] );
assert_sync( 105 === $cat_settings->category_state['processed'], 'A stale completed action must be ignored.' );
$category_terms = array();
$cat_sync->start_category_import();
assert_sync( $cat_settings->categories_imported(), 'An empty taxonomy needs no API calls and must unlock products.' );
echo "Sortillus Lite category lifecycle test passed.\n";

// Exercise the registered save hooks and their background delivery callbacks.
$scheduled_actions = array();
$category_terms = array(
	1 => (object) array( 'term_id' => 1, 'parent' => 0, 'name' => 'Root', 'description' => '' ),
	2 => (object) array( 'term_id' => 2, 'parent' => 1, 'name' => 'Child', 'description' => 'Original' ),
);
$cat_settings->category_state = array( 'status' => 'running', 'processed' => 10, 'run_id' => 'full-import' );
$cat_client->category_batches = array();
$cat_sync->init();
assert_sync( isset( $registered_actions['created_product_cat'], $registered_actions['edited_product_cat'] ), 'Register both category save hooks.' );
assert_sync( ! isset( $registered_actions['created_product_tag'], $registered_actions['edited_product_tag'] ), 'Category updates must not register tag hooks.' );
$created = $registered_actions['created_product_cat'][0];
$edited = $registered_actions['edited_product_cat'][0];
$created( 2 );
$edited( 1 );
assert_sync( 2 === count( $scheduled_actions ) && ! $cat_client->category_batches, 'Category saves must queue independent background sends without HTTP in the save request.' );
assert_sync( $scheduled_actions[0]['timestamp'] >= time() + 4 && false === $scheduled_actions[0]['unique'], 'Category sends must have a short delay and allow multiple categories.' );
$delivery = $registered_actions[Woo_Sortillus_Lite_Sync::CATEGORY_DELTA_HOOK][0];
assert_sync( 3 === $registered_actions[Woo_Sortillus_Lite_Sync::CATEGORY_DELTA_HOOK][1], 'The worker must receive all retry arguments.' );
$category_terms[2]->name = 'Renamed child';
$category_terms[2]->description = 'Updated description';
$delivery( ...$scheduled_actions[0]['args'] );
$sent = end( $cat_client->category_batches )[0];
assert_sync( array( 1, 2 ) === array_column( $sent, 'external_id' ), 'A child update must send its ancestors first.' );
assert_sync( 'Renamed child' === $sent[1]['name'] && 'Updated description' === $sent[1]['description'], 'Queued sends must load the latest category values.' );
assert_sync( 1 === $sent[1]['external_parent_id'] && 'hr_HR' === $sent[1]['source_locale'] && true === $sent[1]['active'], 'Category updates must retain parent, locale, and active fields.' );
assert_sync( 'running' === $cat_settings->category_state['status'] && 10 === $cat_settings->category_state['processed'] && 'full-import' === $cat_settings->category_state['run_id'], 'Automatic sends must preserve full-import progress.' );
assert_sync( ! $cat_settings->categories_imported(), 'A single category update must not mark the initial import complete.' );

$cat_client->category_result = new WP_Error( 'temporary', 'Temporary category failure', array( 'retryable' => true, 'retry_after' => 120 ) );
$delivery( ...$scheduled_actions[0]['args'] );
$failed_key = end( $cat_client->category_batches )[1];
$retry = end( $scheduled_actions );
assert_sync( 1 === $retry['args'][1] && $scheduled_actions[0]['args'][2] === $retry['args'][2], 'Category retries must increment attempts and retain the event key.' );
assert_sync( $retry['timestamp'] >= time() + 119, 'Category retries must respect Retry-After.' );
$cat_client->category_result = array();
$delivery( ...$retry['args'] );
assert_sync( $failed_key === end( $cat_client->category_batches )[1], 'An unchanged retry payload must keep its idempotency key.' );

$category_terms[2]->parent = 0;
$category_terms[2]->name = 'Moved child';
$edited( 2 );
$delivery( ...$retry['args'] );
$latest = end( $cat_client->category_batches );
assert_sync( 1 === count( $latest[0] ) && 0 === $latest[0][0]['external_parent_id'] && 'Moved child' === $latest[0][0]['name'], 'An older retry must send the current hierarchy and name.' );
assert_sync( $failed_key !== $latest[1], 'Changed data on a retry must not reuse the old payload key.' );

$before = count( $scheduled_actions );
$cat_client->category_result = new WP_Error( 'forbidden', 'Reconnect to grant taxonomy:write' );
$delivery( 2, 0, 'permanent-failure' );
assert_sync( $before === count( $scheduled_actions ) && 'Reconnect to grant taxonomy:write' === $cat_settings->category_state['last_delta_error'], 'Permanent failures must be recorded without retrying.' );
$cat_client->category_result = new WP_Error( 'temporary', 'Retries exhausted', array( 'retryable' => true ) );
$delivery( 2, 5, 'exhausted' );
assert_sync( $before === count( $scheduled_actions ) && 'Retries exhausted' === $cat_settings->category_state['last_delta_error'], 'Category retries must stop after five retries.' );
$cat_client->category_result = array();
$delivery( 2, 0, 'success' );
assert_sync( null === $cat_settings->category_state['last_delta_error'], 'Successful category sends must clear the last delta error.' );

$cat_settings->connected = false;
$before_batches = count( $cat_client->category_batches );
$created( 2 );
$delivery( 2, 0, 'disconnected' );
assert_sync( $before === count( $scheduled_actions ) && $before_batches === count( $cat_client->category_batches ), 'Disconnected stores must neither queue nor send category updates.' );
$cat_settings->connected = true;
unset( $category_terms[2] );
$delivery( 2, 0, 'deleted' );
assert_sync( $before_batches === count( $cat_client->category_batches ), 'Deleted categories must be skipped.' );
$category_terms[1]->parent = 1;
$delivery( 1, 0, 'cycle' );
assert_sync( $before_batches === count( $cat_client->category_batches ) && false !== strpos( $cat_settings->category_state['last_delta_error'], 'cycle' ), 'Invalid hierarchies must fail without sending or looping.' );
$category_terms[1]->parent = 99;
$delivery( 1, 0, 'missing-parent' );
assert_sync( $before_batches === count( $cat_client->category_batches ) && false !== strpos( $cat_settings->category_state['last_delta_error'], 'hierarchy' ), 'Missing parents must prevent sending an invalid hierarchy.' );
$schedule_failure = true;
$created( 1 );
assert_sync( false !== strpos( $cat_settings->category_state['last_delta_error'], 'schedule' ), 'Scheduling failures must be visible.' );
$schedule_failure = false;

// Deep hierarchies must keep parent ordering across the API batch limit.
$category_terms = array();
for ( $i = 1; $i <= 105; ++$i ) {
	$category_terms[$i] = (object) array( 'term_id' => $i, 'parent' => $i - 1, 'name' => 'Category ' . $i, 'description' => '' );
}
$cat_client->category_batches = array();
$delivery( 105, 0, 'deep-hierarchy' );
assert_sync( 2 === count( $cat_client->category_batches ) && 100 === count( $cat_client->category_batches[0][0] ), 'Large category hierarchies must respect the batch size.' );
assert_sync( 100 === $cat_client->category_batches[1][0][0]['external_parent_id'] && 105 === $cat_client->category_batches[1][0][4]['external_id'], 'A second hierarchy batch must follow its saved parents.' );
echo "Sortillus Lite automatic category sync test passed.\n";
