<?php

define( 'ABSPATH', __DIR__ );
define( 'WOO_SORTILLUS_LITE_API_ORIGIN', 'https://data.sortillus.com' );
define( 'WOO_SORTILLUS_LITE_VERSION', 'test' );
$options = array();
$requests = array();
$response = array();
class WP_Error {
	private $message;
	private $data;
	public function __construct( $code, $message, $data = array() ) { $this->message = $message; $this->data = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
class Woo_Sortillus_Lite_Crypto {
	public static function decrypt( $value, $purpose ) { return $value; }
	public static function encrypt( $value, $purpose ) { return $value; }
}
function get_option( $key, $default = false ) { global $options; return $options[$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { global $options; $options[$key] = $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function trailingslashit( $value ) { return rtrim( $value, '/' ) . '/'; }
function esc_url_raw( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function home_url() { return 'https://shop.example/'; }
function wp_parse_url( $value, $component ) { return parse_url( $value, $component ); }
function get_bloginfo() { return 'test'; }
function wp_safe_remote_request( $url, $args ) { global $requests, $response; $requests[] = array( $url, $args ); return $response; }
function wp_remote_retrieve_response_code( $value ) { return $value['status']; }
function wp_remote_retrieve_body( $value ) { return json_encode( $value['body'] ); }
function wp_remote_retrieve_header() { return ''; }
function check_client( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
require dirname( __DIR__ ) . '/includes/class-woo-sortillus-lite-settings.php';
require dirname( __DIR__ ) . '/includes/class-woo-sortillus-lite-client.php';
$settings = new Woo_Sortillus_Lite_Settings();
$settings->set_category_state( array( 'status' => 'succeeded' ) );
$settings->store_installation( array( 'access_token' => 'connector-test', 'domain_id' => 42, 'categories_batch_endpoint' => 'https://data.sortillus.com/api/v3/shop/categories/batch' ) );
check_client( ! $settings->categories_imported(), 'Reconnecting must reset category readiness.' );
$options[Woo_Sortillus_Lite_Settings::OPTION_EXTERNAL_ID] = 'test-id';
$client = new Woo_Sortillus_Lite_Client( $settings );
$response = array( 'status' => 200, 'body' => array( 'meta' => array( 'saved_count' => 1, 'error_count' => 0 ), 'errors' => array() ) );
$categories = array( array( 'external_id' => 15, 'external_parent_id' => 0, 'name' => 'Uncategorized' ) );
check_client( ! is_wp_error( $client->send_categories( $categories, 'batch-key' ) ), 'Confirmed category saves must succeed.' );
check_client( $requests[0][0] === 'https://data.sortillus.com/api/v3/shop/categories/batch', 'Use the categories batch endpoint.' );
check_client( $requests[0][1]['headers']['Authorization'] === 'Bearer connector-test', 'Use the installation token to scope categories.' );
check_client( json_decode( $requests[0][1]['body'], true ) === array( 'categories' => $categories ), 'Send category payload with no client-selected domain.' );
$response = array( 'status' => 207, 'body' => array( 'meta' => array( 'saved_count' => 0, 'error_count' => 1 ), 'errors' => array( array( 'index' => 0, 'error' => 'Parent missing' ) ) ) );
check_client( is_wp_error( $client->send_categories( $categories, 'partial' ) ), 'HTTP 207 must not count as category import success.' );
$response = array( 'status' => 202, 'body' => array( 'job_status' => 'queued' ) );
check_client( is_wp_error( $client->send_categories( $categories, 'queued' ) ), 'Queued without save counts must not unlock products.' );
$response = array( 'status' => 403, 'body' => array( 'error' => 'Insufficient connector scope', 'required_scope' => 'taxonomy:write' ) );
$error = $client->send_categories( $categories, 'scope' );
check_client( is_wp_error( $error ) && strpos( $error->get_error_message(), 'Reconnect' ) !== false, 'Old tokens need a useful reconnect message.' );
$response = array( 'status' => 200, 'body' => array() );
$client->activate( 'activation-test' );
$activation = json_decode( end( $requests )[1]['body'], true );
check_client( in_array( 'taxonomy:write', $activation['scopes'], true ), 'Activation must request taxonomy scope.' );
unset( $options[Woo_Sortillus_Lite_Settings::OPTION_CATEGORIES_ENDPOINT] );
$client->send_categories( $categories, 'fallback' );
check_client( end( $requests )[0] === 'https://data.sortillus.com/api/v3/shop/categories/batch', 'Old installations need the correct fallback endpoint.' );
echo "Sortillus Lite category client test passed.\n";
