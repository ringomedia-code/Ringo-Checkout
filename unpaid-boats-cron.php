<?php
/**
 * Cron job to check for unpaid boats and send reminder emails.
 * 
 * Checks for boats marked as "unpaid" that have been sitting for more than
 * the configured time (default: 2 hours) and sends reminder emails to the user.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hook into WordPress cron
add_action( 'ringo_check_unpaid_boats', 'ringo_check_unpaid_boats_handler' );

// Schedule the cron job
add_action( 'wp_loaded', 'ringo_schedule_unpaid_check' );

/**
 * Schedule the unpaid boat check cron job
 */
function ringo_schedule_unpaid_check() {
	if ( ! wp_next_scheduled( 'ringo_check_unpaid_boats' ) ) {
		wp_schedule_event( time() + 60, 'ringo_10min', 'ringo_check_unpaid_boats' );
		ringo_log( 'Unpaid boats cron scheduled' );
	}
}

/**
 * Main handler: Check for unpaid boats and send emails
 */
function ringo_check_unpaid_boats_handler() {
	ringo_log( 'Running unpaid boats check' );

	// Get settings for timeout
	$settings = ringo_get_settings();
	$unpaid_timeout = (int) ( $settings['unpaid_timeout'] ?? 180 ); // Default: 3 minutes = 180 seconds

	// Find all unpaid boats
	$args = [
		'post_type'  => ringo_boat_post_types(),
		'posts_per_page' => -1,
		'post_status' => 'draft',
		'meta_query' => [
			[
				'key'   => '_ringo_checkout_status',
				'value' => 'unpaid',
				'compare' => '='
			]
		]
	];

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		ringo_log( 'No unpaid boats found' );
		return;
	}

	while ( $query->have_posts() ) {
		$query->the_post();
		$post_id = get_the_ID();

		// Get the time when status was marked as unpaid
		$unpaid_time = (int) get_post_meta( $post_id, '_ringo_unpaid_time', true );
		
		if ( ! $unpaid_time ) {
			// If no time recorded, use post created date
			$unpaid_time = strtotime( get_the_date( 'c' ) );
			update_post_meta( $post_id, '_ringo_unpaid_time', $unpaid_time );
		}

		// _ringo_unpaid_time is stored with time() (UTC), so compare against time(), not the
		// site-local current_time('timestamp'), which skews the delay by the timezone offset.
		$current_time = time();
		$time_elapsed = $current_time - $unpaid_time;

		// Check if timeout has passed
		if ( $time_elapsed < $unpaid_timeout ) {
			continue; // Not yet time to send email
		}

		// Check if we already sent an email for this unpaid boat
		$email_sent = get_post_meta( $post_id, '_ringo_unpaid_email_sent', true );
		
		if ( $email_sent ) {
			ringo_log( 'Unpaid email already sent for boat ' . $post_id, $post_id );
			continue;
		}

		// Someone is still actively paying: do not send "payment not completed" mid-checkout.
		$last_activity = (int) get_post_meta( $post_id, '_ringo_payment_last_activity', true );
		if ( $last_activity && ( time() - $last_activity ) < max( $unpaid_timeout, 600 ) ) {
			continue;
		}

		// Send email to customer
		ringo_send_unpaid_boat_customer_email( $post_id );

		// Send email to admin (once per boat, even if the customer email could not be sent)
		if ( ! get_post_meta( $post_id, '_ringo_unpaid_admin_email_sent', true ) ) {
			ringo_send_unpaid_boat_admin_email( $post_id );
		}
	}

	wp_reset_postdata();
}

/**
 * Send email to customer about unpaid boat
 *
 * @param int $post_id
 * @return bool
 */
function ringo_send_unpaid_boat_customer_email( $post_id ) {
	$to = ringo_get_form_email( $post_id );
	
	if ( ! $to ) {
		ringo_log( 'Unpaid boat customer email: no email found', $post_id );
		return false;
	}

	$title = esc_html( get_the_title( $post_id ) );
	$draft_url = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$package = sanitize_text_field( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );

	$subject = 'Complete your boat listing payment';

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Your boat listing is waiting for payment</h2>
    <p>Hi,</p>
    <p>We noticed that your boat listing payment was not completed.</p>

    <div style="background:#fff3cd;border:1px solid #ffeeba;border-radius:5px;padding:15px;margin:15px 0;">
      <p style="margin:0;"><strong>ACTION REQUIRED:</strong></p>
      <p style="margin:5px 0 0 0;">Complete payment now to publish your boat!</p>
    </div>

    <p><strong>Listing Details:</strong></p>
    <table style="width:100%;border-collapse:collapse;">
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Boat Name</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo $title; ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Package</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo $package ? esc_html( ucfirst( $package ) ) : '—'; ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Amount Due</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo $amount > 0 ? '$' . number_format( $amount, 2 ) : '—'; ?></td>
      </tr>
    </table>

    <p style="margin-top:20px;">
      <a href="<?php echo $draft_url; ?>"
         style="display:inline-block;padding:12px 20px;background:#d32f2f;color:#fff;text-decoration:none;border-radius:3px;font-weight:bold;">
        Complete Payment Now
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

	$sent = ringo_send_mail(
		'Unpaid boat customer reminder email',
		$to,
		$subject,
		$html,
		[ 'Reply-To: ammar@ringomedia.com' ],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_unpaid_email_sent', 1 );
		update_post_meta( $post_id, '_ringo_unpaid_email_sent_time', current_time( 'timestamp' ) );
	}

	return $sent;
}

/**
 * Send email to admin about unpaid boat
 *
 * @param int $post_id
 * @return bool
 */
function ringo_send_unpaid_boat_admin_email( $post_id ) {
	$to = get_option( 'admin_email' );

	$title = sanitize_text_field( get_the_title( $post_id ) );
	$user_email = ringo_get_form_email( $post_id );
	$package = sanitize_text_field( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
	$draft_url = esc_url( ringo_get_draft_edit_url( $post_id ) );

	$subject = 'Unpaid boat listing - ' . $title;

	ob_start();
	?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Unpaid boat listing - Reminder sent</h2>
    <p>A reminder email has been sent to the user about their unpaid boat listing.</p>

    <p><strong>Listing Details:</strong></p>
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
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Package</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo $package ? esc_html( ucfirst( $package ) ) : '—'; ?></td>
      </tr>
      <tr>
        <td style="padding:8px;border-bottom:1px solid #eee;"><strong>Amount Due</strong></td>
        <td style="padding:8px;border-bottom:1px solid #eee;"><?php echo $amount > 0 ? '$' . number_format( $amount, 2 ) : '—'; ?></td>
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

	$sent = ringo_send_mail(
		'Unpaid boat admin reminder email',
		$to,
		$subject,
		$html,
		[],
		$post_id
	);

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_unpaid_admin_email_sent', 1 );
	}

	return $sent;
}

/**
 * Add setting for unpaid timeout in settings
 */
add_filter( 'ringo_settings_fields', 'ringo_add_unpaid_timeout_setting' );

function ringo_add_unpaid_timeout_setting( $fields ) {
	$fields['unpaid_timeout'] = [
		'type' => 'number',
		'label' => 'Unpaid boat timeout (seconds)',
		'default' => 180, // 3 minutes
		'description' => 'Time in seconds before sending unpaid boat reminder (3 minutes = 180, 1 hour = 3600, 2 hours = 7200)'
	];

	return $fields;
}