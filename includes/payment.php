<?php
/**
 * Core payment-processing logic.
 *
 * Handles publishing a boat post after a successful payment and triggering
 * the post-payment notification emails.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize legacy/native boat meta before WordPress fires save_post on publish.
 *
 * The BassBoat4Sale child theme expects the `gallery` value to be a
 * comma-separated list of attachment IDs. Early native-form versions stored an
 * array instead. The theme then called explode() on that array during save_post,
 * causing a fatal TypeError after a successful payment.
 *
 * @param int $post_id Boat post ID.
 * @return void
 */
function ringo_prepare_boat_meta_for_publish( $post_id ) {
	$gallery = get_post_meta( $post_id, 'gallery', true );

	if ( ! is_array( $gallery ) ) {
		return;
	}

	$ids = [];
	array_walk_recursive(
		$gallery,
		static function ( $value ) use ( &$ids ) {
			$id = absint( $value );
			if ( $id ) {
				$ids[] = $id;
			}
		}
	);

	$ids = array_values( array_unique( $ids ) );
	update_post_meta( $post_id, 'gallery', implode( ',', $ids ) );

	ringo_log(
		'Normalized gallery meta before publish',
		[
			'post_id' => (int) $post_id,
			'images'  => count( $ids ),
		]
	);
}


/**
 * Finish a paid boat after its background image upload completes.
 *
 * @param int $post_id Boat post ID.
 * @return bool
 */
function ringo_finalize_paid_boat_after_assets( $post_id ) {
	$post_id = absint( $post_id );
	if ( ! $post_id || 'complete' !== get_post_meta( $post_id, '_ringo_native_assets_status', true ) ) {
		return false;
	}
	if ( 'paid' !== get_post_meta( $post_id, '_ringo_checkout_status', true ) && ! get_post_meta( $post_id, '_ringo_publish_pending_assets', true ) ) {
		return false;
	}

	$provider = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );
	if ( 'stripe' === $provider ) {
		$provider_id = (string) get_post_meta( $post_id, '_ringo_stripe_payment_intent', true );
	} elseif ( 'paypal' === $provider ) {
		$provider_id = (string) get_post_meta( $post_id, '_ringo_paypal_order_id', true );
	} else {
		$provider_id = 'background-assets-' . $post_id;
	}

	$package = (string) get_post_meta( $post_id, '_ringo_package', true );
	$amount  = (float) get_post_meta( $post_id, '_ringo_amount', true );
	delete_post_meta( $post_id, '_ringo_publish_pending_assets' );
	return ringo_process_paid( $post_id, $provider ?: 'pending', $provider_id, $package, $amount );
}
add_action( 'ringo_finalize_paid_boat_after_assets', 'ringo_finalize_paid_boat_after_assets' );

/**
 * Amount actually charged on a Stripe PaymentIntent, in dollars.
 *
 * Falls back to the amount stored when the intent was created, then to the base
 * package price, so an unexpected response never records $0.
 *
 * @param object $intent       Stripe PaymentIntent.
 * @param int    $post_id      Boat post ID.
 * @param string $package_name Package label.
 * @return float
 */
function ringo_stripe_charged_amount( $intent, $post_id, $package_name = '' ) {
	$cents = (int) ( $intent->amount_received ?? 0 );
	if ( $cents <= 0 ) {
		$cents = (int) ( $intent->amount ?? 0 );
	}
	if ( $cents > 0 ) {
		return round( $cents / 100, 2 );
	}
	$stored = (float) get_post_meta( $post_id, '_ringo_amount', true );
	if ( $stored > 0 ) {
		return $stored;
	}
	return (float) ringo_get_package_price( $package_name );
}

/**
 * Restore the add-ons a PayPal order was priced for before the boat is marked paid.
 *
 * Add-on meta can be rewritten after an order is created; the snapshot taken at order
 * creation is what was actually charged. No-op when there is no matching snapshot.
 *
 * @param int    $post_id  Boat ID.
 * @param string $order_id PayPal order ID being paid.
 */
function ringo_restore_paypal_paid_cart( $post_id, $order_id ) {
	$cart = json_decode( (string) get_post_meta( $post_id, '_ringo_paypal_order_cart', true ), true );
	if ( ! is_array( $cart ) || ( $cart['order'] ?? '' ) !== (string) $order_id ) {
		return;
	}
	ringo_save_boat_addons( $post_id, (array) ( $cart['addon_ids'] ?? [] ), (float) ( $cart['base'] ?? 0 ) );
}

