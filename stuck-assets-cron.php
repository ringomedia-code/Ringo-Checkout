<?php
/**
 * Safety net for native boat listings whose background photo upload never
 * finished - whether that's an unpaid draft abandoned mid-upload, or a boat
 * that got marked paid (and published immediately, per ringo_process_paid())
 * while photos were still uploading/failed. Flags such listings internally
 * so staff can follow up - no customer email is ever sent for this case.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'ringo_check_stuck_assets', 'ringo_recover_completed_background_assets', 5 );
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
 * Find listings where the background cover/gallery upload started but never
 * reached 'complete' - both unpaid drafts (abandoned mid-upload) and paid/
 * published boats (ringo_process_paid() no longer waits on assets, so these
 * can go live with photos still in flight) - and flag them so staff notice.
 * Unpaid drafts are marked 'failed' so they don't sit as an invisible draft
 * forever; paid/published boats keep their real status and are only logged,
 * since ringo_process_paid() already handled publishing and notifying.
 */
function ringo_check_stuck_assets_handler() {
	global $wpdb;
	$settings = ringo_get_settings();
	$cutoff   = time() - (int) $settings['stuck_assets_after'];

	$ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT p.ID FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->postmeta} started ON started.post_id = p.ID AND started.meta_key = '_ringo_native_assets_started_at'
		INNER JOIN {$wpdb->postmeta} status  ON status.post_id = p.ID AND status.meta_key = '_ringo_native_assets_status' AND status.meta_value IN ( 'uploading', 'failed' )
		WHERE CAST( started.meta_value AS UNSIGNED ) < %d
	", $cutoff ) );

	if ( ! $ids ) {
		return;
	}

	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;

		// Race: the recovery pass above (or the finalize AJAX call) completed
		// between the query above and here.
		if ( get_post_meta( $post_id, '_ringo_native_assets_status', true ) === 'complete' ) {
			continue;
		}

		// Already flagged on a previous run - don't re-log every 10 minutes.
		if ( get_post_meta( $post_id, '_ringo_stuck_assets_notified', true ) ) {
			continue;
		}

		$is_paid = 'paid' === get_post_meta( $post_id, '_ringo_checkout_status', true );

		ringo_log(
			$is_paid
				? 'Paid listing has incomplete photos - staff follow-up needed (no customer email sent)'
				: 'Native assets stuck - flagging (no email sent)',
			[ 'post_id' => $post_id ]
		);

		if ( ! $is_paid ) {
			update_post_meta( $post_id, '_ringo_native_assets_status', 'failed' );
		}
		delete_post_meta( $post_id, '_ringo_publish_pending_assets' );
		update_post_meta( $post_id, '_ringo_stuck_assets_notified', time() );
	}
}

/**
 * Recover a background photo upload that actually finished but never got to
 * flip its listing's status to 'complete' - e.g. the server successfully
 * processed every item in the per-image sequence, but the process died (host
 * timeout, object-cache hiccup) somewhere between accumulating the last item
 * and running the merge/finalise step, so the files exist but nothing ever
 * says so. Without this, such a listing is indistinguishable from one that is
 * genuinely still missing photos, and just sits until the stuck-assets check
 * below flags it as failed - which here means silently, with no email.
 *
 * Runs on every tick of the same 10-ish-minute schedule as the stuck-assets
 * check (priority 5, so it always runs first): cheap, and safe to repeat.
 *
 * @return void
 */
