<?php
/**
 * AJAX handlers for new checkout features.
 *
 * Feature #1: Mark post as unpaid on form submit
 * Feature #2: Mark payment as failed when card declined
 * Feature #3: Process uploaded images in background
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Feature #1: Mark Post as Unpaid ────────────────────────────────────────

/**
 * AJAX handler: Mark boat post as unpaid on form submission
 * Called immediately when form is submitted, before payment modal appears
 */
function ringo_ajax_mark_post_unpaid() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_mark_unpaid_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$boat_post_id = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] ) : 0;
	$package_name = isset( $_POST['package_name'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['package_name'] ) ) : '';
	$package_price    = isset( $_POST['package_price'] ) ? (float) wp_unslash( $_POST['package_price'] ) : 0;
	$base_price_client = isset( $_POST['base_package_price'] ) ? (float) wp_unslash( $_POST['base_package_price'] ) : 0;
	$addon_ids         = ringo_get_requested_addon_ids( $_POST['addon_ids'] ?? [] );

	if ( ! $boat_post_id || ! $package_name ) {
		wp_send_json_error( [ 'message' => 'Missing required fields' ] );
	}

	$post = get_post( $boat_post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat post not found' ] );
	}
	// SECURITY: the nonce is printed on a public page, so it proves nothing about the
	// caller. Only the listing's owner (or an admin) may touch it, and never once paid.
	if ( ! ringo_current_user_owns_boat( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to update this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}

	$fallback_base = $base_price_client > 0 ? $base_price_client : $package_price;
	$totals        = ringo_get_checkout_totals( $package_name, $addon_ids, $fallback_base );
	if ( ( $totals['base'] <= 0 && ! ringo_checkout_base_waived( $package_name, $boat_post_id ) ) || $totals['subtotal'] <= 0 ) {
		wp_send_json_error( [ 'message' => 'Invalid checkout total' ] );
	}
	$package_price = (float) $totals['subtotal'];
	ringo_save_boat_addons( $boat_post_id, $totals['addon_ids'], (float) $totals['base'] );

	// Set initial payment meta with "unpaid" status
	ringo_set_payment_meta(
		$boat_post_id,
		'pending', // provider will be set when they choose payment method
		'unpaid',  // ← Feature #1: Mark as unpaid immediately
		$package_name,
		$package_price,
		'', // provider_id not set yet
		'',
		''
	);

	// Start a fresh, independently tracked payment attempt.
	$attempt_id = ringo_begin_payment_attempt( $boat_post_id, 'draft_created' );
	$now        = time();
	update_post_meta( $boat_post_id, '_ringo_unpaid_time', $now );
	update_post_meta( $boat_post_id, '_ringo_checkout_created_at', $now );

	ringo_log( 'Payment status marked as unpaid', [
		'post_id'    => $boat_post_id,
		'package'    => $package_name,
		'amount'        => $package_price,
		'base_amount'   => (float) $totals['base'],
		'addons_total'  => (float) $totals['addons_total'],
		'addon_ids'     => $totals['addon_ids'],
		'attempt_id'    => $attempt_id,
	] );

	wp_send_json_success( [
		'post_id' => $boat_post_id,
		'status'  => 'unpaid',
	] );
}

add_action( 'wp_ajax_ringo_mark_post_unpaid', 'ringo_ajax_mark_post_unpaid' );
add_action( 'wp_ajax_nopriv_ringo_mark_post_unpaid', 'ringo_ajax_mark_post_unpaid' );

// ─── Feature #2: Mark Payment as Failed ──────────────────────────────────────

/**
 * AJAX handler: Mark payment as failed when card is declined
 * Triggers abandoned/failed payment email
 */
