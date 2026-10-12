<?php
/**
 * Admin delete / trash action for payment rows.
 *
 * Handles the `?ringo_action=delete_payment_row` request that comes from the
 * Payments tracker table.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', 'ringo_handle_delete_payment_row' );

/**
 * Process a delete-row request and redirect back to the payments page.
 */
function ringo_handle_delete_payment_row() {
	if ( ! is_admin() ) {
		return;
	}
	if ( ! current_user_can( 'delete_posts' ) ) {
		return;
	}

	if ( empty( $_GET['ringo_action'] ) || $_GET['ringo_action'] !== 'delete_payment_row' ) {
		return;
	}

	$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
	if ( ! $post_id ) {
		return;
	}

	if (
		empty( $_GET['_wpnonce'] ) ||
		! wp_verify_nonce( $_GET['_wpnonce'], 'ringo_delete_payment_' . $post_id )
	) {
		wp_die( 'Security check failed.' );
	}

	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_die( 'Invalid post.' );
	}

	$force = ! empty( $_GET['force'] );

	if ( $force ) {
		wp_delete_post( $post_id, true );
	} else {
		wp_trash_post( $post_id );
	}

	wp_safe_redirect(
		add_query_arg(
			'deleted',
			$post_id,
			admin_url( 'admin.php?page=ringo-stripe-payments' )
		)
	);
	exit;
}