/**
 * Mark a boat post as paid, publish it, and dispatch notification emails.
 *
 * This function is idempotent with respect to publishing: if the post is
 * already published it will not be modified again.
 *
 * @param  int    $post_id       ID of the 'boats' post.
 * @param  string $provider      'stripe' | 'paypal'
 * @param  string $provider_id   Stripe PaymentIntent ID or PayPal order ID.
 * @param  string $package_name  Raw package label stored in form meta.
 * @param  float  $package_price Charged amount.
 * @return bool                  TRUE on success, FALSE on failure.
 */
function ringo_process_paid( $post_id, $provider, $provider_id, $package_name, $package_price ) {
	$post = get_post( $post_id );

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		ringo_log( 'ringo_process_paid: invalid post', [ 'post_id' => $post_id ] );
		return false;
	}

	// SECURITY: one gateway payment may only ever pay for one boat. Without this, a
	// single paid Stripe PaymentIntent / PayPal order could be replayed against any
	// number of other draft boats via the thank-you URL.
	if ( in_array( $provider, [ 'stripe', 'paypal' ], true ) && '' !== (string) $provider_id ) {
		global $wpdb;
		$pay_key = ( 'stripe' === $provider ) ? '_ringo_stripe_payment_intent' : '_ringo_paypal_order_id';
		$other   = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 WHERE pm.meta_key = %s AND pm.meta_value = %s AND pm.post_id <> %d
			 AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} s WHERE s.post_id = pm.post_id AND s.meta_key = '_ringo_checkout_status' AND s.meta_value = 'paid' )
			 LIMIT 1",
			$pay_key,
			(string) $provider_id,
			(int) $post_id
		) );
		if ( $other ) {
			ringo_log( 'ringo_process_paid: REFUSED - payment already used for another boat', [ 'post_id' => (int) $post_id, 'provider' => $provider, 'provider_id' => $provider_id, 'used_by' => $other ] );
			ringo_record_draft_failure( $post_id, 'payment_incomplete', 'This payment was already used for another listing.', [ 'source' => 'process_paid_duplicate_payment', 'used_by' => $other ] );
			return false;
		}
	}

	$asset_status  = (string) get_post_meta( $post_id, '_ringo_native_assets_status', true );
	$defer_publish = in_array( $asset_status, [ 'uploading', 'failed' ], true );

	if ( $post->post_status === 'draft' && ! $defer_publish ) {
		ringo_prepare_boat_meta_for_publish( $post_id );

		try {
			$updated = wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ], true );
		} catch ( \Throwable $e ) {
			ringo_log(
				'ringo_process_paid: publish threw an error',
				[
					'post_id' => (int) $post_id,
					'error'   => $e->getMessage(),
				]
			);
			return false;
		}

		if ( is_wp_error( $updated ) ) {
			ringo_log( 'ringo_process_paid: publish failed', $updated->get_error_message() );
			return false;
		}
	}

	// Core payment meta.
	update_post_meta( $post_id, '_ringo_paid',             1 );
	update_post_meta( $post_id, '_ringo_checkout_status',  'paid' );
	update_post_meta( $post_id, '_ringo_payment_provider', sanitize_text_field( $provider ) );
	ringo_update_payment_activity( $post_id, 'payment_complete', $provider, [
		'source'      => 'ringo_process_paid',
		'provider_id' => sanitize_text_field( (string) $provider_id ),
	] );

	// Keep failure history for tracing, but clear the active failure marker once
	// a retry succeeds so the boat no longer appears to have an unresolved issue.
	if ( get_post_meta( $post_id, '_ringo_draft_failure_condition', true ) ) {
		update_post_meta( $post_id, '_ringo_draft_failure_resolved_at', time() );
		delete_post_meta( $post_id, '_ringo_draft_failure_condition' );
		delete_post_meta( $post_id, '_ringo_draft_failure_message' );
		delete_post_meta( $post_id, '_ringo_draft_failure_time' );
	}

	// A successful payment supersedes any earlier declined attempt: drop the stale error
	// and point the last-intent marker at the payment that actually succeeded.
	delete_post_meta( $post_id, '_ringo_payment_error' );
	if ( $provider === 'stripe' && '' !== (string) $provider_id ) {
		update_post_meta( $post_id, '_ringo_payment_intent_id', sanitize_text_field( $provider_id ) );
	}

	if ( $provider === 'stripe' ) {
		update_post_meta( $post_id, '_ringo_stripe_payment_intent', sanitize_text_field( $provider_id ) );
	} elseif ( $provider === 'paypal' ) {
		update_post_meta( $post_id, '_ringo_paypal_order_id', sanitize_text_field( $provider_id ) );
	}

	if ( $package_name ) {
		update_post_meta( $post_id, '_ringo_package', sanitize_text_field( (string) $package_name ) );
	}
	if ( (float) $package_price > 0 ) {
		update_post_meta( $post_id, '_ringo_amount', (float) $package_price );
	}

	// Count the coupon use once, when the boat is actually paid (not when an
	// intent/order is merely created, which let abandoned checkouts use up coupons).
	$coupon_code = (string) get_post_meta( $post_id, '_ringo_coupon_code', true );
	if ( $coupon_code && ! get_post_meta( $post_id, '_ringo_coupon_counted', true ) ) {
		ringo_increment_coupon_use( $coupon_code, (string) ringo_get_form_email( $post_id ) );
		update_post_meta( $post_id, '_ringo_coupon_counted', 1 );
	}

	if ( $defer_publish ) {
		update_post_meta( $post_id, '_ringo_publish_pending_assets', 1 );
		if ( ! wp_next_scheduled( 'ringo_finalize_paid_boat_after_assets', [ (int) $post_id ] ) ) {
			wp_schedule_single_event( time() + 15, 'ringo_finalize_paid_boat_after_assets', [ (int) $post_id ] );
		}
		ringo_log( 'Paid boat publish deferred until background images finish', [ 'post_id' => (int) $post_id, 'asset_status' => $asset_status ] );
		return true;
	}

	delete_post_meta( $post_id, '_ringo_publish_pending_assets' );

	// Archive the checkout URL so it no longer shows as "open" in the tracker.
	$last_url = get_post_meta( $post_id, '_ringo_checkout_url', true );
	if ( $last_url ) {
		update_post_meta( $post_id, '_ringo_checkout_url_last', esc_url_raw( $last_url ) );
		delete_post_meta( $post_id, '_ringo_checkout_url' );
	}

	// Send notification emails.
	ringo_send_publish_email( $post_id, $package_name, $package_price );
	ringo_send_admin_listing_email( $post_id, $package_name, $package_price );

	return true;
}

