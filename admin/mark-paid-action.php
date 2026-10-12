<?php
/**
 * Admin "mark as paid" action for payment rows.
 *
 * Handles the `?ringo_action=mark_paid_row` request that comes from the
 * Payments tracker table, for boats paid outside Stripe/PayPal (e.g. bank
 * transfer, cash, comped listing) that still show as unpaid.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'ringo_handle_mark_paid_row' );

/**
 * Process a mark-as-paid request and redirect back to the payments page.
 */
function ringo_handle_mark_paid_row() {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( empty( $_GET['ringo_action'] ) || $_GET['ringo_action'] !== 'mark_paid_row' ) {
		return;
	}

	$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
	if ( ! $post_id ) {
		return;
	}

	if (
		empty( $_GET['_wpnonce'] ) ||
		! wp_verify_nonce( $_GET['_wpnonce'], 'ringo_mark_paid_' . $post_id )
	) {
		wp_die( 'Security check failed.' );
	}

	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_die( 'Invalid post.' );
	}

	if ( get_post_meta( $post_id, '_ringo_checkout_status', true ) !== 'paid' ) {
		$package = (string) get_post_meta( $post_id, '_ringo_package', true );
		$amount  = (float) get_post_meta( $post_id, '_ringo_amount', true );

		$success = ringo_process_paid(
			$post_id,
			'admin',
			'manual-mark-paid-' . get_current_user_id(),
			$package,
			$amount
		);

		if ( $success ) {
			ringo_log( 'Admin manually marked boat as paid', [ 'post_id' => $post_id, 'user' => get_current_user_id() ] );
		} else {
			ringo_log( 'Admin mark-as-paid failed', [ 'post_id' => $post_id, 'user' => get_current_user_id() ] );
		}
	}

	wp_safe_redirect(
		add_query_arg(
			'marked_paid',
			$post_id,
			admin_url( 'admin.php?page=ringo-stripe-payments' )
		)
	);
	exit;
}
