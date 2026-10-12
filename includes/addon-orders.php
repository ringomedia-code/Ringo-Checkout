<?php
/**
 * Add-on-only orders for already published boats.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'ringo_register_addon_order_post_type' );

/**
 * Register a private order post type used for add-on-only payments.
 *
 * @return void
 */
function ringo_register_addon_order_post_type() {
	register_post_type(
		'ringo_addon_order',
		[
			'labels' => [
				'name'          => 'Add-on Orders',
				'singular_name' => 'Add-on Order',
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'exclude_from_search' => true,
			'supports'            => [ 'title', 'author' ],
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		]
	);
}

/**
 * Return a validated add-on order for the current user.
 *
 * @param int $order_id Order post ID.
 * @return WP_Post|null
 */
function ringo_get_current_user_addon_order( $order_id ) {
	$order = get_post( absint( $order_id ) );
	if ( ! $order || 'ringo_addon_order' !== $order->post_type ) {
		return null;
	}
	if ( ! current_user_can( 'manage_options' ) && (int) $order->post_author !== get_current_user_id() ) {
		return null;
	}
	return $order;
}

/**
 * Return true when the current user can buy add-ons for a boat.
 *
 * @param int $boat_id Boat post ID.
 * @return bool
 */
function ringo_current_user_can_buy_boat_addons( $boat_id ) {
	$boat = get_post( absint( $boat_id ) );
	if ( ! $boat || ! ringo_is_boat_post_type( $boat->post_type ) ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}
	return is_user_logged_in() && (int) $boat->post_author === get_current_user_id();
}

/**
 * Return a fresh server-side total for an add-on order.
 *
 * @param int $order_id Order post ID.
 * @return array<string,mixed>|WP_Error
 */
function ringo_recalculate_addon_order( $order_id ) {
	$ids        = ringo_get_requested_addon_ids( get_post_meta( $order_id, '_ringo_addon_order_ids', true ) );
	$boat_id    = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	$owned_ids  = $boat_id ? ringo_get_boat_addon_ids( $boat_id ) : [];
	$ids        = array_values( array_diff( $ids, $owned_ids ) );
	$calculated = ringo_calculate_addons( $ids );
	if ( empty( $calculated['ids'] ) || (float) $calculated['total'] <= 0 ) {
		return new WP_Error( 'invalid_addons', 'The selected add-ons are no longer available.' );
	}

	update_post_meta( $order_id, '_ringo_addon_order_ids', $calculated['ids'] );
	update_post_meta( $order_id, '_ringo_addon_order_items', $calculated['items'] );
	update_post_meta( $order_id, '_ringo_addon_order_amount', (float) $calculated['total'] );

	return $calculated;
}

/**
 * Complete an add-on-only order and grant the add-ons to its boat.
 *
 * @param int    $order_id Order post ID.
 * @param string $provider Payment provider.
 * @param string $transaction_id Provider transaction ID.
 * @return bool|WP_Error
 */
function ringo_complete_addon_order( $order_id, $provider, $transaction_id ) {
	$order = get_post( absint( $order_id ) );
	if ( ! $order || 'ringo_addon_order' !== $order->post_type ) {
		return new WP_Error( 'invalid_order', 'Add-on order not found.' );
	}

	if ( 'completed' === get_post_meta( $order_id, '_ringo_addon_order_status', true ) ) {
		return true;
	}

	$boat_id = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	$boat    = $boat_id ? get_post( $boat_id ) : null;
	if ( ! $boat || ! ringo_is_boat_post_type( $boat->post_type ) ) {
		return new WP_Error( 'invalid_boat', 'The boat linked to this order no longer exists.' );
	}

	$calculated = ringo_recalculate_addon_order( $order_id );
	if ( is_wp_error( $calculated ) ) {
		return $calculated;
	}

	$existing_ids = ringo_get_boat_addon_ids( $boat_id );
	$merged_ids   = array_values( array_unique( array_merge( $existing_ids, $calculated['ids'] ) ) );
	$package      = (string) get_post_meta( $boat_id, '_ringo_package', true );
	$base_amount  = (float) get_post_meta( $boat_id, '_ringo_base_package_amount', true );
	if ( $base_amount <= 0 && $package ) {
		$base_amount = ringo_get_package_price( $package );
	}

	ringo_save_boat_addons( $boat_id, $merged_ids, $base_amount );

	$history = get_post_meta( $boat_id, '_ringo_addon_purchase_history', true );
	$history = is_array( $history ) ? $history : [];
	$history[] = [
		'order_id'       => (int) $order_id,
		'addon_ids'      => $calculated['ids'],
		'items'          => $calculated['items'],
		'amount'         => (float) $calculated['total'],
		'provider'       => sanitize_key( $provider ),
		'transaction_id' => sanitize_text_field( $transaction_id ),
		'completed_at'   => time(),
	];
	update_post_meta( $boat_id, '_ringo_addon_purchase_history', $history );

	update_post_meta( $order_id, '_ringo_addon_order_status', 'completed' );
	update_post_meta( $order_id, '_ringo_addon_order_provider', sanitize_key( $provider ) );
	update_post_meta( $order_id, '_ringo_addon_order_transaction_id', sanitize_text_field( $transaction_id ) );
	update_post_meta( $order_id, '_ringo_addon_order_completed_at', time() );

	ringo_send_addon_order_emails( $order_id );
	ringo_log(
		'Add-on-only order completed',
		[
			'order_id'       => (int) $order_id,
			'boat_id'        => (int) $boat_id,
			'provider'       => sanitize_key( $provider ),
			'transaction_id' => sanitize_text_field( $transaction_id ),
			'amount'         => (float) $calculated['total'],
		]
	);

	return true;
}

/**
 * Send customer and admin confirmations for an add-on-only order.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function ringo_send_addon_order_emails( $order_id ) {
	if ( get_post_meta( $order_id, '_ringo_addon_order_emails_sent', true ) ) {
		return;
	}

	$boat_id = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	$items   = get_post_meta( $order_id, '_ringo_addon_order_items', true );
	$amount  = (float) get_post_meta( $order_id, '_ringo_addon_order_amount', true );
	$boat    = get_post( $boat_id );
	$user    = get_user_by( 'id', (int) get_post_field( 'post_author', $order_id ) );
	$email   = sanitize_email( (string) get_post_meta( $boat_id, 'email', true ) );
	$email   = $email ?: ( $user ? $user->user_email : '' );
	$names   = [];

	if ( is_array( $items ) ) {
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['name'] ) ) {
				$names[] = (string) $item['name'];
			}
		}
	}

	$subject = 'Your BassBoat4Sale add-ons are active';
	$body    = '<div style="font-family:Arial,sans-serif;color:#263648;line-height:1.6;max-width:620px;margin:auto">'
		. '<h2 style="color:#0876b9">Add-on purchase confirmed</h2>'
		. '<p>Your selected services have been added to <strong>' . esc_html( $boat ? $boat->post_title : 'your boat listing' ) . '</strong>.</p>'
		. '<p><strong>Add-ons:</strong> ' . esc_html( $names ? implode( ', ', $names ) : 'None' ) . '<br><strong>Total:</strong> $' . esc_html( number_format( $amount, 2 ) ) . '</p>'
		. '<p>You can edit the listing from your account. Form add-ons are now reflected in the photo and video fields.</p></div>';
	$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

	if ( $email && is_email( $email ) ) {
		wp_mail( $email, $subject, $body, $headers );
	}

	$admin_email = get_option( 'admin_email' );
	if ( $admin_email && is_email( $admin_email ) ) {
		wp_mail( $admin_email, 'Add-on order completed for boat #' . $boat_id, $body, $headers );
	}

	update_post_meta( $order_id, '_ringo_addon_order_emails_sent', time() );
}

add_action( 'wp_ajax_ringo_create_addon_order', 'ringo_ajax_create_addon_order' );
add_action( 'wp_ajax_ringo_addon_stripe_intent', 'ringo_ajax_addon_stripe_intent' );
add_action( 'wp_ajax_ringo_addon_stripe_complete', 'ringo_ajax_addon_stripe_complete' );
add_action( 'wp_ajax_ringo_addon_paypal_create', 'ringo_ajax_addon_paypal_create' );
add_action( 'wp_ajax_ringo_addon_paypal_capture', 'ringo_ajax_addon_paypal_capture' );

/**
 * AJAX: create a pending order for a published boat.
 *
 * @return void
 */
function ringo_ajax_create_addon_order() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in to purchase add-ons.' ], 401 );
	}
	if ( ! check_ajax_referer( 'ringo_addon_order_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}

	$boat_id = isset( $_POST['boat_id'] ) ? absint( $_POST['boat_id'] ) : 0;
	if ( ! ringo_current_user_can_buy_boat_addons( $boat_id ) ) {
		wp_send_json_error( [ 'message' => 'You cannot purchase add-ons for this boat.' ], 403 );
	}

	$boat = get_post( $boat_id );
	if ( ! $boat || 'publish' !== $boat->post_status ) {
		wp_send_json_error( [ 'message' => 'Add-on-only checkout is available for published boats.' ], 422 );
	}

	$requested_ids = ringo_get_requested_addon_ids( $_POST['addon_ids'] ?? [] );
	$owned_ids     = ringo_get_boat_addon_ids( $boat_id );
	$new_ids       = array_values( array_diff( $requested_ids, $owned_ids ) );
	$calculated    = ringo_calculate_addons( $new_ids );
	if ( empty( $calculated['ids'] ) || (float) $calculated['total'] <= 0 ) {
		wp_send_json_error( [ 'message' => 'Select at least one add-on that is not already active on this boat.' ], 422 );
	}

	$order_id = wp_insert_post(
		[
			'post_type'   => 'ringo_addon_order',
			'post_status' => 'publish',
			'post_title'  => 'Add-on order for boat #' . $boat_id . ' - ' . current_time( 'mysql' ),
			'post_author' => get_current_user_id(),
		],
		true
	);
	if ( is_wp_error( $order_id ) ) {
		wp_send_json_error( [ 'message' => 'The add-on order could not be created.' ], 500 );
	}

	update_post_meta( $order_id, '_ringo_addon_order_status', 'pending' );
	update_post_meta( $order_id, '_ringo_addon_order_boat_id', $boat_id );
	update_post_meta( $order_id, '_ringo_addon_order_ids', $calculated['ids'] );
	update_post_meta( $order_id, '_ringo_addon_order_items', $calculated['items'] );
	update_post_meta( $order_id, '_ringo_addon_order_amount', (float) $calculated['total'] );
	update_post_meta( $order_id, '_ringo_addon_order_created_at', time() );

	wp_send_json_success(
		[
			'order_id' => (int) $order_id,
			'boat_id'  => (int) $boat_id,
			'amount'   => (float) $calculated['total'],
			'items'    => $calculated['items'],
		]
	);
}

/**
 * AJAX: create a Stripe PaymentIntent for an add-on order.
 *
 * @return void
 */
function ringo_ajax_addon_stripe_intent() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'ringo_addon_order_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = ringo_get_current_user_addon_order( $order_id );
	if ( ! $order || 'pending' !== get_post_meta( $order_id, '_ringo_addon_order_status', true ) ) {
		wp_send_json_error( [ 'message' => 'Add-on order not found or already completed.' ], 404 );
	}

	$calculated = ringo_recalculate_addon_order( $order_id );
	if ( is_wp_error( $calculated ) ) {
		wp_send_json_error( [ 'message' => $calculated->get_error_message() ], 422 );
	}
	if ( ! ringo_load_stripe_sdk() || ! ringo_get_active_stripe_secret() ) {
		wp_send_json_error( [ 'message' => 'Stripe is not configured.' ], 500 );
	}

	\Stripe\Stripe::setApiKey( ringo_get_active_stripe_secret() );
	$boat_id = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	try {
		$intent = \Stripe\PaymentIntent::create(
			[
				'amount'               => (int) round( (float) $calculated['total'] * 100 ),
				'currency'             => 'usd',
				'payment_method_types' => [ 'card' ],
				'description'          => 'BassBoat4Sale add-ons for boat #' . $boat_id,
				'metadata'             => [
					'checkout_type' => 'addon_order',
					'order_id'      => (string) $order_id,
					'boat_id'       => (string) $boat_id,
					'addon_ids'     => substr( implode( ',', $calculated['ids'] ), 0, 500 ),
				],
			]
		);
		update_post_meta( $order_id, '_ringo_addon_order_stripe_intent', sanitize_text_field( $intent->id ) );
		wp_send_json_success( [ 'client_secret' => $intent->client_secret, 'payment_intent_id' => $intent->id ] );
	} catch ( Throwable $error ) {
		ringo_log( 'Add-on order Stripe intent failed', [ 'order_id' => $order_id, 'error' => $error->getMessage() ] );
		wp_send_json_error( [ 'message' => 'Stripe could not start the payment: ' . $error->getMessage() ], 500 );
	}
}

