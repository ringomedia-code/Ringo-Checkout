<?php
/**
 * Cron: abandoned-checkout / payment-failure checker.
 *
 * Runs every 10 minutes. For each boat post that is still 'unpaid' or
 * 'payment_failed' and older than the configured window (abandoned_after,
 * default 5 minutes), it rechecks directly against the gateway and either:
 *   (a) marks it paid (if Stripe/PayPal reports the payment as completed), or
 *   (b) sends the payment-failed follow-up emails to the customer and admin,
 *       once the failure is confirmed to still be unresolved after the wait.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Schedule registration ────────────────────────────────────────────────────

add_filter( 'cron_schedules', 'ringo_add_cron_intervals' );

function ringo_add_cron_intervals( $schedules ) {
	if ( ! isset( $schedules['ringo_10min'] ) ) {
		$schedules['ringo_10min'] = [
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => 'Every 10 Minutes (Ringo)',
		];
	}
	return $schedules;
}

add_action( 'init', 'ringo_maybe_schedule_cron' );

function ringo_maybe_schedule_cron() {
	if ( ! wp_next_scheduled( 'ringo_check_abandoned_checkouts' ) ) {
		wp_schedule_event( time() + 60, 'ringo_10min', 'ringo_check_abandoned_checkouts' );
		ringo_log( 'Cron scheduled via init' );
	}
}

// ─── Cron callback ────────────────────────────────────────────────────────────

add_action( 'ringo_check_abandoned_checkouts', 'ringo_process_abandoned_checkouts' );

/**
 * Query for unpaid boat posts older than the threshold and process each one.
 */
function ringo_process_abandoned_checkouts() {
	$settings        = ringo_get_settings();
	$abandoned_after = (int) ( $settings['abandoned_after'] ?? 1800 );

	$q = new WP_Query( [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => 'draft',
		'posts_per_page' => 50,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				// Also catches card/gateway declines (payment_failed) so a
				// buyer who never retries still gets followed up on, instead
				// of silently never being emailed at all.
				'key'     => '_ringo_checkout_status',
				'value'   => [ 'unpaid', 'payment_failed' ],
				'compare' => 'IN',
			],
		],
	] );

	if ( empty( $q->posts ) ) {
		return;
	}

	// Initialise Stripe SDK once for the batch.
	$stripe_ready  = false;
	if ( ringo_load_stripe_sdk() ) {
		$stripe_secret = ringo_get_active_stripe_secret();
		if ( $stripe_secret ) {
			\Stripe\Stripe::setApiKey( $stripe_secret );
			$stripe_ready = true;
		}
	}

	foreach ( $q->posts as $post_id ) {
		$created_at = (int) get_post_meta( $post_id, '_ringo_checkout_created_at', true );
		if ( ! $created_at ) {
			$created_at = (int) get_post_meta( $post_id, '_ringo_payment_attempt_started_at', true );
		}
		if ( ! $created_at ) {
			$created_at = (int) get_post_meta( $post_id, '_ringo_unpaid_time', true );
		}
		if ( ! $created_at ) {
			$created_at = (int) get_post_time( 'U', true, $post_id );
		}

		if ( ( time() - $created_at ) < $abandoned_after ) {
			continue;
		}

		$provider = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );

		if ( $provider === 'stripe' ) {
			ringo_cron_handle_stripe( $post_id, $stripe_ready );
		} elseif ( $provider === 'paypal' ) {
			ringo_cron_handle_paypal( $post_id );
		} else {
			$state         = (string) get_post_meta( $post_id, '_ringo_payment_state', true );
			$last_activity = (int) get_post_meta( $post_id, '_ringo_payment_last_activity', true );
			$is_stuck      = $last_activity && ( time() - $last_activity ) >= $abandoned_after && preg_match( '/^(stripe|paypal)_/', $state );
			$condition     = $is_stuck ? 'payment_snippet_stuck' : 'checkout_abandoned';
			$message       = $is_stuck
				? 'Payment activity stopped before a gateway transaction completed.'
				: 'The checkout was left before a payment provider completed the transaction.';

			// notify=false: this cron's own payment-failed email (below) is the sole
			// customer/admin notification now - don't also fire draft-failure's email.
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'        => 'abandoned_cron',
				'payment_state' => $state,
			], false );
			ringo_mark_followed_up( $post_id, $condition, $message );
		}
	}
}

// ─── Per-provider handlers ────────────────────────────────────────────────────

/**
 * Check a Stripe PaymentIntent and publish if succeeded; otherwise follow up.
 *
 * @param int  $post_id
 * @param bool $stripe_ready Whether the SDK is loaded and keyed.
 */
