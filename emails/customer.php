<?php
/**
 * Customer payment-success ("Your listing is now published!") email.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send the customer a confirmation email after their listing goes live.
 *
 * Sending is guarded by a post-meta flag so the email is never sent twice,
 * even if `ringo_process_paid()` is called more than once for the same post.
 *
 * @param  int    $post_id       ID of the published 'boats' post.
 * @param  string $package_name
 * @param  float  $package_price
 * @return bool   TRUE if the e-mail was accepted by wp_mail(); FALSE otherwise.
 */
function ringo_send_publish_email( $post_id, $package_name, $package_price ) {
	$to = ringo_get_form_email( $post_id );
	if ( ! $to ) {
		ringo_log( 'Customer email: missing recipient', [ 'post_id' => $post_id ] );
		return false;
	}

	if ( get_post_meta( $post_id, '_ringo_publish_email_sent', true ) ) {
		ringo_log( 'Customer email: already sent, skipping', $post_id );
		return true;
	}

	$subject        = 'Thank You! 🎉 Your Listing is Now Published!';
	$title          = esc_html( get_the_title( $post_id ) );
	$pkg_name_safe  = esc_html( (string) $package_name );
	$pkg_price_safe = number_format( (float) $package_price, 2 );
	$addons_safe    = esc_html( ringo_get_boat_addons_summary( $post_id ) );

	$boat_url = get_permalink( $post_id ) ?: home_url( '/' );

	ob_start();
	?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;background:#f6f6f6;">
  <div style="max-width:600px;margin:20px auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#0a6ebd;">🎉 Payment Received — Your Boat is Published!</h2>
    <p>Hi,</p>
    <p>Thank you for your payment! Your boat listing is now live on our website.</p>

    <h3>Listing Details</h3>
    <table style="width:100%;border-collapse:collapse;margin-top:15px;">
      <tr>
        <th style="border:1px solid #ddd;padding:10px;text-align:left;background:#f0f0f0;">Boat Name</th>
        <td style="border:1px solid #ddd;padding:10px;"><?php echo $title; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ddd;padding:10px;text-align:left;background:#f0f0f0;">Package</th>
        <td style="border:1px solid #ddd;padding:10px;"><?php echo $pkg_name_safe; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ddd;padding:10px;text-align:left;background:#f0f0f0;">Add-ons</th>
        <td style="border:1px solid #ddd;padding:10px;"><?php echo $addons_safe; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ddd;padding:10px;text-align:left;background:#f0f0f0;">Total Paid</th>
        <td style="border:1px solid #ddd;padding:10px;">$<?php echo $pkg_price_safe; ?></td>
      </tr>
      <tr>
        <th style="border:1px solid #ddd;padding:10px;text-align:left;background:#f0f0f0;">Payment Status</th>
        <td style="border:1px solid #ddd;padding:10px;">Paid</td>
      </tr>
    </table>

    <p>You can view your listing here:</p>
    <a href="<?php echo esc_url( $boat_url ); ?>"
       style="display:inline-block;padding:10px 20px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:3px;margin-top:10px;">
      View My Boat
    </a>

    <p style="margin-top:15px;">If you have any questions, just reply to this email.</p>
	  <!-- Scam warning -->
    <div style="margin-top:20px;padding:12px 16px;background:#fff8e1;border-left:4px solid #f9a825;border-radius:3px;">
      <p style="margin:0;font-size:13px;color:#555;">
        <strong style="color:#e65100;">&#9888;&#65039; FYI &mdash; BEWARE OF SCAMS!</strong> &mdash;
        <a href="<?php echo esc_url( home_url( '/faq/' ) ); ?>" style="color:#0a6ebd;text-decoration:underline;">Read Our FAQ</a>
      </p>
      <p style="margin:6px 0 0;font-size:13px;color:#555;">
        Bass Boat Reports do not exist, do not fall for any scams via Email / Call / Text !!!
      </p>
    </div>
    <div style="text-align:center;font-size:12px;color:#888;margin-top:20px;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: Bassboat4sale <ammar@ringomedia.com>',
	];

	$sent = wp_mail( $to, $subject, $html, $headers );

	ringo_log( 'Customer email: wp_mail result', [
		'sent'    => (bool) $sent,
		'to'      => $to,
		'post_id' => $post_id,
	] );

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_publish_email_sent', 1 );
	}

	return (bool) $sent;
}

