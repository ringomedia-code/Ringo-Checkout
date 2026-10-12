<?php
/**
 * Thank-you page handler.
 *
 * Verifies payment status on page load and finalises the booking:
 *  - Stripe: retrieves the PaymentIntent and checks its status.
 *  - PayPal:  acts as a safety net for the rare case where a user
 *             lands on this URL directly (the normal flow uses the
 *             AJAX capture endpoint, which runs first).
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'template_redirect', 'ringo_handle_thank_you_page' );

/**
 * Run on every page load; bail early if not the thank-you page.
 */
function ringo_handle_thank_you_page() {
	if ( ! is_page( 'thank-you' ) ) {
		return;
	}

	$provider = isset( $_GET['provider'] ) ? sanitize_text_field( wp_unslash( $_GET['provider'] ) ) : '';

	if ( $provider === 'stripe' ) {
		ringo_thank_you_stripe();
	} elseif ( $provider === 'paypal' ) {
		ringo_thank_you_paypal();
	} elseif ( $provider === 'admin' ) {
		ringo_thank_you_admin();
	}
}

/**
 * Find a boat by a stored payment-provider identifier.
 *
 * @param string $meta_key Payment meta key.
 * @param string $value Provider identifier.
 * @return int
 */
function ringo_find_boat_by_payment_meta( $meta_key, $value ) {
	$allowed = [ '_ringo_stripe_payment_intent', '_ringo_paypal_order_id' ];
	if ( ! in_array( $meta_key, $allowed, true ) || ! $value ) {
		return 0;
	}

	$found = new WP_Query( [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => [ 'draft', 'publish', 'pending', 'private' ],
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'   => $meta_key,
				'value' => sanitize_text_field( (string) $value ),
			],
		],
	] );

	return ! empty( $found->posts[0] ) ? (int) $found->posts[0] : 0;
}

// ─── Stripe ───────────────────────────────────────────────────────────────────

/**
 * Verify a Stripe PaymentIntent and publish the listing if it succeeded.
 */
