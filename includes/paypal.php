<?php
/**
 * PayPal REST API helpers: credentials, access token, order status.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the active PayPal credentials based on the current mode setting.
 *
 * @return array {
 *     @type string $mode       'live' | 'sandbox'
 *     @type string $client_id
 *     @type string $secret
 *     @type string $api_base   Root URL for PayPal API calls.
 * }
 */
function ringo_get_paypal_active_credentials() {
	// Constant first, then the saved setting. Off production this is always
	// sandbox and live credentials are never returned (includes/secrets.php).
	if ( 'live' === ringo_get_effective_paypal_mode() ) {
		return [
			'mode'      => 'live',
			'client_id' => ringo_get_usable_credential( 'paypal_live_client_id' ),
			'secret'    => ringo_get_usable_credential( 'paypal_live_secret' ),
			'api_base'  => 'https://api-m.paypal.com',
		];
	}

	return [
		'mode'      => 'sandbox',
		'client_id' => ringo_get_usable_credential( 'paypal_sandbox_client_id' ),
		'secret'    => ringo_get_usable_credential( 'paypal_sandbox_secret' ),
		'api_base'  => 'https://api-m.sandbox.paypal.com',
	];
}

/**
 * Obtain a short-lived PayPal OAuth 2.0 access token.
 *
 * @return string Token, or empty string on failure.
 */
function ringo_paypal_get_access_token() {
	$c = ringo_get_paypal_active_credentials();
	if ( ! $c['client_id'] || ! $c['secret'] ) {
		return '';
	}

	$resp = wp_remote_post(
		$c['api_base'] . '/v1/oauth2/token',
		[
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $c['client_id'] . ':' . $c['secret'] ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'    => 'grant_type=client_credentials',
		]
	);

	if ( is_wp_error( $resp ) ) {
		return '';
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( $code >= 200 && $code < 300 && ! empty( $body['access_token'] ) ) {
		return (string) $body['access_token'];
	}

	return '';
}

/**
 * Retrieve the status string of a PayPal order.
 *
 * @param  string $order_id PayPal order ID.
 * @return string           E.g. 'APPROVED', 'COMPLETED', or '' on failure.
 */
function ringo_paypal_get_order_status( $order_id ) {
	$order_id = sanitize_text_field( (string) $order_id );
	if ( ! $order_id ) {
		return '';
	}

	$token = ringo_paypal_get_access_token();
	if ( ! $token ) {
		return '';
	}

	$c    = ringo_get_paypal_active_credentials();
	$resp = wp_remote_get(
		$c['api_base'] . '/v2/checkout/orders/' . rawurlencode( $order_id ),
		[
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
		]
	);

	if ( is_wp_error( $resp ) ) {
		return '';
	}

	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code < 200 || $code >= 300 ) {
		return '';
	}

	$body = json_decode( wp_remote_retrieve_body( $resp ), true );
	return (string) ( $body['status'] ?? '' );
}
