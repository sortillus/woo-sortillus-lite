<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Settings {
	const OPTION_TOKEN             = 'woo_sortillus_lite_connector_token';
	const OPTION_EXTERNAL_ID       = 'woo_sortillus_lite_external_id';
	const OPTION_INSTALLATION_ID   = 'woo_sortillus_lite_installation_id';
	const OPTION_DOMAIN_ID         = 'woo_sortillus_lite_domain_id';
	const OPTION_OFFERS_ENDPOINT   = 'woo_sortillus_lite_offers_endpoint';
	const OPTION_HEALTH_ENDPOINT   = 'woo_sortillus_lite_health_endpoint';
	const OPTION_SYNCS_ENDPOINT    = 'woo_sortillus_lite_syncs_endpoint';
	const OPTION_SESSIONS_ENDPOINT = 'woo_sortillus_lite_sessions_endpoint';
	const OPTION_ASSISTANT_ENABLED = 'woo_sortillus_lite_assistant_enabled';
	const OPTION_SYNC_STATE        = 'woo_sortillus_lite_sync_state';

	public function is_connected() {
		return '' !== $this->get_token();
	}

	public function get_token() {
		return Woo_Sortillus_Lite_Crypto::decrypt( get_option( self::OPTION_TOKEN, '' ), 'connector_token' );
	}

	public function store_installation( array $response ) {
		if ( empty( $response['access_token'] ) ) {
			throw new RuntimeException( 'Sortillus did not return a connector token.' );
		}
		update_option(
			self::OPTION_TOKEN,
			Woo_Sortillus_Lite_Crypto::encrypt( (string) $response['access_token'], 'connector_token' ),
			false
		);

		$identifiers = array(
			self::OPTION_INSTALLATION_ID => 'installation_id',
			self::OPTION_DOMAIN_ID       => 'domain_id',
		);
		foreach ( $identifiers as $option => $key ) {
			if ( isset( $response[ $key ] ) ) {
				update_option( $option, sanitize_text_field( (string) $response[ $key ] ), false );
			}
		}

		$endpoints = array(
			self::OPTION_OFFERS_ENDPOINT   => 'offers_endpoint',
			self::OPTION_HEALTH_ENDPOINT   => 'health_endpoint',
			self::OPTION_SYNCS_ENDPOINT    => 'syncs_endpoint',
			self::OPTION_SESSIONS_ENDPOINT => 'shop_assistant_sessions_endpoint',
		);
		foreach ( $endpoints as $option => $key ) {
			if ( isset( $response[ $key ] ) ) {
				update_option( $option, esc_url_raw( (string) $response[ $key ] ), false );
			}
		}
	}

	public function get_external_id() {
		$value = (string) get_option( self::OPTION_EXTERNAL_ID, '' );
		if ( '' === $value ) {
			$value = wp_generate_uuid4();
			add_option( self::OPTION_EXTERNAL_ID, $value, '', false );
		}
		return $value;
	}

	public function assistant_enabled() {
		return '1' === (string) get_option( self::OPTION_ASSISTANT_ENABLED, '0' );
	}

	public function set_assistant_enabled( $enabled ) {
		update_option( self::OPTION_ASSISTANT_ENABLED, $enabled ? '1' : '0', false );
	}

	public function endpoint( $option, $fallback_path ) {
		$value = (string) get_option( $option, '' );
		return '' !== $value ? $value : trailingslashit( WOO_SORTILLUS_LITE_API_ORIGIN ) . ltrim( $fallback_path, '/' );
	}

	public function get_sync_state() {
		$state = get_option( self::OPTION_SYNC_STATE, array() );
		return is_array( $state ) ? $state : array();
	}

	public function set_sync_state( array $state ) {
		update_option( self::OPTION_SYNC_STATE, $state, false );
	}
}
