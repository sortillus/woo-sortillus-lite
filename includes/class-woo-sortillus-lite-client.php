<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Client {
	private $settings;

	public function __construct( Woo_Sortillus_Lite_Settings $settings ) {
		$this->settings = $settings;
	}

	public function activate( $activation_token ) {
		$site_url = home_url( '/' );
		$host     = wp_parse_url( $site_url, PHP_URL_HOST );
		$payload  = array(
			'activation_token' => trim( (string) $activation_token ),
			'site_url'         => $site_url,
			'external_id'      => $this->settings->get_external_id(),
			'app_version'      => WOO_SORTILLUS_LITE_VERSION,
			'variant'          => 'lite',
			'production'       => ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ),
			'terms_accepted'   => true,
			'scopes'           => array( 'offers:write', 'integration:read', 'integration:write', 'chat:write' ),
			'metadata'         => array(
				'wordpress_version'   => get_bloginfo( 'version' ),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			),
		);

		return $this->request(
			'POST',
			trailingslashit( WOO_SORTILLUS_LITE_API_ORIGIN ) . 'api/v3/integrations/woocommerce/installations',
			$payload,
			null,
			45
		);
	}

	public function send_offer( array $offer, $idempotency_key ) {
		$batch_endpoint = $this->settings->endpoint(
			Woo_Sortillus_Lite_Settings::OPTION_OFFERS_ENDPOINT,
			'api/v3/offers/batch'
		);
		$endpoint       = preg_replace( '#/batch/?$#', '', $batch_endpoint );
		return $this->request( 'POST', $endpoint, array( 'offer' => $offer ), $idempotency_key, 45 );
	}

	public function send_offers( array $offers, $idempotency_key ) {
		$endpoint = $this->settings->endpoint(
			Woo_Sortillus_Lite_Settings::OPTION_OFFERS_ENDPOINT,
			'api/v3/offers/batch'
		);
		return $this->request(
			'POST',
			$endpoint,
			array(
				'offers' => array_values( $offers ),
				'allow'  => array( 'extract' ),
			),
			$idempotency_key,
			90
		);
	}

	public function report_sync( array $payload, $idempotency_key ) {
		$endpoint = $this->settings->endpoint(
			Woo_Sortillus_Lite_Settings::OPTION_SYNCS_ENDPOINT,
			'api/v3/integrations/woocommerce/current/syncs'
		);
		return $this->request( 'POST', $endpoint, $payload, $idempotency_key, 30 );
	}

	public function health() {
		$endpoint = $this->settings->endpoint(
			Woo_Sortillus_Lite_Settings::OPTION_HEALTH_ENDPOINT,
			'api/v3/integrations/current/health'
		);
		return $this->request( 'GET', $endpoint, null, null, 20 );
	}

	public function create_assistant_session( array $payload ) {
		$endpoint = $this->settings->endpoint(
			Woo_Sortillus_Lite_Settings::OPTION_SESSIONS_ENDPOINT,
			'api/v3/shop_assistant/sessions'
		);
		return $this->request( 'POST', $endpoint, $payload, null, 30 );
	}

	private function request( $method, $url, $payload = null, $idempotency_key = null, $timeout = 30 ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( null !== $payload ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( $idempotency_key ) {
			$headers['Idempotency-Key'] = (string) $idempotency_key;
		}
		$token = $this->settings->get_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 2,
			'headers'     => $headers,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_safe_remote_request( esc_url_raw( $url ), $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'sortillus_network_error',
				$response->get_error_message(),
				array( 'retryable' => true )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$data   = '' !== $body ? json_decode( $body, true ) : array();
		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['error'] ) ? $data['error'] : 'Sortillus API request failed.';
			if ( is_array( $message ) ) {
				$message = $message['message'] ?? 'Sortillus API request failed.';
			}
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			return new WP_Error(
				'sortillus_http_error',
				(string) $message,
				array(
					'status'      => $status,
					'retryable'   => in_array( $status, array( 408, 425, 429 ), true ) || $status >= 500,
					'retry_after' => $retry_after,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}
}