function ringo_thank_you_stripe() {
	$pi_id = isset( $_GET['payment_intent'] )
		? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) )
		: '';
	$post_id = isset( $_GET['boat_post_id'] ) ? (int) wp_unslash( $_GET['boat_post_id'] ) : 0;

	if ( ! $pi_id ) {
		if ( $post_id ) {
			ringo_record_draft_failure( $post_id, 'stripe_payment_incomplete', 'Stripe returned without a PaymentIntent identifier.', [
				'source' => 'stripe_thank_you_missing_intent',
			] );
		}
		return;
	}

	if ( ! $post_id ) {
		$post_id = ringo_find_boat_by_payment_meta( '_ringo_stripe_payment_intent', $pi_id );
	}

	if ( ! ringo_load_stripe_sdk() ) {
		if ( $post_id ) {
			ringo_record_draft_failure( $post_id, 'gateway_unavailable', 'Stripe SDK was unavailable on the thank-you safety check.', [
				'source'            => 'stripe_thank_you_sdk',
				'payment_intent_id' => $pi_id,
			] );
		}
		return;
	}

	$secret = ringo_get_active_stripe_secret();
	if ( ! $secret ) {
		if ( $post_id ) {
			ringo_record_draft_failure( $post_id, 'payment_setup_error', 'Stripe credentials were unavailable on the thank-you safety check.', [
				'source'            => 'stripe_thank_you_credentials',
				'payment_intent_id' => $pi_id,
			] );
		}
		return;
	}

	\Stripe\Stripe::setApiKey( $secret );

	try {
		$intent = \Stripe\PaymentIntent::retrieve( $pi_id );
		$status = (string) ( $intent->status ?? '' );

		if ( ! $post_id ) {
			$post_id = isset( $intent->metadata->post_id ) ? (int) $intent->metadata->post_id : 0;
		}

		// SECURITY: this PaymentIntent must belong to the boat named in the URL. The
		// URL is attacker-controlled, so without this a single paid intent could be
		// replayed against any number of other draft boats.
		if ( $post_id ) {
			$stored_pi = (string) get_post_meta( $post_id, '_ringo_stripe_payment_intent', true );
			$meta_post = isset( $intent->metadata->post_id ) ? (int) $intent->metadata->post_id : 0;
			// The intent's own metadata is written server-side, so it is the authority. The
			// stored id is only a fallback: a newer intent (second tab, retry) overwrites it
			// while an earlier intent can still succeed and must not be orphaned.
			if ( $meta_post ? ( $meta_post !== (int) $post_id ) : ( $stored_pi && $stored_pi !== $pi_id ) ) {
				ringo_log( 'Stripe thank-you: REFUSED - PaymentIntent does not belong to this boat', [ 'post_id' => $post_id, 'pi' => $pi_id, 'stored_pi' => $stored_pi, 'intent_post' => $meta_post ] );
				return;
			}
		}

		if ( 'succeeded' !== $status ) {
			ringo_log( 'Stripe thank-you: not succeeded', [
				'pi'      => $pi_id,
				'post_id' => $post_id,
				'status'  => $status ?: 'unknown',
			] );

			if ( $post_id ) {
				if ( in_array( $status, [ 'processing', 'requires_action', 'requires_confirmation' ], true ) ) {
					$condition = 'payment_pending';
				} elseif ( 'canceled' === $status ) {
					$condition = 'payment_cancelled';
				} else {
					$condition = 'stripe_payment_incomplete';
				}

				ringo_record_draft_failure( $post_id, $condition, 'Stripe thank-you check found status: ' . ( $status ?: 'unknown' ) . '.', [
					'source'            => 'stripe_thank_you_status',
					'payment_intent_id' => $pi_id,
					'stripe_status'     => $status,
				] );
			}
			return;
		}

		if ( ! $post_id ) {
			ringo_log( 'Stripe thank-you: could not resolve boat', [ 'pi' => $pi_id ] );
			return;
		}

		if ( get_post_meta( $post_id, '_ringo_checkout_status', true ) === 'paid' ) {
			ringo_log( 'Stripe thank-you: already paid, skipping', [ 'post_id' => $post_id ] );
			return;
		}

		$package_name  = (string) ( $intent->metadata->package ?? '' );
		// Record what Stripe actually charged (base + add-ons - coupon), not only the base
		// package price, so receipts and reports match the real payment.
		$package_price = ringo_stripe_charged_amount( $intent, $post_id, $package_name );

		// Re-apply the add-ons that were actually charged (server-written intent metadata) so
		// add-on meta edited after the intent was created cannot grant unpaid features.
		if ( isset( $intent->metadata->base_amount ) ) {
			$paid_addon_ids = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $intent->metadata->addon_ids ?? '' ) ) ) ) );
			ringo_save_boat_addons( $post_id, $paid_addon_ids, (float) $intent->metadata->base_amount );
		}

		ringo_process_paid( $post_id, 'stripe', $pi_id, $package_name, $package_price );

	} catch ( \Throwable $e ) {
		if ( ! $post_id ) {
			$post_id = ringo_find_boat_by_payment_meta( '_ringo_stripe_payment_intent', $pi_id );
		}
		$condition = ringo_is_gateway_timeout( $e->getMessage() ) ? 'gateway_timeout' : 'gateway_unavailable';
		if ( $post_id ) {
			ringo_record_draft_failure( $post_id, $condition, 'Stripe thank-you status check failed: ' . $e->getMessage(), [
				'source'            => 'stripe_thank_you_exception',
				'payment_intent_id' => $pi_id,
			] );
		} else {
			ringo_log( 'Stripe thank-you error', $e->getMessage() );
		}
	}
}

// ─── PayPal ───────────────────────────────────────────────────────────────────

/**
 * Safety-net handler for PayPal thank-you page visits.
 *
 * In the normal popup flow the AJAX endpoint captures the order before
 * redirecting here, so the listing is already published. This function
 * handles edge cases where someone arrives with an uncaptured order.
 */
