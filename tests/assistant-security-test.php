<?php

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
$session_calls = 0;
$transients = array();
class Woo_Sortillus_Lite_Settings {
	public function is_connected() { return true; }
	public function assistant_enabled() { return true; }
}
class Woo_Sortillus_Lite_Client {
	public function create_assistant_session( $payload ) { global $session_calls; ++$session_calls; return array( 'session' => 'test-session' ); }
}
class WP_REST_Request {
	public function get_param( $key ) { return 'locale' === $key ? 'en' : ''; }
}
class WP_REST_Response {
	public $data;
	public $status;
	public $headers = array();
	public function __construct( $data, $status ) { $this->data = $data; $this->status = $status; }
	public function header( $name, $value ) { $this->headers[$name] = $value; }
}
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function wp_parse_url( $value, $component = -1 ) { return parse_url( $value, $component ); }
function home_url() { return 'https://shop.example/'; }
function site_url() { return 'https://wordpress.example/'; }
function get_transient( $key ) { global $transients; return $transients[$key] ?? false; }
function set_transient( $key, $value, $expiration ) { global $transients; $transients[$key] = $value; }
function is_wp_error( $value ) { return false; }
function check_assistant( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }

require dirname( __DIR__ ) . '/includes/class-woo-sortillus-lite-assistant.php';
$assistant = new Woo_Sortillus_Lite_Assistant( new Woo_Sortillus_Lite_Settings(), new Woo_Sortillus_Lite_Client() );
$cases = array(
	array( array( 'HTTP_ORIGIN' => 'https://shop.example' ), 201 ),
	array( array( 'HTTP_ORIGIN' => 'https://wordpress.example' ), 201 ),
	array( array( 'HTTP_REFERER' => 'https://shop.example/products/test' ), 201 ),
	array( array( 'HTTP_ORIGIN' => 'https://untrusted.example' ), 403 ),
	array( array( 'HTTP_HOST' => 'untrusted.example', 'HTTP_ORIGIN' => 'https://untrusted.example' ), 403 ),
	array( array( 'HTTP_HOST' => 'untrusted.example', 'HTTP_REFERER' => 'https://untrusted.example/products' ), 403 ),
	array( array( 'HTTP_ORIGIN' => 'http://shop.example' ), 403 ),
	array( array( 'HTTP_ORIGIN' => 'https://shop.example:8443' ), 403 ),
	array( array( 'HTTP_ORIGIN' => 'https://shop.example.untrusted.example' ), 403 ),
	array( array( 'HTTP_ORIGIN' => 'null', 'HTTP_REFERER' => 'https://shop.example/' ), 403 ),
	array( array(), 403 ),
);
foreach ( $cases as $case ) {
	$_SERVER = $case[0] + array( 'HTTP_HOST' => 'shop.example', 'REMOTE_ADDR' => '192.0.2.1' );
	$before = $session_calls;
	$result = $assistant->create_session( new WP_REST_Request() );
	check_assistant( $case[1] === $result->status, 'Only configured storefront and WordPress origins may create sessions.' );
	check_assistant( $session_calls === $before + ( 201 === $case[1] ? 1 : 0 ), 'Rejected requests must never reach the authenticated session API.' );
	check_assistant( 'no-store' === $result->headers['Cache-Control'], 'Session responses must not be cached.' );
}
echo "Sortillus Lite assistant security test passed.\n";