function ringo_cron_handle_stripe( $post_id, $stripe_ready ) {
	$pi_id = (string) get_post_meta( $post_id, '_ringo_stripe_payment_intent', true );

	if ( ! $pi_id ) {
		$message = 'Stripe checkout ended before a PaymentIntent was created.';
		ringo_record_draft_failure( $post_id, 'checkout_abandoned', $message, [
			'source' => 'abandoned_cron_stripe',
		], false );
		ringo_mark_followed_up( $post_id, 'checkout_abandoned', $message );
		return;
	}

	if ( ! $stripe_ready ) {
		$message = 'Stripe could not be checked by the server.';
		ringo_record_draft_failure( $post_id, 'gateway_unavailable', $message, [
			'source'            => 'abandoned_cron_stripe',
			'payment_intent_id' => $pi_id,
		], false );
		ringo_mark_followed_up( $post_id, 'gateway_unavailable', $message );
		return;
	}

	try {
		$intent = \Stripe\PaymentIntent::retrieve( $pi_id );
		$status = (string) ( $intent->status ?? '' );

		ringo_log( 'Cron Stripe status checked', [
			'post_id' => $post_id,
			'pi_id'   => $pi_id,
			'status'  => $status,
		] );

		if ( 'succeeded' === $status ) {
			$package_name  = (string) ( $intent->metadata->package ?? '' );
			// Record what Stripe actually charged (base + add-ons - coupon), not only the base
			// package price, so receipts and reports match the real payment.
			$package_price = ringo_stripe_charged_amount( $intent, $post_id, $package_name );

			ringo_update_payment_activity( $post_id, 'payment_complete', 'stripe', [ 'source' => 'abandoned_cron' ] );
			ringo_process_paid( $post_id, 'stripe', $pi_id, $package_name, $package_price );
			return;
		}

		// Stripe's own decline reason (e.g. "Your card was declined.") is far more useful
		// to a buyer than a generic message, so surface it when the gateway provided one.
		$decline_reason = (string) ( $intent->last_payment_error->message ?? '' );

		if ( in_array( $status, [ 'processing', 'requires_action', 'requires_confirmation' ], true ) ) {
			$condition = 'payment_pending';
			$message   = $decline_reason ?: ( 'Stripe payment is delayed or pending (status: ' . $status . ').' );
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
				'stripe_status'     => $status,
			], false );
		} elseif ( 'canceled' === $status ) {
			$condition = 'payment_cancelled';
			$message   = $decline_reason ?: 'Stripe PaymentIntent was cancelled.';
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
			], false );
		} else {
			$condition = 'payment_incomplete';
			$message   = $decline_reason ?: ( 'Stripe did not complete payment (status: ' . ( $status ?: 'unknown' ) . ').' );
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
				'stripe_status'     => $status,
			], false );
		}
	} catch ( \Exception $e ) {
		$condition = ringo_is_gateway_timeout( $e->getMessage() ) ? 'gateway_timeout' : 'gateway_unavailable';
		$message   = 'Stripe status check failed: ' . $e->getMessage();
		ringo_record_draft_failure( $post_id, $condition, $message, [
			'source'            => 'abandoned_cron_stripe_exception',
			'payment_intent_id' => $pi_id,
		], false );
	}

	ringo_mark_followed_up( $post_id, $condition ?? 'payment_incomplete', $message ?? '' );
}

/**
 * Check a PayPal order, capture if approved, publish if completed; otherwise follow up.
 *
 * @param int $post_id
 */
