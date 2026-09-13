<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Assistant {
	private $settings;
	private $client;

	public function __construct( Woo_Sortillus_Lite_Settings $settings, Woo_Sortillus_Lite_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	public function init() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_widget' ) );
	}

	public function register_routes() {
		register_rest_route(
			'sortillus-lite/v1',
			'/shop-assistant/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function enqueue_widget() {
		if ( ! $this->settings->is_connected() || ! $this->settings->assistant_enabled() ) {
			return;
		}
		$search_selector = implode(
			', ',
			array(
				'header form.woocommerce-product-search',
				'.site-header form.woocommerce-product-search',
				'header .wp-block-woocommerce-product-search',
				'.site-header .wp-block-woocommerce-product-search',
				'header .wp-block-search',
				'.site-header .wp-block-search',
				'form.woocommerce-product-search',
			)
		);
		$desktop_selector = (string) apply_filters(
			'woo_sortillus_lite_assistant_desktop_selector',
			$search_selector
		);
		$mobile_selector = (string) apply_filters(
			'woo_sortillus_lite_assistant_mobile_selector',
			$desktop_selector
		);
		wp_enqueue_script( 'sortillus-shop-assistant', WOO_SORTILLUS_LITE_WIDGET_URL, array(), null, true );
		wp_enqueue_script(
			'woo-sortillus-lite-widget',
			WOO_SORTILLUS_LITE_URL . 'assets/widget.js',
			array( 'sortillus-shop-assistant' ),
			WOO_SORTILLUS_LITE_VERSION,
			true
		);
		wp_localize_script(
			'woo-sortillus-lite-widget',
			'wooSortillusLiteWidget',
			array(
				'apiOrigin'       => WOO_SORTILLUS_LITE_API_ORIGIN,
				'bootstrapUrl'    => wp_make_link_relative( rest_url( 'sortillus-lite/v1/shop-assistant/session' ) ),
				'locale'          => str_replace( '_', '-', determine_locale() ),
				'desktopSelector' => $desktop_selector,
				'mobileSelector'  => $mobile_selector,
			)
		);
	}

	public function create_session( WP_REST_Request $request ) {
		if ( ! $this->settings->is_connected() || ! $this->settings->assistant_enabled() ) {
			return $this->response( array( 'error' => array( 'code' => 'disabled', 'message' => 'Shop Assistant is disabled.' ) ), 403 );
		}
		if ( ! $this->same_origin_request() ) {
			return $this->response( array( 'error' => array( 'code' => 'origin_not_allowed', 'message' => 'Origin is not allowed.' ) ), 403 );
		}
		if ( ! $this->consume_rate_limit() ) {
			return $this->response( array( 'error' => array( 'code' => 'rate_limited', 'message' => 'Too many requests.' ) ), 429 );
		}

		$locale       = sanitize_text_field( (string) $request->get_param( 'locale' ) );
		$resume_token = sanitize_text_field( (string) $request->get_param( 'resume_token' ) );
		$payload      = array( 'locale' => substr( $locale, 0, 35 ) );
		if ( '' !== $resume_token ) {
			$payload['resume_token'] = substr( $resume_token, 0, 2048 );
		}
		$result = $this->client->create_assistant_session( $payload );
		if ( is_wp_error( $result ) ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 502;
			return $this->response(
				array( 'error' => array( 'code' => 'bootstrap_failed', 'message' => $result->get_error_message() ) ),
				$status
			);
		}
		return $this->response( $result, 201 );
	}

	private function response( array $body, $status ) {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	private function same_origin_request() {
		$source = '';
		if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
			$source = wp_unslash( $_SERVER['HTTP_ORIGIN'] );
		} elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
			$source = wp_unslash( $_SERVER['HTTP_REFERER'] );
		}
		$source_origin = $this->origin( $source );
		if ( '' === $source_origin ) {
			return false;
		}
		$allowed = array_filter(
			array(
				$this->origin( home_url( '/' ) ),
				$this->origin( site_url( '/' ) ),
			)
		);
		return in_array( $source_origin, array_unique( $allowed ), true );
	}

	private function origin( $url ) {
		$parts = wp_parse_url( esc_url_raw( $url ) );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	private function consume_rate_limit() {
		$ip    = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$key   = 'woo_sortillus_lite_chat_' . substr( hash( 'sha256', $ip ), 0, 24 );
		$count = (int) get_transient( $key );
		if ( $count >= 30 ) {
			return false;
		}
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}
}