/**
 * Send the seller the "listing updated" confirmation after editing a paid boat.
 *
 * Styled after the former JetFormBuilder edit-form customer email (form 1811).
 *
 * @param  int $post_id Boat post ID.
 * @return bool
 */
function ringo_send_customer_edit_email( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	$to = sanitize_email( (string) get_post_meta( $post_id, 'email', true ) );
	if ( ! $to || ! is_email( $to ) ) {
		return false;
	}

	$view_url = get_permalink( $post_id ) ?: home_url( '/' );
	$title    = html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );

	$body  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Your Listing Was Updated</title></head>';
	$body .= '<body style="margin:0; padding:0; background-color:#f6f6f6; font-family: Arial, sans-serif;">';
	$body .= '<table width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f6f6f6"><tr><td align="center" style="padding:20px;">';
	$body .= '<table width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="border-radius:5px; overflow:hidden; border:1px solid #e0e0e0;">';
	$body .= '<tr><td align="center" bgcolor="#1998D0" style="padding:20px; color:#ffffff; font-size:24px; font-weight:bold;">Your Listing Was Updated</td></tr>';
	$body .= '<tr><td style="padding:20px; color:#333333; font-size:16px; line-height:1.6;">';
	$body .= '<p style="margin:0 0 15px;">Your changes to <strong>' . esc_html( $title ) . '</strong> have been saved and are now live on our website.</p>';
	$body .= '<h3 style="color:#1998D0; margin:20px 0 10px; border-bottom:2px solid #1998D0; padding-bottom:5px;">What You Can Do Now</h3>';
	$body .= '<ul style="padding-left:20px; margin:0;">';
	$body .= '<li style="margin-bottom:10px;"><strong>Manage Your Listing</strong><br>Update details, add more photos, or make edits anytime.</li>';
	$body .= '<li style="margin-bottom:10px;"><strong>Share &amp; Promote</strong><br>Share your listing link to reach more buyers.</li>';
	$body .= '</ul>';
	$body .= '<p style="margin:20px 0 0;">Need help? <a href="mailto:support@bassboat4sale.com" style="color:#1998D0; text-decoration:none;">Contact our team</a> and we\'ll be glad to assist you.</p>';
	$body .= '<p style="margin-top:25px; text-align:center;"><a href="' . esc_url( $view_url ) . '" style="background-color:#1998D0; color:#ffffff; padding:12px 30px; text-decoration:none; border-radius:4px; font-size:16px; font-weight:bold; display:inline-block;">View Boat</a></p>';
	$body .= '<p style="margin-top:25px;">Thank You,<br><strong>BassBoat4Sale</strong></p>';
	$body .= '</td></tr>';
	$body .= '<tr><td align="center" style="font-size:12px; color:#888888; padding:15px; border-top:1px solid #e0e0e0;">&copy; ' . esc_html( wp_date( 'Y' ) ) . ' BassBoat4Sale. All rights reserved.</td></tr>';
	$body .= '</table></td></tr></table></body></html>';

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: BassBoat4Sale <ammar@ringomedia.com>',
		'Reply-To: ' . sanitize_email( (string) get_option( 'admin_email' ) ),
	];

	$sent = wp_mail( $to, 'Your Listing Was Updated', $body, $headers );
	ringo_log( 'Native edit seller email', [ 'post_id' => $post_id, 'sent' => (bool) $sent, 'recipient' => $to ] );

	return (bool) $sent;
}