function ringo_thank_you_paypal() {
	ringo_log( 'PayPal thank-you hit', [ 'get' => $_GET ] );

	// Prefer ?token= (PayPal's standard redirect param) then ?order_id=.
	$order_id = '';
	foreach ( [ 'token', 'order_id' ] as $key ) {
		if ( ! empty( $_GET[ $key ] ) ) {
			$order_id = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			break;
		}
	}

	$boat_post_id = isset( $_GET['boat_post_id'] ) ? (int) wp_unslash( $_GET['boat_post_id'] ) : 0;

	if ( ! $order_id ) {
		ringo_log( 'PayPal thank-you: missing token/order_id' );
		if ( $boat_post_id ) {
			ringo_record_draft_failure( $boat_post_id, 'paypal_no_response', 'PayPal returned without an order identifier.', [
				'source' => 'paypal_thank_you_missing_order',
			] );
		}
		return;
	}

	// Try to resolve the boat post from the URL or by querying meta.

	if ( ! $boat_post_id ) {
		$boat_post_id = ringo_find_boat_by_payment_meta( '_ringo_paypal_order_id', $order_id );
	}

	if ( ! $boat_post_id ) {
		ringo_log( 'PayPal thank-you: could not resolve boat_post_id', [ 'order_id' => $order_id ] );
		return;
	}

	// SECURITY: the order in the URL must be the one created for this boat.
	$stored_order = (string) get_post_meta( $boat_post_id, '_ringo_paypal_order_id', true );
	if ( $stored_order !== $order_id ) {
		ringo_log( 'PayPal thank-you: REFUSED - order does not belong to this boat', [ 'post_id' => $boat_post_id, 'order_id' => $order_id, 'stored_order' => $stored_order ] );
		return;
	}

	if ( get_post_meta( $boat_post_id, '_ringo_checkout_status', true ) === 'paid' ) {
		ringo_log( 'PayPal thank-you: already paid, skipping', [ 'post_id' => $boat_post_id ] );
		return;
	}

	$token = ringo_paypal_get_access_token();
	if ( ! $token ) {
		ringo_log( 'PayPal thank-you: access token failed (check mode/keys)' );
		ringo_record_draft_failure( $boat_post_id, 'paypal_no_response', 'PayPal access token request failed on the thank-you safety check.', [
			'source'   => 'paypal_thank_you_token',
			'order_id' => $order_id,
		] );
		return;
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
		$message   = $resp->get_error_message();
		$condition = ringo_is_gateway_timeout( $message ) ? 'gateway_timeout' : 'paypal_no_response';
		ringo_log( 'PayPal order status wp_error', $message );
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal order status check failed: ' . $message, [
			'source'   => 'paypal_thank_you_status_http',
			'order_id' => $order_id,
		] );
		return;
	}

	$code   = wp_remote_retrieve_response_code( $resp );
	$body   = json_decode( wp_remote_retrieve_body( $resp ), true );
	$status = (string) ( $body['status'] ?? '' );

	ringo_log( 'PayPal order status response', [ 'code' => $code, 'status' => $status ] );

	if ( ! ( $code >= 200 && $code < 300 ) ) {
		$condition = ringo_is_gateway_timeout( '', $code ) ? 'gateway_timeout' : 'gateway_unavailable';
		ringo_log( 'PayPal order status failed', [ 'code' => $code ] );
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal order status returned HTTP ' . $code . '.', [
			'source'    => 'paypal_thank_you_status_response',
			'order_id'  => $order_id,
			'http_code' => $code,
		] );
		return;
	}

	if ( $status === 'APPROVED' ) {
		ringo_paypal_capture_and_process( $boat_post_id, $order_id, $c, $token );
	} elseif ( $status === 'COMPLETED' ) {
		ringo_log( 'PayPal order already completed', [ 'post_id' => $boat_post_id ] );
		ringo_paypal_finish_paid( $boat_post_id, $order_id );
	} else {
		ringo_log( 'PayPal order not approved/completed', [ 'status' => $status, 'post_id' => $boat_post_id ] );
		update_post_meta( $boat_post_id, '_ringo_checkout_status', 'unpaid' );
		if ( in_array( $status, [ 'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' ], true ) ) {
			$condition = 'payment_pending';
		} elseif ( in_array( $status, [ 'VOIDED', 'CANCELLED', 'CANCELED' ], true ) ) {
			$condition = 'payment_cancelled';
		} else {
			$condition = 'payment_incomplete';
		}
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal thank-you check found status: ' . ( $status ?: 'unknown' ) . '.', [
			'source'        => 'paypal_thank_you_status',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		] );
	}
}

/**
 * Capture an approved PayPal order and, if successful, call ringo_process_paid().
 *
 * @param int    $boat_post_id
 * @param string $order_id
 * @param array  $credentials  From ringo_get_paypal_active_credentials().
 * @param string $token        Bearer token.
 */