/**
 * AJAX: verify a successful Stripe add-on payment and complete the order.
 *
 * @return void
 */
function ringo_ajax_addon_stripe_complete() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'ringo_addon_order_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = ringo_get_current_user_addon_order( $order_id );
	$pi_id    = isset( $_POST['payment_intent_id'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_intent_id'] ) ) : '';
	if ( ! $order || ! $pi_id ) {
		wp_send_json_error( [ 'message' => 'Payment verification information is missing.' ], 422 );
	}
	if ( ! ringo_load_stripe_sdk() || ! ringo_get_active_stripe_secret() ) {
		wp_send_json_error( [ 'message' => 'Stripe is not configured.' ], 500 );
	}

	$calculated = ringo_recalculate_addon_order( $order_id );
	if ( is_wp_error( $calculated ) ) {
		wp_send_json_error( [ 'message' => $calculated->get_error_message() ], 422 );
	}

	\Stripe\Stripe::setApiKey( ringo_get_active_stripe_secret() );
	try {
		$intent = \Stripe\PaymentIntent::retrieve( $pi_id );
		$expected = (int) round( (float) $calculated['total'] * 100 );
		$meta_order = isset( $intent->metadata->order_id ) ? absint( $intent->metadata->order_id ) : 0;
		if ( 'succeeded' !== $intent->status || $meta_order !== $order_id || (int) $intent->amount_received < $expected ) {
			wp_send_json_error( [ 'message' => 'Stripe has not confirmed the full payment yet.' ], 422 );
		}
		$result = ringo_complete_addon_order( $order_id, 'stripe', $pi_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}
		$boat_id  = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
		$edit_url = add_query_arg( 'ringo_addons_updated', '1', ringo_get_draft_edit_url( $boat_id ) );
		wp_send_json_success(
			[
				'message'  => 'Your add-ons are now active.',
				'boat_id'  => $boat_id,
				'edit_url' => esc_url_raw( $edit_url ),
			]
		);
	} catch ( Throwable $error ) {
		wp_send_json_error( [ 'message' => 'Stripe verification failed: ' . $error->getMessage() ], 500 );
	}
}

/**
 * AJAX: create a PayPal order for add-ons.
 *
 * @return void
 */
function ringo_ajax_addon_paypal_create() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'ringo_addon_order_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
	}

	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$order    = ringo_get_current_user_addon_order( $order_id );
	if ( ! $order || 'pending' !== get_post_meta( $order_id, '_ringo_addon_order_status', true ) ) {
		wp_send_json_error( [ 'message' => 'Add-on order not found or already completed.' ], 404 );
	}

	$calculated = ringo_recalculate_addon_order( $order_id );
	if ( is_wp_error( $calculated ) ) {
		wp_send_json_error( [ 'message' => $calculated->get_error_message() ], 422 );
	}

	$token = ringo_paypal_get_access_token();
	$creds = ringo_get_paypal_active_credentials();
	if ( ! $token || empty( $creds['api_base'] ) ) {
		wp_send_json_error( [ 'message' => 'PayPal is not configured.' ], 500 );
	}

	$boat_id = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	$response = wp_remote_post(
		$creds['api_base'] . '/v2/checkout/orders',
		[
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'PayPal-Request-Id' => 'ringo-addon-' . $order_id,
			],
			'body' => wp_json_encode(
				[
					'intent' => 'CAPTURE',
					'purchase_units' => [
						[
							'custom_id'   => (string) $order_id,
							'description' => 'BassBoat4Sale add-ons for boat #' . $boat_id,
							'amount'      => [
								'currency_code' => 'USD',
								'value'         => number_format( (float) $calculated['total'], 2, '.', '' ),
							],
						],
					],
				]
			),
		]
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( [ 'message' => 'PayPal could not start the payment.' ], 500 );
	}
	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( wp_remote_retrieve_response_code( $response ) < 200 || wp_remote_retrieve_response_code( $response ) >= 300 || empty( $body['id'] ) ) {
		wp_send_json_error( [ 'message' => 'PayPal returned an invalid order response.' ], 500 );
	}
	update_post_meta( $order_id, '_ringo_addon_order_paypal_id', sanitize_text_field( $body['id'] ) );
	wp_send_json_success( [ 'paypal_order_id' => sanitize_text_field( $body['id'] ) ] );
}

