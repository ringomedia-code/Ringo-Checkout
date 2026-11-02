<?php
/**
 * Safety net for native boat listings whose background photo upload never
 * finished after payment (e.g. the thank-you redirect fired before the
 * background cover/gallery upload request completed, and the browser
 * navigation cancelled it). Without this, such a listing sits as an
 * invisible draft forever with no error anywhere.
 *
 * This is intentionally separate from ringo_record_draft_failure(): that
 * channel explicitly ignores boats whose checkout status is already 'paid'
 * (see emails/draft-failure.php), since it exists for payment failures. Here
 * payment succeeded - only the asset upload stalled - so it needs its own
 * notification path.
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
 * never reached 'complete', flag them as failed, and notify seller + admin
 * so the listing does not sit as an invisible draft forever.
 */
function ringo_check_stuck_assets_handler() {
	global $wpdb;
	$cutoff = time() - 10 * MINUTE_IN_SECONDS;

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

		ringo_log( 'Native assets stuck - flagging for follow-up', [ 'post_id' => $post_id ] );
		update_post_meta( $post_id, '_ringo_native_assets_status', 'failed' );
		delete_post_meta( $post_id, '_ringo_publish_pending_assets' );
		update_post_meta( $post_id, '_ringo_stuck_assets_notified', time() );

		ringo_send_stuck_assets_customer_email( $post_id );
		ringo_send_stuck_assets_admin_email( $post_id );
	}
}

/**
 * Tell the seller their payment went through but their photos need re-uploading.
 *
 * @param int $post_id
 * @return bool
 */
function ringo_send_stuck_assets_customer_email( $post_id ) {
	$to = ringo_get_form_email( $post_id );
	if ( ! $to ) {
		ringo_log( 'Stuck assets customer email: no email found', [ 'post_id' => $post_id ] );
		return false;
	}

	$title     = esc_html( get_the_title( $post_id ) );
	$draft_url = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$subject   = 'Action needed: please re-upload your boat listing photos';

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Your payment was received, but your photos did not finish uploading</h2>
    <p>Hi,</p>
    <p>Your payment for "<?php echo $title; ?>" was successful, but the listing photos did not finish uploading
      (this can happen on a slow connection or if the browser tab was closed too soon).</p>

    <div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:5px;padding:15px;margin:15px 0;">
      <p style="margin:0;"><strong>ACTION REQUIRED:</strong></p>
      <p style="margin:5px 0 0 0;">Please go back and re-upload your photos to publish the listing. You will not be charged again.</p>
    </div>

    <p style="margin-top:20px;">
      <a href="<?php echo $draft_url; ?>"
         style="display:inline-block;padding:12px 20px;background:#d32f2f;color:#fff;text-decoration:none;border-radius:3px;font-weight:bold;">
        Re-upload Photos Now
      </a>
    </p>

    <p>If you have any questions or need assistance:</p>
    <p>
      <a href="https://bassboat4sale.com/contact/"
         style="display:inline-block;padding:10px 18px;background:#555;color:#fff;text-decoration:none;border-radius:3px;">
        Contact Support
      </a>
    </p>
    <div style="margin-top:20px;font-size:12px;color:#888;border-top:1px solid #eee;padding-top:15px;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	return ringo_send_mail(
		'Stuck native assets customer email',
		$to,
		$subject,
		$html,
		[ 'Reply-To: ammar@ringomedia.com' ],
		$post_id
	);
}

/**
 * Alert the admin that a paid listing is stuck without photos.
 *
 * @param int $post_id
 * @return bool
 */
function ringo_send_stuck_assets_admin_email( $post_id ) {
	$to         = get_option( 'admin_email' );
	$title      = sanitize_text_field( get_the_title( $post_id ) );
	$user_email = ringo_get_form_email( $post_id );
	$draft_url  = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$subject    = 'Paid listing stuck without photos - ' . $title;

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Paid listing stuck - photos never finished uploading</h2>
    <p>A listing was paid for, but the background photo upload never completed. The seller has been emailed to re-upload.</p>

    <table style="width:100%;border-collapse:collapse;margin:10px 0;">
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Boat Title</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $title ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Post ID</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $post_id ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>User Email</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo esc_html( $user_email ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Draft Edit URL</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><a href="<?php echo $draft_url; ?>"><?php echo esc_html( $draft_url ); ?></a></td>
      </tr>
    </table>

    <p style="margin-top:15px;font-size:12px;color:#888;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </p>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	return ringo_send_mail( 'Stuck native assets admin email', $to, $subject, $html, [], $post_id );
}