function ringo_ajax_mark_payment_failed() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_stripe_intent_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$boat_post_id = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] ) : 0;
	$payment_intent_id = isset( $_POST['payment_intent_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['payment_intent_id'] ) ) : '';
	$error_message = isset( $_POST['error_message'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['error_message'] ) ) : '';
	$condition     = isset( $_POST['condition'] ) ? sanitize_key( (string) wp_unslash( $_POST['condition'] ) ) : 'card_rejected';

	if ( ! $boat_post_id || ! $payment_intent_id ) {
		wp_send_json_error( [ 'message' => 'Missing required fields' ] );
	}

	$post = get_post( $boat_post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat post not found' ] );
	}
	// SECURITY: the nonce is printed on a public page, so it proves nothing about the
	// caller. Only the listing's owner (or an admin) may touch it, and never once paid.
	if ( ! ringo_current_user_owns_boat( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to update this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}

	// Update status to payment_failed
	// The failure report must refer to this boat's own PaymentIntent.
	$stored_pi = (string) get_post_meta( $boat_post_id, '_ringo_stripe_payment_intent', true );
	if ( $stored_pi && $stored_pi !== $payment_intent_id ) {
		wp_send_json_error( [ 'message' => 'Payment does not belong to this listing.' ], 403 );
	}

	update_post_meta( $boat_post_id, '_ringo_checkout_status', 'payment_failed' );
	update_post_meta( $boat_post_id, '_ringo_payment_error', $error_message );
	update_post_meta( $boat_post_id, '_ringo_payment_failed_time', current_time( 'timestamp' ) );
	update_post_meta( $boat_post_id, '_ringo_payment_intent_id', $payment_intent_id );

	// Send the dedicated draft-failure notification. This is independent from
	// the boat follow-up email sequence.
	ringo_record_draft_failure(
		$boat_post_id,
		$condition ?: 'card_rejected',
		$error_message,
		[
			'source'            => 'ajax_mark_payment_failed',
			'payment_intent_id' => $payment_intent_id,
		]
	);

	ringo_log( 'Payment marked as failed', [
		'post_id'    => $boat_post_id,
		'pi_id'      => $payment_intent_id,
		'error'      => $error_message,
		'condition'  => $condition ?: 'card_rejected',
	] );

	wp_send_json_success( [
		'post_id' => $boat_post_id,
		'status'  => 'payment_failed',
	] );
}

add_action( 'wp_ajax_ringo_mark_payment_failed', 'ringo_ajax_mark_payment_failed' );
add_action( 'wp_ajax_nopriv_ringo_mark_payment_failed', 'ringo_ajax_mark_payment_failed' );

// ─── Feature #3: Process Uploaded Images ────────────────────────────────────

/**
 * AJAX handler: Process uploaded file IDs and store in post meta
 * Called in background while payment modal is displayed
 */
function ringo_ajax_process_uploaded_images() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_mark_unpaid_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$boat_post_id = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] ) : 0;
	$file_ids_str = isset( $_POST['file_ids'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['file_ids'] ) ) : '';

	if ( ! $boat_post_id || ! $file_ids_str ) {
		wp_send_json_error( [ 'message' => 'Missing required fields' ] );
	}

	$post = get_post( $boat_post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat post not found' ] );
	}
	// SECURITY: the nonce is printed on a public page, so it proves nothing about the
	// caller. Only the listing's owner (or an admin) may touch it, and never once paid.
	if ( ! ringo_current_user_owns_boat( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to update this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $boat_post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}

	// Parse file IDs
	// Only accept real image attachments that belong to this boat or to the caller.
	$file_ids = array_values( array_filter( array_map( 'intval', explode( ',', $file_ids_str ) ), static function ( $fid ) use ( $boat_post_id ) {
		$att = get_post( $fid );
		return $att && 'attachment' === $att->post_type
			&& ( (int) $att->post_parent === (int) $boat_post_id || (int) $att->post_author === get_current_user_id() || current_user_can( 'administrator' ) );
	} ) );

	if ( ! empty( $file_ids ) ) {
		// Store uploaded file IDs
		update_post_meta( $boat_post_id, '_ringo_uploaded_file_ids', $file_ids );

		ringo_log( 'Uploaded file IDs stored', [
			'post_id' => $boat_post_id,
			'file_ids' => $file_ids,
		] );
	}

	wp_send_json_success( [
		'post_id' => $boat_post_id,
		'file_count' => count( $file_ids ),
	] );
}

add_action( 'wp_ajax_ringo_process_uploaded_images', 'ringo_ajax_process_uploaded_images' );
add_action( 'wp_ajax_nopriv_ringo_process_uploaded_images', 'ringo_ajax_process_uploaded_images' );

