<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Woo_Sortillus_Lite_Crypto {
	const PREFIX = 'enc1:';

	public static function encrypt( $plaintext, $context ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new RuntimeException( 'OpenSSL is required to protect the Sortillus connector token.' );
		}

		$key        = self::key( $context );
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Could not encrypt the Sortillus connector token.' );
		}

		return self::PREFIX . base64_encode(
			wp_json_encode(
				array(
					'ciphertext' => base64_encode( $ciphertext ),
					'iv'         => base64_encode( $iv ),
					'tag'        => base64_encode( $tag ),
				)
			)
		);
	}

	public static function decrypt( $value, $context ) {
		$value = (string) $value;
		if ( '' === $value || 0 !== strpos( $value, self::PREFIX ) ) {
			return '';
		}

		$decoded = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );
		$data    = $decoded ? json_decode( $decoded, true ) : null;
		if ( ! is_array( $data ) ) {
			return '';
		}

		$ciphertext = base64_decode( (string) ( $data['ciphertext'] ?? '' ), true );
		$iv         = base64_decode( (string) ( $data['iv'] ?? '' ), true );
		$tag        = base64_decode( (string) ( $data['tag'] ?? '' ), true );
		if ( false === $ciphertext || false === $iv || false === $tag ) {
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::key( $context ), OPENSSL_RAW_DATA, $iv, $tag );
		return is_string( $plaintext ) ? $plaintext : '';
	}

	private static function key( $context ) {
		return hash( 'sha256', wp_salt( 'auth' ) . '|' . site_url() . '|' . (string) $context, true );
	}
}

