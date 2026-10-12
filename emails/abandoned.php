<?php
/**
 * Abandoned-checkout follow-up emails.
 *
 * Sent when a user started but did not complete payment within the configured
 * time window.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── SMTP error capture ───────────────────────────────────────────────────────

/**
 * Hook into wp_mail_failed to log SMTP errors with full detail.
 */
add_action( 'wp_mail_failed', 'ringo_capture_mail_error' );

function ringo_capture_mail_error( $wp_error ) {
	if ( ! is_wp_error( $wp_error ) ) {
		return;
	}
	ringo_log( 'wp_mail FAILED', [
		'code'    => $wp_error->get_error_code(),
		'message' => $wp_error->get_error_message(),
		'data'    => $wp_error->get_error_data(),
	] );
}

// ─── Shared mail helper ───────────────────────────────────────────────────────

/**
 * Send an HTML email and log the result.
 *
 * @param  string $label          Human-readable name for logging.
 * @param  string $to             Recipient address.
 * @param  string $subject        Email subject.
 * @param  string $html           Full HTML body.
 * @param  array  $extra_headers  Additional headers (Reply-To, etc).
 * @param  int    $post_id        Associated post ID for log context.
 * @return bool
 */
function ringo_send_mail( $label, $to, $subject, $html, $extra_headers = [], $post_id = 0 ) {
	$headers = array_merge(
		[ 'Content-Type: text/html; charset=UTF-8' ],
		$extra_headers
	);

	$sent = wp_mail( $to, $subject, $html, $headers );

	ringo_log( $label . ( $sent ? ': sent OK' : ': FAILED' ), [
		'to'      => $to,
		'subject' => $subject,
		'post_id' => $post_id,
	] );

	return (bool) $sent;
}

// ─── Customer abandoned email ─────────────────────────────────────────────────

/**
 * Remind the customer to complete payment for their draft listing.
 *
 * @param  int  $post_id
 * @return bool
 */
function ringo_send_payment_pending_customer_email( $post_id ) {
	$to = ringo_get_form_email( $post_id );
	if ( ! $to ) {
		ringo_log( 'Customer abandoned email: no email address found, skipping', $post_id );
		return false;
	}

	if ( get_post_meta( $post_id, '_ringo_pending_email_sent', true ) ) {
		ringo_log( 'Customer abandoned email: already sent, skipping', $post_id );
		return true;
	}

	$subject   = 'Action needed: complete payment to publish your boat listing';
	$title     = esc_html( get_the_title( $post_id ) );
	$draft_url = esc_url( ringo_get_draft_edit_url( $post_id ) );

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#0a6ebd;">Your boat listing is still in draft</h2>
    <p>Hi,</p>
    <p>We noticed your payment was not completed, so your boat listing is still in draft and not visible on the site.</p>
    <p><strong>Listing:</strong> <?php echo $title; ?></p>
    <p>Please complete payment to publish your listing.</p>
    <p>
      <a href="<?php echo $draft_url; ?>"
         style="display:inline-block;padding:10px 18px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:3px;">
        Complete payment
      </a>
    </p>
    <p>Having trouble or need help?</p>
    <p>
      <a href="https://bassboat4sale.com/contact/"
         style="display:inline-block;padding:10px 18px;background:#555;color:#fff;text-decoration:none;border-radius:3px;">
        Contact Us
      </a>
    </p>
    <p>If you prefer, you can also reply directly to this email and we'll be happy to assist.</p>
    <div style="margin-top:20px;font-size:12px;color:#888;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	$sent = ringo_send_mail(
		'Customer abandoned email',
		$to,
		$subject,
		$html,
		[ 'Reply-To: ammar@ringomedia.com' ],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_pending_email_sent', 1 );
	}

	return $sent;
}

// ─── Admin abandoned email ────────────────────────────────────────────────────

/**
 * Notify the admin that a user abandoned checkout.
 *
 * @param  int  $post_id
 * @return bool
 */
function ringo_send_payment_pending_admin_email( $post_id ) {
	$to = get_option( 'admin_email' );

	if ( get_post_meta( $post_id, '_ringo_pending_admin_email_sent', true ) ) {
		ringo_log( 'Admin abandoned email: already sent, skipping', $post_id );
		return true;
	}

	$subject    = 'Payment not completed – boat listing still in draft';
	$title      = sanitize_text_field( get_the_title( $post_id ) );
	$user_email = ringo_get_form_email( $post_id );
	$provider   = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );
	$draft_url  = esc_url( ringo_get_draft_edit_url( $post_id ) );

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#0a6ebd;">Boat listing still in draft</h2>
    <p>A user started checkout but did not complete payment.</p>
    <table style="width:100%;border-collapse:collapse;margin-top:10px;">
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Boat Title</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $title ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Post ID</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $post_id ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>User Email</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $user_email ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Provider</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $provider ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Draft edit URL</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><a href="<?php echo $draft_url; ?>"><?php echo esc_html( $draft_url ); ?></a></td>
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

	$sent = ringo_send_mail(
		'Admin abandoned email',
		$to,
		$subject,
		$html,
		[],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_pending_admin_email_sent', 1 );
	}

	return $sent;
}

// ─── Admin new draft email ────────────────────────────────────────────────────

/**
 * Notify the admin that a new boat listing has been added to draft.
 * Fires at the 6-minute cron window alongside the customer email.
 *
 * @param  int  $post_id
 * @return bool
 */
