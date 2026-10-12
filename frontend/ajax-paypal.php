<?php
/**
 * AJAX handlers: create and capture PayPal orders (popup button flow).
 *
 * Registered for both logged-in and logged-out users.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Create order ─────────────────────────────────────────────────────────────

add_action( 'wp_ajax_ringo_paypal_create_order',        'ringo_ajax_paypal_create_order' );
add_action( 'wp_ajax_nopriv_ringo_paypal_create_order', 'ringo_ajax_paypal_create_order' );

/**
 * Create a PayPal order and return its ID so the JS SDK can open the
 * checkout experience inside the existing popup (no page redirect).
 */
function ringo_ajax_paypal_create_order() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_paypal_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$package_label        = isset( $_POST['package_name'] )  ? trim( (string) wp_unslash( $_POST['package_name'] ) ) : '';
	$boat_post_id         = isset( $_POST['boat_post_id'] )   ? (int) wp_unslash( $_POST['boat_post_id'] )           : 0;
	$form_id              = isset( $_POST['form_id'] )        ? sanitize_text_field( wp_unslash( $_POST['form_id'] ) ) : '';
	$user_email           = isset( $_POST['user_email'] )     ? sanitize_email( wp_unslash( $_POST['user_email'] ) )  : '';
	$package_price_client = isset( $_POST['package_price'] )      ? (float) wp_unslash( $_POST['package_price'] )      : 0;
	$base_price_client    = isset( $_POST['base_package_price'] ) ? (float) wp_unslash( $_POST['base_package_price'] ) : 0;
	$addon_ids            = ringo_get_requested_addon_ids( $_POST['addon_ids'] ?? [] );

	if ( ! $package_label ) {
		if ( $boat_post_id ) {
			ringo_record_draft_failure( $boat_post_id, 'payment_setup_error', 'PayPal package was missing.', [ 'source' => 'paypal_create_order' ] );
		}
		wp_send_json_error( [ 'message' => 'Package is required', 'condition' => 'payment_setup_error' ] );
	}
	if ( ! $boat_post_id ) {
		wp_send_json_error( [ 'message' => 'Boat post ID missing' ] );
	}

	$post = get_post( $boat_post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat not found' ] );
	}
	if ( $post->post_status !== 'draft' ) {
		wp_send_json_error( [ 'message' => 'Boat must be in draft before payment (current: ' . $post->post_status . ')' ] );
	}
	// SECURITY: only the listing's owner (or an admin) may start a payment for it, and
	// never for a listing that is already paid. The nonce on its own is public.
	if ( ! ringo_current_user_owns_boat( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to pay for this listing.', 'condition' => 'payment_setup_error' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid.' ], 409 );
	}

	if ( $user_email && is_email( $user_email ) ) {
		ringo_save_customer_email_if_provided( $boat_post_id, $user_email );
	}

	// Recalculate the package and selected add-ons on the server so a client
	// cannot alter the amount that PayPal receives.
	$fallback_base = $base_price_client > 0 ? $base_price_client : $package_price_client;
	$totals        = ringo_get_checkout_totals( $package_label, $addon_ids, $fallback_base );
	$amount        = (float) $totals['subtotal'];
	// Dealer accounts pay for their listing outside the site: only add-ons are charged.
	// Dealer with nothing to pay (no paid add-ons): publish instead of erroring.
	if ( $amount <= 0 && '' !== ( $ringo_free_url = ringo_checkout_publish_waived_free( $boat_post_id, $package_label ) ) ) {
		wp_send_json_success( [ 'free_checkout' => true, 'redirect' => $ringo_free_url ] );
	}
	if ( ( $totals['base'] <= 0 && ! ringo_checkout_base_waived( $package_label, $boat_post_id ) ) || $amount <= 0 ) {
		ringo_record_draft_failure( $boat_post_id, 'payment_setup_error', 'PayPal checkout total was invalid.', [ 'source' => 'paypal_create_order', 'package' => $package_label ] );
		wp_send_json_error( [ 'message' => 'Invalid checkout total', 'condition' => 'payment_setup_error' ] );
	}

	ringo_save_boat_addons( $boat_post_id, $totals['addon_ids'], (float) $totals['base'] );

	// Apply coupon if provided.
	$coupon_code = isset( $_POST['coupon_code'] ) ? strtoupper( sanitize_text_field( trim( (string) wp_unslash( $_POST['coupon_code'] ) ) ) ) : '';
	if ( $coupon_code ) {
		$coupon_result = ringo_apply_coupon( $coupon_code, $amount, $user_email );
		if ( is_wp_error( $coupon_result ) ) {
			// Tell the buyer instead of silently charging the full price.
			wp_send_json_error( [ 'message' => $coupon_result->get_error_message(), 'condition' => 'payment_setup_error' ] );
		}
		$amount = $coupon_result['final_price'];
		update_post_meta( $boat_post_id, '_ringo_coupon_code', sanitize_text_field( $coupon_code ) );
	} else {
		delete_post_meta( $boat_post_id, '_ringo_coupon_code' ); // no stale coupon from an earlier attempt
	}

	// 100%-off coupon: nothing to charge, so skip PayPal and mark the listing paid.
	if ( $amount <= 0 ) {
		ringo_set_payment_meta( $boat_post_id, 'coupon', 'unpaid', $package_label, 0, '', '', $form_id );
		update_post_meta( $boat_post_id, '_ringo_checkout_created_at', time() );
		if ( ringo_process_paid( $boat_post_id, 'coupon', 'coupon-' . $boat_post_id . '-' . $coupon_code, $package_label, 0 ) ) {
			update_post_meta( $boat_post_id, '_ringo_amount', 0 ); // record that nothing was charged
			wp_send_json_success( [
				'free_checkout' => true,
				'redirect'      => home_url( '/thank-you/?provider=coupon&boat_post_id=' . $boat_post_id ),
			] );
		}
		wp_send_json_error( [ 'message' => 'Could not complete the free listing. Please contact support.', 'condition' => 'payment_setup_error' ] );
	}
	if ( $amount < 0.01 ) {
		wp_send_json_error( [ 'message' => 'The total after your coupon is too small to charge. Please contact support to finish this listing.', 'condition' => 'payment_setup_error' ] );
	}

	ringo_update_payment_activity( $boat_post_id, 'paypal_order_request', 'paypal', [ 'source' => 'server' ] );

	$token = ringo_paypal_get_access_token();
	if ( ! $token ) {
		ringo_record_draft_failure( $boat_post_id, 'paypal_no_response', 'PayPal access token request did not return a token.', [ 'source' => 'paypal_create_order' ] );
		wp_send_json_error( [ 'message' => 'PayPal is not configured or did not respond. Please contact admin.', 'condition' => 'paypal_no_response' ] );
	}

	$custom_desc = ringo_get_package_description( $package_label );
	$description  = $custom_desc ? $package_label . ' - ' . $custom_desc : $package_label;
	$addon_names  = array_map( static function ( $item ) { return (string) $item['name']; }, $totals['addon_items'] );
	if ( $addon_names ) {
		$description .= ' + ' . implode( ', ', $addon_names );
	}
	$description = substr( $description, 0, 127 ); // PayPal max length

	$c = ringo_get_paypal_active_credentials();

	$payload = [
		'intent'         => 'CAPTURE',
		'purchase_units' => [
			[
				'reference_id' => 'boat_' . $boat_post_id,
				'amount'       => [
					'currency_code' => 'USD',
					'value'         => number_format( (float) $amount, 2, '.', '' ),
				],
				'custom_id'   => (string) $boat_post_id,
				'description' => $description,
			],
		],
		'application_context' => [
			'brand_name'  => 'BassBoat4Sale',
			'landing_page'=> 'NO_PREFERENCE',
			'user_action' => 'PAY_NOW',
		],
	];

	$resp = wp_remote_post(
		$c['api_base'] . '/v2/checkout/orders',
		[
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( $payload ),
		]
	);

	if ( is_wp_error( $resp ) ) {
		$message   = 'PayPal error: ' . $resp->get_error_message();
		$condition = ringo_is_gateway_timeout( $resp->get_error_message() ) ? 'gateway_timeout' : 'paypal_no_response';
		ringo_record_draft_failure( $boat_post_id, $condition, $message, [ 'source' => 'paypal_create_order_http' ] );
		wp_send_json_error( [ 'message' => $message, 'condition' => $condition ] );
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( ! ( $code >= 200 && $code < 300 ) || empty( $body['id'] ) ) {
		$message   = 'PayPal error: could not create order (HTTP ' . $code . ')';
		$condition = ringo_is_gateway_timeout( '', $code ) ? 'gateway_timeout' : 'paypal_order_error';
		ringo_record_draft_failure( $boat_post_id, $condition, $message, [ 'source' => 'paypal_create_order_response', 'http_code' => $code, 'paypal_status' => (string) ( $body['status'] ?? '' ) ] );
		wp_send_json_error( [ 'message' => $message, 'condition' => $condition ] );
	}

	$order_id = (string) $body['id'];
	ringo_update_payment_activity( $boat_post_id, 'paypal_order_created', 'paypal', [ 'order_id' => $order_id ] );

	update_post_meta( $boat_post_id, '_ringo_checkout_created_at', time() );

	// Snapshot the cart this order was priced for, so capture can restore exactly what was paid.
	update_post_meta( $boat_post_id, '_ringo_paypal_order_cart', wp_json_encode( [
		'order'     => $order_id,
		'addon_ids' => array_values( $totals['addon_ids'] ),
		'base'      => (float) $totals['base'],
	] ) );

	ringo_set_payment_meta(
		$boat_post_id,
		'paypal',
		'unpaid',
		$package_label,
		$amount,
		$order_id,
		'',
		$form_id
	);

	delete_post_meta( $boat_post_id, '_ringo_pending_email_sent' );
	delete_post_meta( $boat_post_id, '_ringo_pending_admin_email_sent' );
	delete_post_meta( $boat_post_id, '_ringo_new_draft_admin_email_sent' );

	wp_send_json_success( [ 'order_id' => $order_id ] );
}