// ─── Administrator bypass AJAX handler ───────────────────────────────────────

/**
 * AJAX: publish a boat directly without payment (administrator only).
 *
 * Accepts: boat_post_id, package_name, nonce.
 * Sets provider to 'admin' and amount to 0 to indicate a free/bypassed listing.
 */
add_action( 'wp_ajax_ringo_admin_bypass', 'ringo_ajax_admin_bypass' );

function ringo_ajax_admin_bypass() {
	// Only administrators may use this endpoint.
	if ( ! current_user_can( 'administrator' ) ) {
		wp_send_json_error( 'Unauthorized' );
	}

	if ( ! check_ajax_referer( 'ringo_admin_bypass_nonce', 'nonce', false ) ) {
		wp_send_json_error( 'Invalid nonce' );
	}

	$post_id      = isset( $_POST['boat_post_id'] ) ? (int) $_POST['boat_post_id'] : 0;
	$package_name = isset( $_POST['package_name'] ) ? sanitize_text_field( wp_unslash( $_POST['package_name'] ) ) : '';

	if ( ! $post_id ) {
		wp_send_json_error( 'Missing boat_post_id' );
	}

	if ( get_post_meta( $post_id, '_ringo_checkout_status', true ) === 'paid' ) {
		wp_send_json_success( 'Already published' );
	}

	$success = ringo_process_paid( $post_id, 'admin', 'admin-bypass-' . get_current_user_id(), $package_name, 0 );

	if ( $success ) {
		ringo_log( 'Admin bypass: boat published', [ 'post_id' => $post_id, 'user' => get_current_user_id() ] );
		wp_send_json_success( 'Published' );
	} else {
		wp_send_json_error( 'Failed to publish post' );
	}
}
