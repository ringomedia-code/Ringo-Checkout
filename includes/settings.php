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
		$out[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
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
