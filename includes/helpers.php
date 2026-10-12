<?php
/**
 * Global helper functions.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Logging ─────────────────────────────────────────────────────────────────

if ( ! function_exists( 'ringo_log' ) ) {
	/**
	 * Write a prefixed line to the PHP error log.
	 *
	 * @param string     $message Human-readable label.
	 * @param mixed|null $data    Optional context (array, object, or scalar).
	 */
	function ringo_log( $message, $data = null ) {
		$line = '🧾 [Ringo Pay] ' . $message;
		if ( null !== $data ) {
			$line .= ' | ' . ( is_array( $data ) || is_object( $data )
				? wp_json_encode( $data )
				: (string) $data );
		}
		// Routine payment-event lines used to be written to php_errorlog on every
		// event, which bloats that file. They are still kept in the DB log below;
		// set RINGO_DEBUG (or use the filter) to also mirror them to error_log.
		if ( ( defined( 'RINGO_DEBUG' ) && RINGO_DEBUG ) || apply_filters( 'ringo_log_to_error_log', false, $message ) ) {
			error_log( $line );
		}

		// Persist to DB so the admin Email Logs page can display entries.
		$entry = [
			'time'    => current_time( 'mysql' ),
			'message' => $message,
			'data'    => $data,
		];
		$logs = get_option( 'ringo_email_logs', [] );
		array_unshift( $logs, $entry );         // newest first
		$logs = array_slice( $logs, 0, 200 );   // cap at 200 entries
		update_option( 'ringo_email_logs', $logs, false );
	}
}

// ─── Boat post types and dealer billing ──────────────────────────────────────

/**
 * Post types this plugin treats as a boat listing. Regular sellers create 'boats';
 * Dealer / Dealer Plus accounts create 'dlr_boats'.
 *
 * @return string[]
 */
function ringo_boat_post_types() {
	return (array) apply_filters( 'ringo_boat_post_types', [ 'boats', 'dlr_boats' ] );
}

/**
 * @param string $post_type Post type slug.
 * @return bool
 */
function ringo_is_boat_post_type( $post_type ) {
	return in_array( (string) $post_type, ringo_boat_post_types(), true );
}

/**
 * Package keys used for dealer accounts. They are paid for outside the site, so they
 * have no listing price on the add-boat form.
 *
 * @return string[]
 */
function ringo_dealer_package_keys() {
	return [ 'dealer_plus', 'dealer' ];
}

/**
 * @param string $package_label Raw package label.
 * @return bool
 */
function ringo_package_is_dealer( $package_label ) {
	return in_array( strtolower( trim( (string) $package_label ) ), ringo_dealer_package_keys(), true );
}

/**
 * Return 'dealer' or 'dealer_plus' for a Dealer / Dealer Plus account, otherwise ''.
 * Administrators are never treated as dealers, even if they also hold a dealer role.
 *
 * @param int $user_id User ID (0 = current user).
 * @return string
 */
function ringo_user_dealer_package( $user_id = 0 ) {
	$user = $user_id ? get_userdata( (int) $user_id ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}
	$roles = (array) $user->roles;
	if ( in_array( 'administrator', $roles, true ) ) {
		return '';
	}
	foreach ( ringo_dealer_package_keys() as $key ) {
		if ( in_array( $key, $roles, true ) ) {
			return $key;
		}
	}
	return '';
}

/**
 * True when the listing's base package price is waived because the boat's author really
 * is a Dealer / Dealer Plus account. The package name comes from the browser, so it is
 * only honoured when the author's own role matches it.
 *
 * @param string $package_label Package label sent by the browser.
 * @param int    $post_id       Boat ID (0 = current user is the author).
 * @return bool
 */
function ringo_checkout_base_waived( $package_label, $post_id = 0 ) {
	if ( ! ringo_package_is_dealer( $package_label ) ) {
		return false;
	}
	$owner = $post_id ? (int) get_post_field( 'post_author', (int) $post_id ) : get_current_user_id();
	return ringo_user_dealer_package( $owner ) === strtolower( trim( (string) $package_label ) );
}

// ─── Access / payability guards (security) ───────────────────────────────────

/**
 * Publish a dealer listing that has nothing to pay (base waived, no paid add-ons) from a
 * payment endpoint, so an empty total completes instead of erroring. Returns the thank-you
 * URL, or '' when this listing is not eligible.
 *
 * @param int    $post_id       Boat ID.
 * @param string $package_label Package label sent by the browser.
 * @return string
 */
function ringo_checkout_publish_waived_free( $post_id, $package_label ) {
	if ( ! ringo_checkout_base_waived( $package_label, $post_id ) ) {
		return '';
	}
	if ( ! ringo_process_paid( $post_id, 'dealer', 'dealer-' . (int) $post_id, $package_label, 0 ) ) {
		return '';
	}
	update_post_meta( $post_id, '_ringo_amount', 0 );
	return home_url( '/thank-you/?provider=dealer&boat_post_id=' . (int) $post_id );
}

if ( ! function_exists( 'ringo_current_user_owns_boat' ) ) {
	/**
	 * True when the current (logged-in) user is the boat's author or an administrator.
	 * Used to stop anyone holding a public page nonce from acting on other people's listings.
	 *
	 * @param int $post_id Boat post ID.
	 * @return bool
	 */
	function ringo_current_user_owns_boat( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) || ! is_user_logged_in() ) {
			return false;
		}
		return current_user_can( 'administrator' ) || (int) $post->post_author === get_current_user_id();
	}
}

