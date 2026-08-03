<?php

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );

$scheduled_actions = array();
$transients = array();

class WP_Error {
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) { $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function wp_generate_uuid4() { static $id = 0; ++$id; return '00000000-0000-4000-8000-' . str_pad( (string) $id, 12, '0', STR_PAD_LEFT ); }
function wp_count_posts() { return (object) array( 'publish' => 2 ); }
function get_post_status() { return 'publish'; }
function absint( $value ) { return abs( (int) $value ); }
function get_transient( $key ) { global $transients; return $transients[ $key ] ?? false; }
function set_transient( $key, $value ) { global $transients; $transients[ $key ] = $value; return true; }
function delete_transient( $key ) { global $transients; unset( $transients[ $key ] ); return true; }
function as_schedule_single_action( $timestamp, $hook, $args, $group, $unique ) {
	global $scheduled_actions, $unique_hooks_in_flight;

	// Mirror Action Scheduler: unique is hook+group only (args ignored).
	$key = $hook . '|' . $group;
	if ( $unique && ! empty( $unique_hooks_in_flight[ $key ] ) ) {
		return 0;
	}

	$scheduled_actions[] = compact( 'timestamp', 'hook', 'args', 'group', 'unique' );
	return count( $scheduled_actions );
}
function add_action() {}

final class Woo_Sortillus_Lite_Settings {
	public $state = array();
	public function is_connected() { return true; }
	public function get_sync_state() { return $this->state; }
	public function set_sync_state( array $state ) { $this->state = $state; }
}

final class Woo_Sortillus_Lite_Client {
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