// ─── Capture order ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_ringo_paypal_capture_order',        'ringo_ajax_paypal_capture_order' );
add_action( 'wp_ajax_nopriv_ringo_paypal_capture_order', 'ringo_ajax_paypal_capture_order' );

/**
 * Capture an approved PayPal order, publish the boat, and return a redirect URL.
 *
 * Called from the JS `onApprove` callback — the user has already approved
 * the payment inside the PayPal popup at this point.
 */
function ringo_ajax_paypal_capture_order() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_paypal_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$order_id     = isset( $_POST['order_id'] )     ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
	$boat_post_id = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] )              : 0;

	if ( ! $order_id ) {
		if ( $boat_post_id ) {
			ringo_record_draft_failure( $boat_post_id, 'payment_setup_error', 'PayPal capture was called without an order ID.', [ 'source' => 'paypal_capture_order' ] );
		}
		wp_send_json_error( [ 'message' => 'Missing order ID', 'condition' => 'payment_setup_error' ] );
	}
	if ( ! $boat_post_id ) {
		wp_send_json_error( [ 'message' => 'Missing boat post ID' ] );
	}

	// SECURITY: only the listing's owner (or an admin) may capture a payment for it.
	if ( ! ringo_current_user_owns_boat( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to pay for this listing.', 'condition' => 'payment_setup_error' ], 403 );
	}

	// Guard against double-capture if the user somehow triggers this twice.
	if ( get_post_meta( $boat_post_id, '_ringo_checkout_status', true ) === 'paid' ) {
		wp_send_json_success( [
			'redirect' => home_url( '/thank-you/?provider=paypal&boat_post_id=' . $boat_post_id ),
		] );
	}

	// SECURITY: the order being captured must be the one created for THIS boat, so a
	// paid order from another listing cannot be replayed here.
	if ( (string) get_post_meta( $boat_post_id, '_ringo_paypal_order_id', true ) !== $order_id ) {
		ringo_record_draft_failure( $boat_post_id, 'payment_setup_error', 'PayPal order does not belong to this listing.', [ 'source' => 'paypal_capture_order_mismatch', 'order_id' => $order_id ] );
		wp_send_json_error( [ 'message' => 'This PayPal order does not belong to this listing.', 'condition' => 'payment_setup_error' ], 403 );
	}

	ringo_update_payment_activity( $boat_post_id, 'paypal_capturing', 'paypal', [ 'order_id' => $order_id, 'source' => 'server' ] );

	$token = ringo_paypal_get_access_token();
	if ( ! $token ) {
		ringo_record_draft_failure( $boat_post_id, 'paypal_no_response', 'PayPal access token request failed before capture.', [ 'source' => 'paypal_capture_order', 'order_id' => $order_id ] );
		wp_send_json_error( [ 'message' => 'PayPal is not configured or did not respond. Please contact admin.', 'condition' => 'paypal_no_response' ] );
	}

	$c = ringo_get_paypal_active_credentials();

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
		$message   = 'PayPal capture error: ' . $resp->get_error_message();
		$condition = ringo_is_gateway_timeout( $resp->get_error_message() ) ? 'gateway_timeout' : 'paypal_no_response';
		ringo_record_draft_failure( $boat_post_id, $condition, $message, [ 'source' => 'paypal_capture_http', 'order_id' => $order_id ] );
		wp_send_json_error( [ 'message' => $message, 'condition' => $condition ] );
	}

	$code = wp_remote_retrieve_response_code( $resp );
	$body = json_decode( wp_remote_retrieve_body( $resp ), true );

	if ( ! ( $code >= 200 && $code < 300 ) ) {
		$message   = 'PayPal capture failed (HTTP ' . $code . ')';
		$condition = ringo_is_gateway_timeout( '', $code ) ? 'gateway_timeout' : 'paypal_capture_error';
		ringo_record_draft_failure( $boat_post_id, $condition, $message, [ 'source' => 'paypal_capture_response', 'order_id' => $order_id, 'http_code' => $code ] );
		wp_send_json_error( [ 'message' => $message, 'condition' => $condition ] );
	}

	$top_status  = (string) ( $body['status'] ?? '' );
	$cap_status  = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['status'] ?? '' );
	$is_completed = ( $top_status === 'COMPLETED' || $cap_status === 'COMPLETED' );

	if ( ! $is_completed ) {
		$status_value = $cap_status ?: $top_status ?: 'unknown';
		$message      = 'Payment not completed (status: ' . $status_value . ')';
		$pending      = in_array( strtoupper( $status_value ), [ 'PENDING', 'APPROVED', 'CREATED', 'PAYER_ACTION_REQUIRED' ], true );
		$condition    = $pending ? 'payment_pending' : 'paypal_capture_error';
		ringo_record_draft_failure( $boat_post_id, $condition, $message, [ 'source' => 'paypal_capture_status', 'order_id' => $order_id, 'top_status' => $top_status, 'capture_status' => $cap_status ] );
		wp_send_json_error( [ 'message' => $message, 'condition' => $condition ] );
	}

	$capture_id = (string) ( $body['purchase_units'][0]['payments']['captures'][0]['id'] ?? '' );
	if ( $capture_id ) {
		update_post_meta( $boat_post_id, '_ringo_paypal_capture_id', sanitize_text_field( $capture_id ) );
	}

	$package_name  = (string) get_post_meta( $boat_post_id, '_ringo_package', true );
	$package_price = (float)  get_post_meta( $boat_post_id, '_ringo_amount',  true );
	if ( $package_price <= 0 && $package_name ) {
		$package_price = ringo_get_package_price( $package_name );
	}

	ringo_update_payment_activity( $boat_post_id, 'payment_complete', 'paypal', [ 'order_id' => $order_id ] );
	ringo_restore_paypal_paid_cart( $boat_post_id, $order_id );
	if ( ! ringo_process_paid( $boat_post_id, 'paypal', $order_id, $package_name, $package_price ) ) {
		// Money was captured but the listing could not be completed: never report success.
		wp_send_json_error( [
			'message'   => 'Your PayPal payment was received, but we could not finish publishing the listing. Please contact support and do not pay again.',
			'condition' => 'payment_incomplete',
		] );
	}

	wp_send_json_success( [
		'redirect' => home_url( '/thank-you/?provider=paypal&boat_post_id=' . $boat_post_id ),
	] );
}