function ringo_send_admin_new_draft_email( $post_id ) {
	$to = get_option( 'admin_email' );

	if ( get_post_meta( $post_id, '_ringo_new_draft_admin_email_sent', true ) ) {
		ringo_log( 'Admin new-draft email: already sent, skipping', $post_id );
		return true;
	}

	$title      = sanitize_text_field( get_the_title( $post_id ) );
	$user_email = ringo_get_form_email( $post_id );
	$provider   = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );
	$package    = sanitize_text_field( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$amount     = (float) get_post_meta( $post_id, '_ringo_amount', true );
	$draft_url  = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$subject    = 'New Boat Added to Draft – Payment Pending';

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#0a6ebd;">New Boat Listing – Draft Created</h2>
    <p>A user has started the checkout process. The listing is in draft and awaiting payment.</p>
    <table style="width:100%;border-collapse:collapse;margin-top:10px;">
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Boat Title</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $title ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Post ID</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $post_id ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>User Email</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $user_email ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Package</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $package ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Amount</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo $amount > 0 ? '$' . number_format( $amount, 2 ) : '—'; ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Payment Provider</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $provider ? ucfirst( $provider ) : '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Draft Edit URL</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><a href="<?php echo $draft_url; ?>"><?php echo esc_html( $draft_url ); ?></a></td>
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

	$sent = ringo_send_mail(
		'Admin new-draft email',
		$to,
		$subject,
		$html,
		[],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_new_draft_admin_email_sent', 1 );
	}

	return $sent;
}

// ─── Payment Failed Customer Email ────────────────────────────────────────────

/**
 * Send email to customer when their payment fails.
 * Triggered immediately when card is declined or other payment errors occur.
 *
 * @param  int    $post_id
 * @param  string $error_message The specific payment error
 * @return bool
 */
function ringo_send_payment_failed_customer_email( $post_id, $error_message = '' ) {
	$to = ringo_get_form_email( $post_id );
	if ( ! $to ) {
		ringo_log( 'Payment failed customer email: no email address found, skipping', $post_id );
		return false;
	}

	// Check if we've already sent a payment_failed email for this post
	if ( get_post_meta( $post_id, '_ringo_payment_failed_email_sent', true ) ) {
		ringo_log( 'Payment failed customer email: already sent, skipping', $post_id );
		return true;
	}

	$subject   = 'Your payment could not be processed';
	$title     = esc_html( get_the_title( $post_id ) );
	$draft_url = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$error_msg = $error_message ? esc_html( $error_message ) : 'Your card could not be processed. This might be due to insufficient funds, a card issue, or a temporary problem with your bank.';

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Payment Could Not Be Processed</h2>
    <p>Hi,</p>
    <p>We attempted to process your payment for your boat listing, but it was declined.</p>
    
    <div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:5px;padding:12px;margin:15px 0;color:#856404;">
      <strong>Error:</strong> <?php echo $error_msg; ?>
    </div>

    <p><strong>Listing:</strong> <?php echo $title; ?></p>
    
    <p>Please try again with:</p>
    <ul style="margin:10px 0;padding-left:20px;">
      <li>A different payment method</li>
      <li>A different card</li>
      <li>Contact your bank if you believe this is an error</li>
    </ul>

    <p>
      <a href="<?php echo $draft_url; ?>"
         style="display:inline-block;padding:10px 18px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:3px;">
        Try Payment Again
      </a>
    </p>

    <p>Having trouble or need help?</p>
    <p>
      <a href="https://bassboat4sale.com/contact/"
         style="display:inline-block;padding:10px 18px;background:#555;color:#fff;text-decoration:none;border-radius:3px;">
        Contact Us
      </a>
    </p>
    <p>If you prefer, you can also reply directly to this email and we'll be happy to assist.</p>
    <div style="margin-top:20px;font-size:12px;color:#888;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	$sent = ringo_send_mail(
		'Payment failed customer email',
		$to,
		$subject,
		$html,
		[ 'Reply-To: ammar@ringomedia.com' ],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_payment_failed_email_sent', 1 );
	}

	return $sent;
}

// ─── Payment Failed Admin Email ───────────────────────────────────────────────

/**
 * Notify admin when a payment fails.
 *
 * @param  int    $post_id
 * @param  string $error_message The specific payment error
 * @return bool
 */
function ringo_send_payment_failed_admin_email( $post_id, $error_message = '' ) {
	$to = get_option( 'admin_email' );

	if ( get_post_meta( $post_id, '_ringo_payment_failed_admin_email_sent', true ) ) {
		ringo_log( 'Payment failed admin email: already sent, skipping', $post_id );
		return true;
	}

	$subject    = 'Payment Failed – Boat Listing Still in Draft';
	$title      = sanitize_text_field( get_the_title( $post_id ) );
	$user_email = ringo_get_form_email( $post_id );
	$provider   = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );
	$draft_url  = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$error_msg  = $error_message ? esc_html( $error_message ) : 'Unknown error';

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Payment Failed – Listing Still in Draft</h2>
    <p>A user's payment was rejected. The listing remains in draft status.</p>
    
    <table style="width:100%;border-collapse:collapse;margin-top:10px;">
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Boat Title</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $title ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Post ID</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $post_id ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>User Email</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $user_email ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Provider</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $provider ?: '—' ); ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Error</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;background:#fff3cd;"><?php echo $error_msg; ?></td>
      </tr>
      <tr>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Draft edit URL</strong></td>
        <td style="padding:8px 10px;border-bottom:1px solid #eee;"><a href="<?php echo $draft_url; ?>"><?php echo esc_html( $draft_url ); ?></a></td>
      </tr>
    </table>
    
    <p style="margin-top:15px;font-size:12px;color:#888;">
      This is an automatic notification. The customer has been notified of the payment failure.
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </p>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	$sent = ringo_send_mail(
		'Payment failed admin email',
		$to,
		$subject,
		$html,
		[],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_payment_failed_admin_email_sent', 1 );
	}

	return $sent;
}