if ( ! function_exists( 'ringo_boat_is_open_for_payment' ) ) {
	/**
	 * True for an existing boat draft that has not been paid yet.
	 *
	 * @param int $post_id Boat post ID.
	 * @return bool
	 */
	function ringo_boat_is_open_for_payment( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) || 'draft' !== $post->post_status ) {
			return false;
		}
		return 'paid' !== get_post_meta( $post->ID, '_ringo_checkout_status', true );
	}
}

// ─── Package helpers ─────────────────────────────────────────────────────────

/**
 * Return the canonical, lower-cased package key from a raw label.
 *
 * @param  string $label Raw label from form field or meta.
 * @return string        E.g. "featured", "vip".
 */
function ringo_normalize_package_key( $label ) {
	return preg_replace( '/\s+/', ' ', strtolower( trim( (string) $label ) ) );
}

/**
 * Return the price for a package by its label.
 *
 * @param  string $package_label Raw label.
 * @return float
 */
function ringo_get_package_price( $package_label ) {
	$key      = ringo_normalize_package_key( $package_label );
	$settings = ringo_get_settings();
	$prices   = isset( $settings['prices'] ) && is_array( $settings['prices'] )
		? $settings['prices']
		: [];

	return isset( $prices[ $key ] ) ? (float) $prices[ $key ] : 0.0;
}

/**
 * Return the description for a package by its label.
 *
 * @param  string $package_label Raw label.
 * @return string
 */
function ringo_get_package_description( $package_label ) {
	$key      = ringo_normalize_package_key( $package_label );
	$settings = ringo_get_settings();
	$descs    = isset( $settings['descriptions'] ) && is_array( $settings['descriptions'] )
		? $settings['descriptions']
		: [];

	return isset( $descs[ $key ] ) ? (string) $descs[ $key ] : '';
}

// ─── Post / email helpers ────────────────────────────────────────────────────

/**
 * Retrieve the customer e-mail associated with a boat post.
 *
 * @param  int $post_id
 * @return string Sanitised e-mail, or empty string.
 */
function ringo_get_form_email( $post_id ) {
	$candidates = [
		get_post_meta( $post_id, 'email',                    true ),
		get_post_meta( $post_id, 'Email',                    true ),
		get_post_meta( $post_id, '_ringo_customer_email',    true ),
	];

	foreach ( $candidates as $v ) {
		$v = sanitize_email( (string) $v );
		if ( $v && is_email( $v ) ) {
			return $v;
		}
	}

	return '';
}

/**
 * Persist a customer e-mail on the boat post (without overwriting an existing one).
 *
 * @param int    $post_id
 * @param string $email
 */
function ringo_save_customer_email_if_provided( $post_id, $email ) {
	$email = sanitize_email( (string) $email );
	if ( ! $email || ! is_email( $email ) ) {
		return;
	}

	update_post_meta( $post_id, '_ringo_customer_email', $email );

	if ( ! get_post_meta( $post_id, 'email', true ) ) {
		update_post_meta( $post_id, 'email', $email );
	}
}

/**
 * Build the URL for a user to return and complete/edit their draft listing.
 *
 * @param  int $post_id
 * @return string
 */
function ringo_get_draft_edit_url( $post_id ) {
	$post_id = (int) $post_id;
	return $post_id
		? home_url( '/account/edit-post/?_post_id=' . $post_id )
		: home_url( '/account/edit-post/' );
}

// ─── Payment meta helpers ────────────────────────────────────────────────────

/**
 * Write initial payment metadata to a boat post.
 *
 * @param int    $post_id
 * @param string $provider       "stripe" | "paypal"
 * @param string $status         "unpaid" | "paid" | "followed_up"
 * @param string $package
 * @param float  $amount
 * @param string $provider_id    Payment-intent ID or PayPal order ID.
 * @param string $checkout_url   Optional URL saved for abandoned follow-up display.
 * @param string $form_id
 */
function ringo_set_payment_meta(
	$post_id,
	$provider,
	$status,
	$package,
	$amount,
	$provider_id   = '',
	$checkout_url  = '',
	$form_id       = ''
) {
	update_post_meta( $post_id, '_ringo_payment_provider',  sanitize_text_field( (string) $provider ) );
	update_post_meta( $post_id, '_ringo_checkout_status',   sanitize_text_field( (string) $status ) );

	if ( $form_id ) {
		update_post_meta( $post_id, '_ringo_form_id', sanitize_text_field( (string) $form_id ) );
	}
	if ( $package ) {
		update_post_meta( $post_id, '_ringo_package', sanitize_text_field( (string) $package ) );
	}
	if ( (float) $amount > 0 ) {
		update_post_meta( $post_id, '_ringo_amount', (float) $amount );
	}

	if ( $provider === 'stripe' && $provider_id ) {
		update_post_meta( $post_id, '_ringo_stripe_payment_intent', sanitize_text_field( (string) $provider_id ) );
	} elseif ( $provider === 'paypal' && $provider_id ) {
		update_post_meta( $post_id, '_ringo_paypal_order_id', sanitize_text_field( (string) $provider_id ) );
	}

	if ( $checkout_url ) {
		update_post_meta( $post_id, '_ringo_checkout_url', esc_url_raw( $checkout_url ) );
	}
}