// ─── Payment activity + generic failure reporting ───────────────────────────

/**
 * AJAX: update the current payment stage for watchdog/cron diagnosis.
 */
function ringo_ajax_payment_activity() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_payment_failure_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$post_id  = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] ) : 0;
	$state    = isset( $_POST['state'] ) ? sanitize_key( (string) wp_unslash( $_POST['state'] ) ) : '';
	$provider = isset( $_POST['provider'] ) ? sanitize_key( (string) wp_unslash( $_POST['provider'] ) ) : '';

	$allowed_states = [
		'draft_created',
		'chooser_ready',
		'stripe_modal_open',
		'stripe_intent_request',
		'stripe_confirming',
		'paypal_modal_open',
		'paypal_order_request',
		'paypal_approved',
		'paypal_capturing',
		'payment_complete',
	];

	if ( ! $post_id || ! in_array( $state, $allowed_states, true ) ) {
		wp_send_json_error( [ 'message' => 'Invalid activity update' ] );
	}

	// SECURITY: owner only, and not for boats that are already paid.
	if ( ! ringo_current_user_owns_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to update this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}

	if ( ! ringo_update_payment_activity( $post_id, $state, $provider, [ 'source' => 'browser_activity' ] ) ) {
		wp_send_json_error( [ 'message' => 'Boat post not found' ] );
	}

	wp_send_json_success( [ 'post_id' => $post_id, 'state' => $state ] );
}

add_action( 'wp_ajax_ringo_payment_activity', 'ringo_ajax_payment_activity' );
add_action( 'wp_ajax_nopriv_ringo_payment_activity', 'ringo_ajax_payment_activity' );

/**
 * AJAX: record any browser-detected failure before payment completes.
 */
function ringo_ajax_report_payment_failure() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_payment_failure_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed' ] );
	}

	$post_id   = isset( $_POST['boat_post_id'] ) ? (int) wp_unslash( $_POST['boat_post_id'] ) : 0;
	$condition = isset( $_POST['condition'] ) ? sanitize_key( (string) wp_unslash( $_POST['condition'] ) ) : '';
	$message   = isset( $_POST['message'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['message'] ) ) : '';
	$provider  = isset( $_POST['provider'] ) ? sanitize_key( (string) wp_unslash( $_POST['provider'] ) ) : '';
	$stage     = isset( $_POST['stage'] ) ? sanitize_key( (string) wp_unslash( $_POST['stage'] ) ) : '';

	$allowed_conditions = [
		'card_rejected',
		'stripe_intent_error',
		'stripe_confirmation_error',
		'stripe_payment_incomplete',
		'paypal_no_response',
		'paypal_order_error',
		'paypal_capture_error',
		'paypal_sdk_error',
		'payment_snippet_stuck',
		'payment_pending',
		'gateway_timeout',
		'gateway_unavailable',
		'checkout_abandoned',
		'payment_cancelled',
		'payment_setup_error',
		'payment_incomplete',
	];

	if ( ! $post_id || ! in_array( $condition, $allowed_conditions, true ) ) {
		wp_send_json_error( [ 'message' => 'Invalid failure report' ] );
	}

	// SECURITY: owner only, and not for boats that are already paid.
	if ( ! ringo_current_user_owns_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to update this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}

	if ( $provider && in_array( $provider, [ 'stripe', 'paypal', 'coupon' ], true ) ) {
		update_post_meta( $post_id, '_ringo_payment_provider', $provider );
	}

	$recorded = ringo_record_draft_failure(
		$post_id,
		$condition,
		$message,
		[
			'source'   => 'browser_failure_report',
			'provider' => $provider,
			'stage'    => $stage,
		]
	);

	if ( ! $recorded ) {
		wp_send_json_error( [ 'message' => 'Failure could not be recorded' ] );
	}

	wp_send_json_success( [
		'post_id'   => $post_id,
		'condition' => $condition,
	] );
}

add_action( 'wp_ajax_ringo_report_payment_failure', 'ringo_ajax_report_payment_failure' );
add_action( 'wp_ajax_nopriv_ringo_report_payment_failure', 'ringo_ajax_report_payment_failure' );
