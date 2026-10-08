<?php
/**
 * Authentication helper for WP MCP.
 *
 * All WP MCP endpoints are protected by a single API key. The MCP server sends
 * the key in the x-api-key or Authorization: Bearer HTTP header. An optional
 * MCP path-key fallback is available for content tools. Keys use a
 * timing-safe comparison.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPMCP_Auth {
	const MAX_FAILURES   = 10;
	const FAILURE_WINDOW = 900; // Seconds; also the lockout length after the last failure.

	public static $header_authenticated = false;

	/**
	 * Generate a random, URL-safe API key.
	 *
	 * @return string
	 */
	public static function generate_key() {
		// 32 bytes -> 64 hex chars. Prefixed so it is recognisable in logs.
		if ( function_exists( 'wp_generate_password' ) ) {
			$random = wp_generate_password( 48, false, false );
		} else {
			$random = bin2hex( random_bytes( 24 ) );
		}
		return 'wpmcp_' . $random;
	}

	/**
	 * Read the API key supplied on the current request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string
	 */
	public static function get_request_key( $request ) {
		// Preferred: the standard header name Claude's static_headers auth allows.
		$key = $request->get_header( 'x_api_key' );
		if ( empty( $key ) ) {
			$key = $request->get_header( 'X-Api-Key' );
		}
		if ( empty( $key ) ) {
			$key = $request->get_header( 'x_wpmcp_key' );
		}
		if ( empty( $key ) ) {
			// Some servers pass custom headers differently; try alternatives.
			$key = $request->get_header( 'X-WPMCP-Key' );
		}
		if ( empty( $key ) ) {
			$auth = $request->get_header( 'authorization' );
			if ( ! empty( $auth ) && stripos( $auth, 'Bearer ' ) === 0 ) {
				$key = trim( substr( $auth, 7 ) );
			}
		}
		return is_string( $key ) ? trim( $key ) : '';
	}

	/**
	 * Permission callback for all WP MCP routes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public static function check( $request ) {
		self::$header_authenticated = false;
		if ( '1' !== (string) get_option( 'wpmcp_enabled', '1' ) ) {
			return new WP_Error(
				'wpmcp_disabled',
				'WP MCP is disabled. Enable it in the WP MCP admin menu.',
				array( 'status' => 403 )
			);
		}

		$provided = self::get_request_key( $request );

		// OAuth access token: acts as the administrator who approved the connection.
		if ( 0 === strpos( $provided, 'wpmcp_at_' ) ) {
			$grant = WPMCP_OAuth::validate_access_token( $provided );
			if ( ! $grant ) {
				WPMCP_OAuth::send_challenge( 'invalid_token' );
				return new WP_Error( 'wpmcp_invalid_token', 'The access token is invalid or has expired.', array( 'status' => 401 ) );
			}
			wp_set_current_user( $grant['user_id'] );
			self::$header_authenticated = true;
			return true;
		}

		$stored = (string) get_option( 'wpmcp_api_key', '' );
		if ( '' === $stored && ! WPMCP_OAuth::enabled() ) {
			return new WP_Error(
				'wpmcp_no_key',
				'No API key configured on the site. Open the WP MCP admin menu and generate one.',
				array( 'status' => 500 )
			);
		}

		$from_header = '' !== $provided;
		if ( ! $from_header && '1' === (string) get_option( 'wpmcp_allow_url_key', '1' ) ) {
			$params = $request->get_url_params();
			$provided = isset( $params['key'] ) && is_string( $params['key'] ) ? $params['key'] : '';
		}
		if ( '' === $provided ) {
			// No credentials at all: point OAuth-capable clients at the sign-in flow.
			if ( WPMCP_OAuth::enabled() ) {
				WPMCP_OAuth::send_challenge();
				return new WP_Error( 'wpmcp_missing_key', 'Authorization required. Connect through OAuth or send an API key in the x-api-key header.', array( 'status' => 401 ) );
			}
			return new WP_Error(
				'wpmcp_missing_key',
				'Missing API key. Send it in the x-api-key header (or Authorization: Bearer).',
				array( 'status' => 403 )
			);
		}

		if ( '' === $stored ) {
			return new WP_Error( 'wpmcp_bad_key', 'Invalid API key.', array( 'status' => 403 ) );
		}

		$fail_key = self::fail_key();
		if ( (int) get_transient( $fail_key ) >= self::MAX_FAILURES ) {
			return new WP_Error(
				'wpmcp_rate_limited',
				'Too many invalid API keys from this address. Try again in a few minutes.',
				array( 'status' => 429 )
			);
		}

		if ( ! hash_equals( $stored, $provided ) ) {
			set_transient( $fail_key, (int) get_transient( $fail_key ) + 1, self::FAILURE_WINDOW );
			return new WP_Error(
				'wpmcp_bad_key',
				'Invalid API key.',
				array( 'status' => 403 )
			);
		}

		delete_transient( $fail_key );
		self::$header_authenticated = $from_header;
		return true;
	}

	/**
	 * Transient name that counts failed key attempts for the calling address.
	 *
	 * Uses REMOTE_ADDR only. Behind a reverse proxy or CDN, return the real
	 * client address from the wpmcp_client_ip filter, otherwise every caller
	 * shares one counter.
	 *
	 * @return string
	 */
	public static function fail_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip = (string) apply_filters( 'wpmcp_client_ip', $ip );
		return 'wpmcp_fail_' . md5( $ip );
	}
}
