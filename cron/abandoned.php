<?php
/**
 * Cron: abandoned-checkout checker.
 *
 * Runs every 10 minutes. For each boat post that is still 'unpaid' and
 * older than the configured window, it either:
 *   (a) marks it paid (if Stripe/PayPal reports the payment as completed), or
 *   (b) sends abandoned-checkout follow-up emails to the customer and admin.
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
				'key'   => '_ringo_checkout_status',
				'value' => 'unpaid',
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

			ringo_record_draft_failure( $post_id, $condition, $message, [
				'source'        => 'abandoned_cron',
				'payment_state' => $state,
			] );
			ringo_mark_followed_up( $post_id );
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
		ringo_record_draft_failure( $post_id, 'checkout_abandoned', 'Stripe checkout ended before a PaymentIntent was created.', [
			'source' => 'abandoned_cron_stripe',
		] );
		ringo_mark_followed_up( $post_id );
		return;
	}

	if ( ! $stripe_ready ) {
		ringo_record_draft_failure( $post_id, 'gateway_unavailable', 'Stripe could not be checked by the server.', [
			'source'            => 'abandoned_cron_stripe',
			'payment_intent_id' => $pi_id,
		] );
		ringo_mark_followed_up( $post_id );
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

		if ( in_array( $status, [ 'processing', 'requires_action', 'requires_confirmation' ], true ) ) {
			ringo_record_draft_failure( $post_id, 'payment_pending', 'Stripe payment is delayed or pending (status: ' . $status . ').', [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
				'stripe_status'     => $status,
			] );
		} elseif ( 'canceled' === $status ) {
			ringo_record_draft_failure( $post_id, 'payment_cancelled', 'Stripe PaymentIntent was cancelled.', [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
			] );
		} else {
			ringo_record_draft_failure( $post_id, 'payment_incomplete', 'Stripe did not complete payment (status: ' . ( $status ?: 'unknown' ) . ').', [
				'source'            => 'abandoned_cron_stripe',
				'payment_intent_id' => $pi_id,
				'stripe_status'     => $status,
			] );
		}
	} catch ( \Exception $e ) {
		$condition = ringo_is_gateway_timeout( $e->getMessage() ) ? 'gateway_timeout' : 'gateway_unavailable';
		ringo_record_draft_failure( $post_id, $condition, 'Stripe status check failed: ' . $e->getMessage(), [
			'source'            => 'abandoned_cron_stripe_exception',
			'payment_intent_id' => $pi_id,
		] );
	}

	ringo_mark_followed_up( $post_id );
}

/**
 * Check a PayPal order, capture if approved, publish if completed; otherwise follow up.
 *
 * @param int $post_id
 */
function ringo_cron_handle_paypal( $post_id ) {
	$order_id = (string) get_post_meta( $post_id, '_ringo_paypal_order_id', true );
	if ( ! $order_id ) {
		ringo_record_draft_failure( $post_id, 'checkout_abandoned', 'PayPal checkout ended before an order was created.', [
			'source' => 'abandoned_cron_paypal',
		] );
		ringo_mark_followed_up( $post_id );
		return;
	}

	$status = ringo_paypal_get_order_status( $order_id );
	if ( ! $status ) {
		ringo_record_draft_failure( $post_id, 'paypal_no_response', 'PayPal did not return an order status.', [
			'source'   => 'abandoned_cron_paypal_status',
			'order_id' => $order_id,
		] );
		ringo_mark_followed_up( $post_id );
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
			ringo_record_draft_failure( $post_id, 'paypal_no_response', 'PayPal access token failed during delayed capture.', [
				'source'   => 'abandoned_cron_paypal_capture',
				'order_id' => $order_id,
			] );
			ringo_mark_followed_up( $post_id );
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
			ringo_record_draft_failure( $post_id, $condition, 'PayPal delayed capture failed: ' . $resp->get_error_message(), [
				'source'   => 'abandoned_cron_paypal_capture_http',
				'order_id' => $order_id,
			] );
			ringo_mark_followed_up( $post_id );
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
			ringo_record_draft_failure( $post_id, $condition, 'PayPal delayed capture returned HTTP ' . $code . '.', [
				'source'   => 'abandoned_cron_paypal_capture_response',
				'order_id' => $order_id,
				'http_code'=> $code,
			] );
			ringo_mark_followed_up( $post_id );
			return;
		} else {
			ringo_record_draft_failure( $post_id, 'payment_pending', 'PayPal capture is still pending.', [
				'source'         => 'abandoned_cron_paypal_capture_status',
				'order_id'       => $order_id,
				'top_status'     => $top_status,
				'capture_status' => $cap_status,
			] );
			ringo_mark_followed_up( $post_id );
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
		ringo_record_draft_failure( $post_id, 'payment_pending', 'PayPal payment is delayed or awaiting action (status: ' . $status . ').', [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		] );
	} elseif ( in_array( $status, [ 'VOIDED', 'CANCELLED', 'CANCELED' ], true ) ) {
		ringo_record_draft_failure( $post_id, 'payment_cancelled', 'PayPal order was cancelled (status: ' . $status . ').', [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		] );
	} else {
		ringo_record_draft_failure( $post_id, 'payment_incomplete', 'PayPal did not complete payment (status: ' . $status . ').', [
			'source'        => 'abandoned_cron_paypal',
			'order_id'      => $order_id,
			'paypal_status' => $status,
		] );
	}

	ringo_mark_followed_up( $post_id );
}

// ─── Shared follow-up helper ──────────────────────────────────────────────────

/**
 * Send abandoned emails and mark the post as followed_up.
 *
 * @param int $post_id
 */
function ringo_mark_followed_up( $post_id ) {
	ringo_send_payment_pending_customer_email( $post_id );
	ringo_send_payment_pending_admin_email( $post_id );
	ringo_send_admin_new_draft_email( $post_id );
	update_post_meta( $post_id, '_ringo_checkout_status', 'followed_up' );
}