/**
 * AJAX: capture PayPal payment and complete the add-on order.
 *
 * @return void
 */
function ringo_ajax_addon_paypal_capture() {
	if ( ! is_user_logged_in() || ! check_ajax_referer( 'ringo_addon_order_nonce', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ], 403 );
	}

	$order_id       = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	$paypal_order_id = isset( $_POST['paypal_order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['paypal_order_id'] ) ) : '';
	$order          = ringo_get_current_user_addon_order( $order_id );
	$stored_id      = (string) get_post_meta( $order_id, '_ringo_addon_order_paypal_id', true );
	if ( ! $order || ! $paypal_order_id || ! hash_equals( $stored_id, $paypal_order_id ) ) {
		wp_send_json_error( [ 'message' => 'PayPal order verification failed.' ], 422 );
	}

	$token = ringo_paypal_get_access_token();
	$creds = ringo_get_paypal_active_credentials();
	if ( ! $token || empty( $creds['api_base'] ) ) {
		wp_send_json_error( [ 'message' => 'PayPal is not configured.' ], 500 );
	}

	$response = wp_remote_post(
		$creds['api_base'] . '/v2/checkout/orders/' . rawurlencode( $paypal_order_id ) . '/capture',
		[
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			],
			'body' => '{}',
		]
	);
	if ( is_wp_error( $response ) ) {
		wp_send_json_error( [ 'message' => 'PayPal could not capture the payment.' ], 500 );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 'COMPLETED' !== ( $body['status'] ?? '' ) ) {
		wp_send_json_error( [ 'message' => 'PayPal has not completed the payment.' ], 422 );
	}

	$calculated = ringo_recalculate_addon_order( $order_id );
	if ( is_wp_error( $calculated ) ) {
		wp_send_json_error( [ 'message' => $calculated->get_error_message() ], 422 );
	}

	$captured = (float) ( $body['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? 0 );
	$expected = (float) $calculated['total'];
	if ( $captured + 0.001 < $expected ) {
		wp_send_json_error( [ 'message' => 'PayPal captured less than the expected total.' ], 422 );
	}

	$result = ringo_complete_addon_order( $order_id, 'paypal', $paypal_order_id );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
	}
	$boat_id  = absint( get_post_meta( $order_id, '_ringo_addon_order_boat_id', true ) );
	$edit_url = add_query_arg( 'ringo_addons_updated', '1', ringo_get_draft_edit_url( $boat_id ) );
	wp_send_json_success(
		[
			'message'  => 'Your add-ons are now active.',
			'boat_id'  => $boat_id,
			'edit_url' => esc_url_raw( $edit_url ),
		]
	);
}

/**
 * Enqueue payment assets for the existing-boat add-on shop.
 *
 * @return void
 */
function ringo_enqueue_addon_order_assets() {
	wp_enqueue_script( 'jquery' );
	wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

	$paypal = ringo_get_paypal_active_credentials();
	if ( ! empty( $paypal['client_id'] ) ) {
		$paypal_url = add_query_arg(
			[
				'client-id' => rawurlencode( $paypal['client_id'] ),
				'currency'  => 'USD',
				'intent'    => 'capture',
			],
			'https://www.paypal.com/sdk/js'
		);
		wp_enqueue_script( 'paypal-js', $paypal_url, [], null, true );
	}

	$deps = [ 'jquery', 'stripe-js' ];
	if ( ! empty( $paypal['client_id'] ) ) {
		$deps[] = 'paypal-js';
	}
	wp_register_script( 'ringo-addon-order-checkout', '', $deps, RINGO_CHECKOUT_VERSION, true );
	wp_enqueue_script( 'ringo-addon-order-checkout' );
	wp_add_inline_script(
		'ringo-addon-order-checkout',
		'window.ringoAddonOrders=' . wp_json_encode(
			[
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ringo_addon_order_nonce' ),
				'stripePk' => ringo_get_active_stripe_publishable(),
				'paypal'   => ! empty( $paypal['client_id'] ),
			]
		) . ';',
		'before'
	);
	wp_add_inline_script( 'ringo-addon-order-checkout', ringo_get_addon_order_frontend_js(), 'after' );
}

/**
 * Return the JavaScript used by existing-boat add-on checkout.
 *
 * @return string
 */
function ringo_get_addon_order_frontend_js() {
	return <<<'JS'
jQuery(function($){
  function money(value){
    var amount = parseFloat(value || 0);
    if (isNaN(amount)) amount = 0;
    return '$' + amount.toFixed(2);
  }

  function closeModal(){ $('#ringoAddonOrderOverlay').remove(); }

  function showSuccess($form, message, editUrl){
    closeModal();
    if (editUrl) {
      window.location.assign(editUrl);
      return;
    }
    $form.find('[data-ringo-addon-order-message]').removeClass('is-error').addClass('is-success').text(message || 'Your add-ons are now active.').show();
    $form.find('button[type="submit"]').prop('disabled', false).text('Buy Add-ons');
  }

  function showError($form, message){
    $form.find('[data-ringo-addon-order-message]').removeClass('is-success').addClass('is-error').text(message || 'The add-on payment could not be completed.').show();
    $form.find('button[type="submit"]').prop('disabled', false).text('Buy Add-ons');
  }

  function ajax(action, data){
    data = data || {};
    data.action = action;
    data.nonce = window.ringoAddonOrders.nonce;
    return $.ajax({url:window.ringoAddonOrders.ajaxUrl,method:'POST',dataType:'json',data:data,timeout:60000});
  }

  function renderCheckout($form, order){
    closeModal();
    var paypalArea = window.ringoAddonOrders.paypal ? '<div id="ringoAddonPaypal"></div>' : '<p class="ringo-addon-order-note">PayPal is not configured.</p>';
    var html = '<div id="ringoAddonOrderOverlay" class="ringo-addon-order-overlay">' +
      '<div class="ringo-addon-order-modal">' +
        '<button type="button" class="ringo-addon-order-close" aria-label="Close">&times;</button>' +
        '<h3>Complete Add-on Purchase</h3>' +
        '<p class="ringo-addon-order-total">Total: <strong>' + money(order.amount) + '</strong></p>' +
        '<div class="ringo-addon-order-section">' +
          '<h4>Pay by card</h4>' +
          '<div id="ringoAddonCardElement" class="ringo-addon-card-element"></div>' +
          '<div id="ringoAddonCardError" class="ringo-addon-order-error"></div>' +
          '<button type="button" id="ringoAddonCardPay">Pay ' + money(order.amount) + '</button>' +
        '</div>' +
        '<div class="ringo-addon-order-divider"><span>or</span></div>' +
        '<div class="ringo-addon-order-section"><h4>Pay with PayPal</h4>' + paypalArea + '</div>' +
      '</div>' +
    '</div>';
    $('body').append(html);
    $('.ringo-addon-order-close').on('click', closeModal);

    if (window.ringoAddonOrders.stripePk && window.Stripe) {
      var stripe = Stripe(window.ringoAddonOrders.stripePk);
      var elements = stripe.elements();
      var card = elements.create('card', {hidePostalCode:false});
      card.mount('#ringoAddonCardElement');
      card.on('change', function(event){ $('#ringoAddonCardError').text(event.error ? event.error.message : ''); });

      $('#ringoAddonCardPay').on('click', function(){
        var $button = $(this).prop('disabled', true).text('Starting payment...');
        ajax('ringo_addon_stripe_intent', {order_id:order.order_id}).done(function(response){
          if (!response || !response.success) {
            $('#ringoAddonCardError').text(response && response.data && response.data.message ? response.data.message : 'Stripe could not start the payment.');
            $button.prop('disabled', false).text('Pay ' + money(order.amount));
            return;
          }
          stripe.confirmCardPayment(response.data.client_secret, {payment_method:{card:card}}).then(function(result){
            if (result.error) {
              $('#ringoAddonCardError').text(result.error.message || 'Card payment failed.');
              $button.prop('disabled', false).text('Pay ' + money(order.amount));
              return;
            }
            ajax('ringo_addon_stripe_complete', {order_id:order.order_id,payment_intent_id:result.paymentIntent.id}).done(function(done){
              if (done && done.success) showSuccess($form, done.data.message, done.data.edit_url);
              else {
                $('#ringoAddonCardError').text(done && done.data && done.data.message ? done.data.message : 'Payment verification failed.');
                $button.prop('disabled', false).text('Pay ' + money(order.amount));
              }
            }).fail(function(){
              $('#ringoAddonCardError').text('Payment verification request failed.');
              $button.prop('disabled', false).text('Pay ' + money(order.amount));
            });
          });
        }).fail(function(xhr){
          $('#ringoAddonCardError').text(xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'Stripe request failed.');
          $button.prop('disabled', false).text('Pay ' + money(order.amount));
        });
      });
    } else {
      $('#ringoAddonCardElement').html('<p class="ringo-addon-order-note">Stripe is not configured.</p>');
      $('#ringoAddonCardPay').hide();
    }

    if (window.ringoAddonOrders.paypal && window.paypal) {
      paypal.Buttons({
        createOrder:function(){
          return ajax('ringo_addon_paypal_create', {order_id:order.order_id}).then(function(response){
            if (!response || !response.success) throw new Error(response && response.data && response.data.message ? response.data.message : 'PayPal could not create the order.');
            return response.data.paypal_order_id;
          });
        },
        onApprove:function(data){
          return ajax('ringo_addon_paypal_capture', {order_id:order.order_id,paypal_order_id:data.orderID}).then(function(response){
            if (!response || !response.success) throw new Error(response && response.data && response.data.message ? response.data.message : 'PayPal capture failed.');
            showSuccess($form, response.data.message, response.data.edit_url);
          });
        },
        onError:function(error){
          showError($form, error && error.message ? error.message : 'PayPal payment failed.');
        }
      }).render('#ringoAddonPaypal');
    }
  }

  $(document).on('change', '.ringo-existing-addon-shop [name="boat_id"]', function(){
    var $form = $(this).closest('form');
    var owned = String($(this).find('option:selected').attr('data-owned-addons') || '').split(',').filter(Boolean);
    $form.find('[data-ringo-addon-order-choice]').each(function(){
      var isOwned = owned.indexOf(String($(this).val())) !== -1;
      $(this).prop('checked', false).prop('disabled', isOwned);
      $(this).closest('.ringo-addon-service-card').toggleClass('is-owned', isOwned);
      var $tag = $(this).closest('.ringo-addon-service-card').find('.ringo-addon-owned-label');
      if (isOwned && !$tag.length) $(this).closest('.ringo-addon-service-card').append('<small class="ringo-addon-owned-label">Already active</small>');
      if (!isOwned) $tag.remove();
    });
    $form.find('[data-ringo-existing-total]').text('$0.00');
  });

  $(document).on('change', '.ringo-existing-addon-shop [data-ringo-addon-order-choice]', function(){
    var $form = $(this).closest('form');
    var total = 0;
    $form.find('[data-ringo-addon-order-choice]:checked').each(function(){ total += parseFloat($(this).attr('data-price') || 0) || 0; });
    $form.find('[data-ringo-existing-total]').text(money(total));
  });

  $(document).on('submit', '.ringo-existing-addon-shop', function(event){
    event.preventDefault();
    var $form = $(this);
    var boatId = $form.find('[name="boat_id"]').val();
    var ids = [];
    $form.find('[data-ringo-addon-order-choice]:checked').each(function(){ ids.push($(this).val()); });
    if (!boatId) { showError($form, 'Select a published boat.'); return; }
    if (!ids.length) { showError($form, 'Select at least one add-on.'); return; }
    var $button = $form.find('button[type="submit"]').prop('disabled', true).text('Preparing checkout...');
    $form.find('[data-ringo-addon-order-message]').hide();
    ajax('ringo_create_addon_order', {boat_id:boatId,addon_ids:ids.join(',')}).done(function(response){
      if (!response || !response.success) {
        showError($form, response && response.data && response.data.message ? response.data.message : 'The add-on order could not be created.');
        return;
      }
      $button.prop('disabled', false).text('Buy Add-ons');
      renderCheckout($form, response.data);
    }).fail(function(xhr){
      showError($form, xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : 'The add-on order request failed.');
    });
  });
});
JS;
}

/**
 * Render the existing-published-boat add-on shop.
 *
 * @param array<string,array<string,mixed>> $addons Active add-ons.
 * @return string
 */
function ringo_render_existing_boat_addon_shop( $addons, $compact = false ) {
	if ( ! is_user_logged_in() ) {
		$content = '<h3>Existing Published Boat</h3><p>Please sign in to select one of your published boats.</p>';
		return $compact ? '<div class="ringo-addon-mode-content">' . $content . '</div>' : '<div class="ringo-addon-existing-panel">' . $content . '</div>';
	}

	$args = [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => 'publish',
		'posts_per_page' => 100,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];
	if ( ! current_user_can( 'manage_options' ) ) {
		$args['author'] = get_current_user_id();
	}
	$boats = get_posts( $args );

	ringo_enqueue_addon_order_assets();
	ob_start();
	?>
	<div class="<?php echo $compact ? 'ringo-addon-mode-content' : 'ringo-addon-existing-panel'; ?>">
		<h3>Buy Add-ons for a Published Boat</h3>
		<p>Select a boat and pay only for the add-ons. The original listing package is not charged again.</p>
		<?php if ( ! $boats ) : ?>
			<p>You do not have a published boat available for add-on checkout.</p>
		<?php else : ?>
			<form class="ringo-existing-addon-shop">
				<label class="ringo-native-field">
					<span>Published Boat</span>
					<select name="boat_id" required>
						<option value="">Select a boat</option>
						<?php foreach ( $boats as $boat ) : ?>
							<?php $owned = implode( ',', ringo_get_boat_addon_ids( $boat->ID ) ); ?>
							<option value="<?php echo esc_attr( $boat->ID ); ?>" data-owned-addons="<?php echo esc_attr( $owned ); ?>"><?php echo esc_html( $boat->post_title . ' (#' . $boat->ID . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<div class="ringo-addon-services-grid">
					<?php foreach ( $addons as $addon ) : ?>
						<label class="ringo-addon-service-card">
							<input class="ringo-addon-service-check" type="checkbox" value="<?php echo esc_attr( $addon['id'] ); ?>" data-ringo-addon-order-choice data-price="<?php echo esc_attr( number_format( (float) $addon['price'], 2, '.', '' ) ); ?>">
							<span class="ringo-addon-service-select"><?php echo 'form' === $addon['addon_type'] ? 'Form add-on' : 'Service add-on'; ?></span>
							<h3><?php echo esc_html( $addon['name'] ); ?></h3>
							<div class="ringo-addon-service-price"><?php echo esc_html( '$' . number_format_i18n( (float) $addon['price'], 2 ) ); ?></div>
							<p><?php echo esc_html( $addon['description'] ); ?></p>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="ringo-addon-services-actions">
					<div><span>Add-on checkout total</span><strong data-ringo-existing-total>$0.00</strong></div>
					<button type="submit">Buy Add-ons</button>
				</div>
				<div class="ringo-addon-order-message" data-ringo-addon-order-message style="display:none"></div>
			</form>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'admin_menu', 'ringo_register_addon_orders_admin_page', 60 );

/**
 * Add the order list to the Ringo Checkout admin menu.
 *
 * @return void
 */
function ringo_register_addon_orders_admin_page() {
	add_submenu_page(
		'ringo-stripe-payments',
		'Add-on Orders',
		'Add-on Orders',
		'manage_options',
		'ringo-addon-orders',
		'ringo_render_addon_orders_admin_page'
	);
}

/**
 * Render a simple add-on order ledger.
 *
 * @return void
 */
function ringo_render_addon_orders_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$orders = get_posts(
		[
			'post_type'      => 'ringo_addon_order',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]
	);
	?>
	<div class="wrap">
		<h1>Add-on Orders</h1>
		<table class="widefat striped">
			<thead><tr><th>Order</th><th>Boat</th><th>Customer</th><th>Add-ons</th><th>Amount</th><th>Status</th><th>Provider</th><th>Date</th></tr></thead>
			<tbody>
			<?php if ( ! $orders ) : ?>
				<tr><td colspan="8">No add-on orders found.</td></tr>
			<?php else : foreach ( $orders as $order ) :
				$boat_id = absint( get_post_meta( $order->ID, '_ringo_addon_order_boat_id', true ) );
				$user    = get_user_by( 'id', (int) $order->post_author );
				$items   = get_post_meta( $order->ID, '_ringo_addon_order_items', true );
				$names   = [];
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( is_array( $item ) && ! empty( $item['name'] ) ) $names[] = (string) $item['name'];
					}
				}
				?>
				<tr>
					<td>#<?php echo esc_html( $order->ID ); ?></td>
					<td><a href="<?php echo esc_url( get_edit_post_link( $boat_id ) ); ?>">#<?php echo esc_html( $boat_id ); ?></a></td>
					<td><?php echo esc_html( $user ? $user->user_email : 'Unknown' ); ?></td>
					<td><?php echo esc_html( implode( ', ', $names ) ); ?></td>
					<td>$<?php echo esc_html( number_format( (float) get_post_meta( $order->ID, '_ringo_addon_order_amount', true ), 2 ) ); ?></td>
					<td><?php echo esc_html( ucfirst( (string) get_post_meta( $order->ID, '_ringo_addon_order_status', true ) ) ); ?></td>
					<td><?php echo esc_html( ucfirst( (string) get_post_meta( $order->ID, '_ringo_addon_order_provider', true ) ) ?: '-' ); ?></td>
					<td><?php echo esc_html( get_the_date( 'Y-m-d H:i', $order ) ); ?></td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}
