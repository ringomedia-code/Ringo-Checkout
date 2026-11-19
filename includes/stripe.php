<?php
/**
 * Stripe SDK loader and active-key helpers.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attempt to load the Stripe PHP SDK.
 *
 * Checks for a Composer autoloader at the WordPress root first, then falls
 * back to a local vendor copy shipped with this plugin.
 *
 * @return bool TRUE when \Stripe\Stripe is available.
 */
function ringo_load_stripe_sdk() {
	$composer = ABSPATH . 'vendor/autoload.php';
	if ( file_exists( $composer ) ) {
		require_once $composer;
	}

	if ( ! class_exists( '\Stripe\Stripe' ) ) {
		$local = RINGO_CHECKOUT_DIR . 'vendor/stripe/stripe-php/init.php';
		if ( file_exists( $local ) ) {
			require_once $local;
		}
	}

	return class_exists( '\Stripe\Stripe' );
}

/**
 * Return the currently active Stripe secret key.
 *
 * Order: RINGO_STRIPE_{LIVE|TEST}_SECRET constant, then the saved setting,
 * then the legacy RINGO_STRIPE_SECRET_KEY constant. Off production the mode
 * is always test and a live key is never returned (see includes/secrets.php).
 *
 * @return string
 */
function ringo_get_active_stripe_secret() {
	$field = ( 'live' === ringo_get_effective_stripe_mode() ) ? 'stripe_live_secret' : 'stripe_test_secret';
	$key   = ringo_get_usable_credential( $field );
	if ( '' !== $key ) {
		return $key;
	}

	if ( defined( 'RINGO_STRIPE_SECRET_KEY' ) && RINGO_STRIPE_SECRET_KEY ) {
		$legacy = trim( (string) RINGO_STRIPE_SECRET_KEY );
		if ( ringo_is_production_site() || ! ringo_is_live_stripe_key( $legacy ) ) {
			return $legacy;
		}
	}

	return '';
}

/**
 * Return the currently active Stripe publishable key.
 *
 * Same order and staging guard as the secret key.
 *
 * @return string
 */
function ringo_get_active_stripe_publishable() {
	$field = ( 'live' === ringo_get_effective_stripe_mode() ) ? 'stripe_live_publishable' : 'stripe_test_publishable';
	$key   = ringo_get_usable_credential( $field );
	if ( '' !== $key ) {
		return $key;
	}

	if ( defined( 'RINGO_STRIPE_PUBLISHABLE_KEY' ) && RINGO_STRIPE_PUBLISHABLE_KEY ) {
		$legacy = trim( (string) RINGO_STRIPE_PUBLISHABLE_KEY );
		if ( ringo_is_production_site() || ! ringo_is_live_stripe_key( $legacy ) ) {
			return $legacy;
		}
	}

	return '';
}
