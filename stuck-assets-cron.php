<?php
/**
 * Safety net for native boat listings whose background photo upload never
 * finished after payment (e.g. the thank-you redirect fired before the
 * background cover/gallery upload request completed, and the browser
 * navigation cancelled it). Flags such listings so they don't sit as an
 * invisible draft forever - no customer/admin email is sent for this case.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'ringo_check_stuck_assets', 'ringo_check_stuck_assets_handler' );
add_action( 'wp_loaded', 'ringo_schedule_stuck_assets_check' );

/**
 * Schedule the stuck-assets check cron job.
 */
function ringo_schedule_stuck_assets_check() {
	if ( ! wp_next_scheduled( 'ringo_check_stuck_assets' ) ) {
		wp_schedule_event( time() + 60, 'ringo_10min', 'ringo_check_stuck_assets' );
		ringo_log( 'Stuck native assets cron scheduled' );
	}
}

/**
 * Find paid listings where the background cover/gallery upload started but
 * never reached 'complete', and flag them as failed so the listing does not
 * sit as an invisible draft forever.
 */
function ringo_check_stuck_assets_handler() {
	global $wpdb;
	$settings = ringo_get_settings();
	$cutoff   = time() - (int) $settings['stuck_assets_after'];

	$ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT p.ID FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} pending ON pending.post_id = p.ID AND pending.meta_key = '_ringo_publish_pending_assets'
		INNER JOIN {$wpdb->postmeta} started ON started.post_id = p.ID AND started.meta_key = '_ringo_native_assets_started_at'
		WHERE p.post_status = 'draft'
		AND CAST( started.meta_value AS UNSIGNED ) < %d
	", $cutoff ) );

	if ( ! $ids ) {
		return;
	}

	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;

		// Race: the finalize AJAX call completed between the query above and here.
		if ( get_post_meta( $post_id, '_ringo_native_assets_status', true ) === 'complete' ) {
			continue;
		}

		// Already flagged on a previous run - don't re-email every 10 minutes.
		if ( get_post_meta( $post_id, '_ringo_stuck_assets_notified', true ) ) {
			continue;
		}

		ringo_log( 'Native assets stuck - flagging (no email sent)', [ 'post_id' => $post_id ] );
		update_post_meta( $post_id, '_ringo_native_assets_status', 'failed' );
		delete_post_meta( $post_id, '_ringo_publish_pending_assets' );
		update_post_meta( $post_id, '_ringo_stuck_assets_notified', time() );
	}
}