function ringo_paypal_capture_and_process( $boat_post_id, $order_id, $credentials, $token ) {
	$resp = wp_remote_post(
		$credentials['api_base'] . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
		[
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => '{}',
		]
	);

	if ( is_wp_error( $resp ) ) {
		$message   = $resp->get_error_message();
		$condition = ringo_is_gateway_timeout( $message ) ? 'gateway_timeout' : 'paypal_capture_error';
		ringo_log( 'PayPal capture wp_error', $message );
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal capture failed: ' . $message, [
			'source'   => 'paypal_thank_you_capture_http',
			'order_id' => $order_id,
		] );
		return;
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	ringo_log( 'PayPal capture response', [ 'code' => $code, 'body' => $body ] );

	if ( ! ( $code >= 200 && $code < 300 ) ) {
		$condition = ringo_is_gateway_timeout( '', $code ) ? 'gateway_timeout' : 'paypal_capture_error';
		ringo_log( 'PayPal capture failed', [ 'code' => $code ] );
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal capture returned HTTP ' . $code . '.', [
			'source'    => 'paypal_thank_you_capture_response',
			'order_id'  => $order_id,
			'http_code' => $code,
		] );
		return;
	}

	$top_status   = (string) ( $body['status'] ?? '' );
	$cap_status   = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['status'] ?? '' );
	$is_completed = ( $top_status === 'COMPLETED' || $cap_status === 'COMPLETED' );

	if ( ! $is_completed ) {
		$condition = in_array( $top_status, [ 'PENDING', 'APPROVED' ], true ) || 'PENDING' === $cap_status
			? 'payment_pending'
			: 'paypal_capture_error';
		ringo_record_draft_failure( $boat_post_id, $condition, 'PayPal capture did not complete.', [
			'source'         => 'paypal_thank_you_capture_status',
			'order_id'       => $order_id,
			'top_status'     => $top_status,
			'capture_status' => $cap_status,
		] );
		return;
	}

	$capture_id = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );
	if ( $capture_id ) {
		update_post_meta( $boat_post_id, '_ringo_paypal_capture_id', sanitize_text_field( $capture_id ) );
	}

	ringo_paypal_finish_paid( $boat_post_id, $order_id );
}

/**
 * Read stored package info and call ringo_process_paid().
 *
 * @param int    $boat_post_id
 * @param string $order_id
 */
function ringo_paypal_finish_paid( $boat_post_id, $order_id ) {
	$package_name  = (string) get_post_meta( $boat_post_id, '_ringo_package', true );
	$package_price = (float)  get_post_meta( $boat_post_id, '_ringo_amount',  true );

	if ( $package_price <= 0 && $package_name ) {
		$package_price = ringo_get_package_price( $package_name );
	}

	ringo_restore_paypal_paid_cart( $boat_post_id, $order_id );
	ringo_process_paid( $boat_post_id, 'paypal', $order_id, $package_name, $package_price );
}

// ─── Admin bypass ─────────────────────────────────────────────────────────────

/**
 * Thank-you handler for administrator-bypassed listings.
 *
 * The boat was already published by the AJAX endpoint; this function just
 * logs the page hit and confirms the listing is live. If somehow the post
 * is still a draft (e.g. the AJAX call failed silently), it will publish it
 * here as a safety net.
 */
function ringo_thank_you_admin() {
	if ( ! current_user_can( 'administrator' ) ) {
		return; // Safety: non-admins cannot trigger this path.
	}

	$post_id = isset( $_GET['boat_post_id'] ) ? (int) wp_unslash( $_GET['boat_post_id'] ) : 0;
	if ( ! $post_id ) {
		ringo_log( 'Admin thank-you: missing boat_post_id' );
		return;
	}

	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		ringo_log( 'Admin thank-you: invalid post', [ 'post_id' => $post_id ] );
		return;
	}

	// Safety net: publish if still a draft (e.g. AJAX was interrupted).
	if ( $post->post_status === 'draft' ) {
		ringo_log( 'Admin thank-you: safety-net publish', [ 'post_id' => $post_id ] );
		$package_name = (string) get_post_meta( $post_id, '_ringo_package', true );
		ringo_process_paid( $post_id, 'admin', 'admin-bypass-thankyou-' . get_current_user_id(), $package_name, 0 );
	} else {
		ringo_log( 'Admin thank-you: boat already live', [ 'post_id' => $post_id, 'status' => $post->post_status ] );
	}
}
