<?php
/**
 * Plugin settings: defaults, retrieval, and sanitisation.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return the hard-coded default settings array.
 *
 * @return array
 */
function ringo_default_settings() {
	return [
		'mode' => 'test', // 'test' | 'live'

		// Stripe keys
		'stripe_test_secret'      => '',
		'stripe_live_secret'      => '',
		'stripe_test_publishable' => '',
		'stripe_live_publishable' => '',

		// PayPal REST
		'paypal_mode'              => 'sandbox', // 'sandbox' | 'live'
		'paypal_sandbox_client_id' => '',
		'paypal_sandbox_secret'    => '',
		'paypal_live_client_id'    => '',
		'paypal_live_secret'       => '',

		'prices' => [
			'standard' => 59.99,
			'featured' => 79.99,
			'vip'      => 225.99,
			'pro'      => 225.99,
		],

		'descriptions' => [
			'standard' => 'Boat info · 4 photos · Social media package · Listed on standard page',
			'featured' => 'Boat info · 10 photos · YouTube video on site · Social media package · Listed on featured page',
			'vip'      => 'Boat info · 25 photos · Up to 2 YouTube videos · Social media package · Rotating top of site · Rotating cover photos · VIP page and Instagram highlights',
			'pro'      => 'BASS Elite, MLF Tour, NPFL · 25 photos · Up to 2 YouTube videos · Social media package · Rotating top of site · Rotating cover photos · PRO page and Instagram highlights',
		],

		'abandoned_after'      => 300, // seconds (5 minutes) - checkout age before follow-up emails
		'stuck_watchdog_after' => 300, // seconds (5 minutes) - browser "payment not advancing" watchdog
		'stuck_assets_after'   => 300, // seconds (5 minutes) - paid listing's background photo upload considered stuck
	];
}

/**
 * Retrieve the merged, validated settings array.
 *
 * @return array
 */
function ringo_get_settings() {
	$defaults = ringo_default_settings();
	$opts     = get_option( 'ringo_stripe_settings', [] );
	if ( ! is_array( $opts ) ) {
		$opts = [];
	}

	$merged = wp_parse_args( $opts, $defaults );

	// Ensure sub-arrays are properly merged.
	foreach ( [ 'prices', 'descriptions' ] as $key ) {
		if ( ! isset( $merged[ $key ] ) || ! is_array( $merged[ $key ] ) ) {
			$merged[ $key ] = $defaults[ $key ];
		} else {
			$merged[ $key ] = wp_parse_args( $merged[ $key ], $defaults[ $key ] );
		}
	}

	$merged['mode']           = ( $merged['mode'] === 'live' ) ? 'live' : 'test';
	$merged['paypal_mode']    = ( $merged['paypal_mode'] === 'live' ) ? 'live' : 'sandbox';
	$merged['abandoned_after']      = max( 0, (int) ( $merged['abandoned_after'] ?? $defaults['abandoned_after'] ) );
	$merged['stuck_watchdog_after'] = max( 30, (int) ( $merged['stuck_watchdog_after'] ?? $defaults['stuck_watchdog_after'] ) );
	$merged['stuck_assets_after']   = max( 60, (int) ( $merged['stuck_assets_after'] ?? $defaults['stuck_assets_after'] ) );

	return $merged;
}

/**
 * Sanitise the raw $_POST input before saving to the database.
 *
 * @param  array $input Raw form input.
 * @return array        Sanitised settings.
 */
function ringo_sanitize_settings( $input ) {
	$defaults = ringo_default_settings();
	$out      = [];

	$out['mode'] = ( isset( $input['mode'] ) && $input['mode'] === 'live' ) ? 'live' : 'test';

	// Credentials. The settings page never sends a stored secret back to the
	// browser, so an empty secret field means "keep what is saved"; the
	// "clear" checkbox is the only way to blank one. See includes/secrets.php.
	$stored  = get_option( 'ringo_stripe_settings', [] );
	$stored  = is_array( $stored ) ? $stored : [];
	$clear   = ( isset( $input['clear'] ) && is_array( $input['clear'] ) ) ? $input['clear'] : [];
	$is_prod = function_exists( 'ringo_is_production_site' ) ? ringo_is_production_site() : true;
	$secrets = function_exists( 'ringo_secret_fields' ) ? ringo_secret_fields() : [];
	$live    = function_exists( 'ringo_live_credential_fields' ) ? ringo_live_credential_fields() : [];

	foreach (
		[
			'stripe_test_secret',
			'stripe_live_secret',
			'stripe_test_publishable',
			'stripe_live_publishable',
			'paypal_sandbox_client_id',
			'paypal_sandbox_secret',
			'paypal_live_client_id',
			'paypal_live_secret',
		] as $field
	) {
		$old       = isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
		$submitted = isset( $input[ $field ] ) ? trim( sanitize_text_field( (string) $input[ $field ] ) ) : '';

		if ( ! empty( $clear[ $field ] ) ) {
			$out[ $field ] = '';
			continue;
		}

		// A new live value is never saved on a non production site.
		$new_is_live = in_array( $field, $live, true )
			|| ( 0 === strpos( $field, 'stripe_' ) && function_exists( 'ringo_is_live_stripe_key' ) && ringo_is_live_stripe_key( $submitted ) );
		if ( '' !== $submitted && ! $is_prod && $new_is_live && $submitted !== $old ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( 'ringo_stripe_settings', 'ringo_live_refused_' . $field, 'Not saved: live keys cannot be stored on a non production site (' . $field . ').' );
			}
			$out[ $field ] = $old;
			continue;
		}

		if ( in_array( $field, $secrets, true ) ) {
			$out[ $field ] = ( '' !== $submitted ) ? $submitted : $old;
		} else {
			// Client IDs and publishable keys are not secret and are shown in the
			// form, so the submitted value is the value. A field locked by a
			// constant is disabled (not submitted): keep what is stored.
			$locked        = function_exists( 'ringo_credential_constant_in_use' ) && ringo_credential_constant_in_use( $field );
			$out[ $field ] = ( $locked && ! isset( $input[ $field ] ) ) ? $old : $submitted;
		}
	}

	$out['paypal_mode'] = ( isset( $input['paypal_mode'] ) && $input['paypal_mode'] === 'live' ) ? 'live' : 'sandbox';

	$out['prices'] = $defaults['prices'];
	foreach ( array_keys( $defaults['prices'] ) as $k ) {
		if ( isset( $input['prices'][ $k ] ) ) {
			$v = (float) $input['prices'][ $k ];
			$out['prices'][ $k ] = ( $v >= 0 ) ? $v : $defaults['prices'][ $k ];
		}
	}

	$out['descriptions'] = $defaults['descriptions'];
	foreach ( array_keys( $defaults['descriptions'] ) as $k ) {
		if ( isset( $input['descriptions'][ $k ] ) ) {
			$out['descriptions'][ $k ] = sanitize_textarea_field( $input['descriptions'][ $k ] );
		}
	}

	$out['abandoned_after'] = isset( $input['abandoned_after'] )
		? max( 0, (int) $input['abandoned_after'] )
		: $defaults['abandoned_after'];

	$out['stuck_watchdog_after'] = isset( $input['stuck_watchdog_after'] )
		? max( 30, (int) $input['stuck_watchdog_after'] )
		: $defaults['stuck_watchdog_after'];

	$out['stuck_assets_after'] = isset( $input['stuck_assets_after'] )
		? max( 60, (int) $input['stuck_assets_after'] )
		: $defaults['stuck_assets_after'];

	return $out;
}