function ringo_cron_handle_paypal( $post_id ) {
	$order_id = (string) get_post_meta( $post_id, '_ringo_paypal_order_id', true );
	if ( ! $order_id ) {
		$message = 'PayPal checkout ended before an order was created.';
		ringo_record_draft_failure( $post_id, 'checkout_abandoned', $message, [
			'source' => 'abandoned_cron_paypal',
		], false );
		ringo_mark_followed_up( $post_id, 'checkout_abandoned', $message );
		return;
	}

	$status = ringo_paypal_get_order_status( $order_id );
	if ( ! $status ) {
		$message = 'PayPal did not return an order status.';
		ringo_record_draft_failure( $post_id, 'paypal_no_response', $message, [
			'source'   => 'abandoned_cron_paypal_status',
			'order_id' => $order_id,
		], false );
		ringo_mark_followed_up( $post_id, 'paypal_no_response', $message );
		return;
	}

	ringo_log( 'Cron PayPal status checked', [
		'post_id' => $post_id,
		'order_id'=> $order_id,
		'status'  => $status,
	] );

	// Attempt to capture if the user approved but the browser stopped before capture.
	if ( 'APPROVED' === $status ) {
		$token = ringo_paypal_get_access_token();
		if ( ! $token ) {
			$message = 'PayPal access token failed during delayed capture.';
			ringo_record_draft_failure( $post_id, 'paypal_no_response', $message, [
				'source'   => 'abandoned_cron_paypal_capture',
				'order_id' => $order_id,
			], false );
			ringo_mark_followed_up( $post_id, 'paypal_no_response', $message );
			return;
		}

		$c    = ringo_get_paypal_active_credentials();
		$resp = wp_remote_post(
			$c['api_base'] . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
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
			$condition = ringo_is_gateway_timeout( $resp->get_error_message() ) ? 'gateway_timeout' : 'paypal_no_response';
			$message   = 'PayPal delayed capture failed: ' . $resp->get_error_message();
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'   => 'abandoned_cron_paypal_capture_http',
				'order_id' => $order_id,
			], false );
			ringo_mark_followed_up( $post_id, $condition, $message );
			return;
		}

		$code       = wp_remote_retrieve_response_code( $resp );
		$body       = json_decode( wp_remote_retrieve_body( $resp ), true );
		$top_status = (string) ( $body['status'] ?? '' );
		$cap_status = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['status'] ?? '' );

		if ( $code >= 200 && $code < 300 && ( 'COMPLETED' === $top_status || 'COMPLETED' === $cap_status ) ) {
			$status     = 'COMPLETED';
			$capture_id = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );
			if ( $capture_id ) {
				update_post_meta( $post_id, '_ringo_paypal_capture_id', sanitize_text_field( $capture_id ) );
			}
		} elseif ( ! ( $code >= 200 && $code < 300 ) ) {
			$condition = ringo_is_gateway_timeout( '', $code ) ? 'gateway_timeout' : 'paypal_capture_error';
			$message   = 'PayPal delayed capture returned HTTP ' . $code . '.';
			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'   => 'abandoned_cron_paypal_capture_response',
				'order_id' => $order_id,
				'http_code'=> $code,
			], false );
			ringo_mark_followed_up( $post_id, $condition, $message );
			return;
		} else {
			$message = 'PayPal capture is still pending.';
			ringo_record_draft_failure( $post_id, 'payment_pending', $message, [
				'source'         => 'abandoned_cron_paypal_capture_status',
				'order_id'       => $order_id,
				'top_status'     => $top_status,
				'capture_status' => $cap_status,
			], false );
			ringo_mark_followed_up( $post_id, 'payment_pending', $message );
			return;
		}
	}

	if ( 'COMPLETED' === $status ) {
		$package_name  = (string) get_post_meta( $post_id, '_ringo_package', true );
		$package_price = (float) get_post_meta( $post_id, '_ringo_amount', true );
		if ( $package_price <= 0 && $package_name ) {
			$package_price = ringo_get_package_price( $package_name );
		}

		ringo_update_payment_activity( $post_id, 'payment_complete', 'paypal', [ 'source' => 'abandoned_cron' ] );
		ringo_process_paid( $post_id, 'paypal', $order_id, $package_name, $package_price );
		return;
	}

	if ( in_array( $status, [ 'CREATED', 'SAVED', 'PAYER_ACTION_REQUIRED' ], true ) ) {
		$condition = 'payment_pending';
		$message   = 'PayPal payment is delayed or awaiting action (status: ' . $status . ').';
		ringo_record_draft_failure( $post_id, $condition, $message, [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		], false );
	} elseif ( in_array( $status, [ 'VOIDED', 'CANCELLED', 'CANCELED' ], true ) ) {
		$condition = 'payment_cancelled';
		$message   = 'PayPal order was cancelled (status: ' . $status . ').';
		ringo_record_draft_failure( $post_id, $condition, $message, [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		], false );
	} else {
		$condition = 'payment_incomplete';
		$message   = 'PayPal did not complete payment (status: ' . $status . ').';
		ringo_record_draft_failure( $post_id, $condition, $message, [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		], false );
	}

	ringo_mark_followed_up( $post_id, $condition, $message );
}

// ─── Shared follow-up helper ──────────────────────────────────────────────────

/**
 * Send the single payment-failed email pair (one to the customer, one to the
 * admin) and mark the post as followed_up.
 *
 * Runs after a checkout has been rechecked directly against the gateway (Stripe
 * or PayPal) and confirmed to still not be paid - so this always reflects a
 * reverified, persistent failure, never an instant/first-attempt decline. This
 * is the only notification sent for an unresolved checkout - draft-failure's
 * own email is suppressed (notify=false) at every call site above, and the
 * separate "new draft" admin email is not sent here.
 *
 * @param int    $post_id
 * @param string $condition     Failure condition code (see ringo_get_draft_failure_label()).
 * @param string $error_message Human-readable reason the payment never completed.
 */
function ringo_mark_followed_up( $post_id, $condition, $error_message = '' ) {
	ringo_send_payment_failed_customer_email( $post_id, $condition, $error_message );
	ringo_send_payment_failed_admin_email( $post_id, $condition, $error_message );
	update_post_meta( $post_id, '_ringo_checkout_status', 'followed_up' );
}