function ringo_recover_completed_background_assets() {
	global $wpdb;

	$ids = $wpdb->get_col( "
		SELECT post_id FROM {$wpdb->postmeta}
		WHERE meta_key = '_ringo_native_assets_status' AND meta_value IN ( 'uploading', 'failed' )
	" );

	if ( ! $ids ) {
		return;
	}

	foreach ( $ids as $post_id ) {
		$post_id = (int) $post_id;

		$expected_total = (int) get_post_meta( $post_id, '_ringo_native_assets_expected_total', true );
		if ( $expected_total < 1 ) {
			continue; // Nothing recorded to compare against - leave to the stuck-assets check below.
		}

		$partial = get_post_meta( $post_id, '_ringo_native_assets_partial', true );
		$partial = is_array( $partial ) ? $partial : [];
		if ( count( $partial ) < $expected_total ) {
			continue; // Genuinely still missing photos, not a stalled-finalize case.
		}

		// _ringo_native_edit_kept_gallery only ever exists for the edit/re-upload
		// path (ringo_ajax_native_update_boat's fast phase) - its presence is
		// what tells the two otherwise-identical-looking sequences apart, since
		// the new-listing path has no such "kept" baseline to merge onto.
		$kept_gallery = get_post_meta( $post_id, '_ringo_native_edit_kept_gallery', true );
		if ( is_array( $kept_gallery ) ) {
			ringo_native_recover_finish_edit_assets( $post_id, $partial, $kept_gallery );
		} else {
			ringo_native_recover_finish_new_assets( $post_id, $partial );
		}
	}
}

/**
 * Finish a stalled new-listing (1204) background upload whose files are all
 * present, mirroring the "last item" merge in ringo_ajax_native_finalize_boat_assets()
 * - duplicated rather than shared, so a future change to the live per-request
 * path can't silently change recovery behaviour (or vice versa) without both
 * being touched deliberately.
 *
 * @param int   $post_id
 * @param array $partial Accumulated items: [ [ 'id' => int, 'role' => string ], ... ].
 * @return void
 */
function ringo_native_recover_finish_new_assets( $post_id, $partial ) {
	$cover_id = 0;
	foreach ( $partial as $item ) {
		if ( 'cover' === $item['role'] ) {
			$cover_id = (int) $item['id'];
			break;
		}
	}
	$gallery_ids = array_values( array_map(
		function ( $item ) { return (int) $item['id']; },
		array_filter( $partial, function ( $item ) { return 'gallery' === $item['role']; } )
	) );
	$gallery_ids = array_values( array_unique( array_filter( array_merge( [ $cover_id ], $gallery_ids ) ) ) );
	$attachments = array_values( array_unique( array_map(
		function ( $item ) { return (int) $item['id']; },
		$partial
	) ) );

	update_post_meta( $post_id, 'gallery', implode( ',', $gallery_ids ) );
	update_post_meta( $post_id, '_ringo_uploaded_file_ids', $attachments );
	update_post_meta( $post_id, '_ringo_native_assets_status', 'complete' );
	update_post_meta( $post_id, '_ringo_native_assets_completed_at', time() );
	delete_post_meta( $post_id, '_ringo_native_assets_error' );
	delete_post_meta( $post_id, '_ringo_native_assets_partial' );
	delete_post_meta( $post_id, '_ringo_native_assets_expected_total' );
	delete_post_meta( $post_id, '_ringo_stuck_assets_notified' );

	ringo_log( 'Recovered stalled background upload - files were all present, finalize had not run', [ 'post_id' => $post_id, 'images' => count( $attachments ) ] );

	if ( get_post_meta( $post_id, '_ringo_publish_pending_assets', true ) ) {
		ringo_finalize_paid_boat_after_assets( $post_id );
	}
}

/**
 * Finish a stalled edit/re-upload (37231) background upload whose files are
 * all present, mirroring the "last item" merge in
 * ringo_ajax_native_finalize_edit_assets() - merges onto the kept-gallery
 * snapshot rather than overwriting, same as the live path.
 *
 * @param int   $post_id
 * @param array $partial      Accumulated items: [ [ 'id' => int, 'role' => string ], ... ].
 * @param array $kept_gallery Gallery attachment IDs the seller chose to keep.
 * @return void
 */
function ringo_native_recover_finish_edit_assets( $post_id, $partial, $kept_gallery ) {
	$kept_gallery = array_map( 'absint', $kept_gallery );

	$new_cover_id = 0;
	foreach ( $partial as $item ) {
		if ( 'cover' === $item['role'] ) {
			$new_cover_id = (int) $item['id'];
			break;
		}
	}
	$new_gallery_ids = array_values( array_map(
		function ( $item ) { return (int) $item['id']; },
		array_filter( $partial, function ( $item ) { return 'gallery' === $item['role']; } )
	) );
	$final_gallery = array_values( array_unique( array_filter( array_merge(
		$new_cover_id ? [ $new_cover_id ] : [],
		$kept_gallery,
		$new_gallery_ids
	) ) ) );
	update_post_meta( $post_id, 'gallery', implode( ',', $final_gallery ) );
	if ( $new_cover_id ) {
		set_post_thumbnail( $post_id, $new_cover_id );
	}

	$tracked_uploads = ringo_native_gallery_ids_from_value( get_post_meta( $post_id, '_ringo_uploaded_file_ids', true ) );
	$tracked_uploads = array_values( array_unique( array_merge(
		$tracked_uploads,
		array_map( function ( $item ) { return (int) $item['id']; }, $partial )
	) ) );
	update_post_meta( $post_id, '_ringo_uploaded_file_ids', $tracked_uploads );
	update_post_meta( $post_id, '_ringo_native_assets_status', 'complete' );
	update_post_meta( $post_id, '_ringo_native_assets_completed_at', time() );
	delete_post_meta( $post_id, '_ringo_native_assets_error' );
	delete_post_meta( $post_id, '_ringo_native_assets_partial' );
	delete_post_meta( $post_id, '_ringo_native_assets_expected_total' );
	delete_post_meta( $post_id, '_ringo_native_edit_kept_gallery' );
	delete_post_meta( $post_id, '_ringo_stuck_assets_notified' );

	ringo_log( 'Recovered stalled background upload (edit) - files were all present, finalize had not run', [ 'post_id' => $post_id, 'images' => count( $tracked_uploads ) ] );

	if ( get_post_meta( $post_id, '_ringo_publish_pending_assets', true ) ) {
		ringo_finalize_paid_boat_after_assets( $post_id );
	}
}
