<?php
/**
 * Draft payment failure monitoring and notification emails.
 *
 * This channel is intentionally separate from the abandoned/unpaid boat
 * follow-up sequence. Recording or emailing a draft failure never changes the
 * follow-up sent flags and never marks the checkout as followed_up.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return a readable label for a machine failure condition.
 *
 * @param string $condition Condition code.
 * @return string
 */
function ringo_get_draft_failure_label( $condition ) {
	$labels = [
		'card_rejected'             => 'Card rejected',
		'stripe_intent_error'       => 'Stripe could not start payment',
		'stripe_confirmation_error' => 'Stripe confirmation failed',
		'stripe_payment_incomplete' => 'Stripe payment did not complete',
		'paypal_no_response'        => 'PayPal did not respond',
		'paypal_order_error'        => 'PayPal order creation failed',
		'paypal_capture_error'      => 'PayPal capture failed',
		'paypal_sdk_error'          => 'PayPal payment window error',
		'payment_snippet_stuck'     => 'Payment window stuck or unresponsive',
		'payment_pending'           => 'Payment delayed or pending',
		'gateway_timeout'           => 'Payment gateway timeout',
		'gateway_unavailable'       => 'Payment gateway unavailable',
		'checkout_abandoned'        => 'Checkout abandoned during payment',
		'payment_cancelled'         => 'Payment cancelled',
		'payment_setup_error'       => 'Payment setup error',
		'payment_incomplete'        => 'Payment did not complete',
	];

	$condition = sanitize_key( (string) $condition );
	return isset( $labels[ $condition ] ) ? $labels[ $condition ] : ucwords( str_replace( '_', ' ', $condition ) );
}

/**
 * Detect a timeout from a gateway message or HTTP code.
 *
 * @param string $message Error text.
 * @param int    $http_code Optional HTTP code.
 * @return bool
 */
function ringo_is_gateway_timeout( $message = '', $http_code = 0 ) {
	if ( in_array( (int) $http_code, [ 408, 504, 524, 598, 599 ], true ) ) {
		return true;
	}

	$message = strtolower( (string) $message );
	foreach ( [ 'timeout', 'timed out', 'operation timed out', 'curl error 28', 'cURL error 28' ] as $needle ) {
		if ( strpos( $message, strtolower( $needle ) ) !== false ) {
			return true;
		}
	}

	return false;
}

/**
 * Keep only safe, compact scalar context for logs and post meta.
 *
 * @param mixed $value Raw context value.
 * @return mixed
 */
function ringo_sanitize_failure_context_value( $value ) {
	if ( is_array( $value ) ) {
		$out = [];
		foreach ( array_slice( $value, 0, 20, true ) as $key => $item ) {
			$out[ sanitize_key( (string) $key ) ] = ringo_sanitize_failure_context_value( $item );
		}
		return $out;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value;
	}

	return sanitize_text_field( substr( (string) $value, 0, 500 ) );
}

/**
 * Start a new payment attempt for a draft boat.
 *
 * @param int    $post_id Boat post ID.
 * @param string $state Initial state.
 * @return string Attempt ID.
 */
function ringo_begin_payment_attempt( $post_id, $state = 'draft_created' ) {
	$post_id    = (int) $post_id;
	$attempt_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'ringo-', true );
	$now        = time();

	update_post_meta( $post_id, '_ringo_payment_attempt_id', sanitize_text_field( $attempt_id ) );
	update_post_meta( $post_id, '_ringo_payment_attempt_started_at', $now );
	update_post_meta( $post_id, '_ringo_payment_last_activity', $now );
	update_post_meta( $post_id, '_ringo_payment_state', sanitize_key( $state ) );
	delete_post_meta( $post_id, '_ringo_draft_failure_condition' );
	delete_post_meta( $post_id, '_ringo_draft_failure_message' );
	delete_post_meta( $post_id, '_ringo_draft_failure_time' );

	ringo_log( 'Payment attempt started', [
		'post_id'    => $post_id,
		'attempt_id' => $attempt_id,
		'state'      => sanitize_key( $state ),
	] );

	return $attempt_id;
}

/**
 * Update the current payment stage without touching follow-up status.
 *
 * @param int    $post_id Boat post ID.
 * @param string $state Payment stage.
 * @param string $provider Optional provider.
 * @param array  $context Optional safe context.
 * @return bool
 */
function ringo_update_payment_activity( $post_id, $state, $provider = '', $context = [] ) {
	$post_id = (int) $post_id;
	$post    = get_post( $post_id );

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return false;
	}

	$state = sanitize_key( (string) $state );
	$now   = time();

	if ( ! get_post_meta( $post_id, '_ringo_payment_attempt_id', true ) ) {
		ringo_begin_payment_attempt( $post_id, $state ?: 'payment_started' );
	}

	update_post_meta( $post_id, '_ringo_payment_last_activity', $now );
	if ( $state ) {
		update_post_meta( $post_id, '_ringo_payment_state', $state );
	}
	if ( $provider ) {
		update_post_meta( $post_id, '_ringo_payment_provider', sanitize_key( $provider ) );
	}

	$previous = (string) get_post_meta( $post_id, '_ringo_payment_last_logged_state', true );
	if ( $state && $state !== $previous ) {
		update_post_meta( $post_id, '_ringo_payment_last_logged_state', $state );
		ringo_log( 'Payment activity state changed', [
			'post_id' => $post_id,
			'state'   => $state,
			'provider'=> sanitize_key( $provider ),
			'context' => ringo_sanitize_failure_context_value( $context ),
		] );
	}

	return true;
}

/**
 * Return recipients for the dedicated draft failure notification.
 *
 * Default behavior preserves the existing delivery pattern: the seller and
 * the WordPress site administrator. Josh is only explicitly added when
 * RINGO_DRAFT_FAILURE_JOSH_EMAIL is defined or a filter adds his address.
 *
 * @param int $post_id Boat post ID.
 * @return array<string,string> Role => email.
 */
function ringo_get_draft_failure_recipients( $post_id ) {
	$recipients = [];
	$seller     = sanitize_email( ringo_get_form_email( $post_id ) );
	$admin      = sanitize_email( (string) get_option( 'admin_email' ) );

	if ( $seller && is_email( $seller ) ) {
		$recipients['seller'] = $seller;
	}
	if ( $admin && is_email( $admin ) && ! in_array( $admin, $recipients, true ) ) {
		$recipients['admin'] = $admin;
	}

	if ( defined( 'RINGO_DRAFT_FAILURE_JOSH_EMAIL' ) ) {
		$josh = sanitize_email( (string) RINGO_DRAFT_FAILURE_JOSH_EMAIL );
		if ( $josh && is_email( $josh ) && ! in_array( $josh, $recipients, true ) ) {
			$recipients['josh'] = $josh;
		}
	}

	/**
	 * Filter the dedicated draft failure recipients.
	 *
	 * @param array<string,string> $recipients Role => email.
	 * @param int                  $post_id Boat post ID.
	 */
	$recipients = apply_filters( 'ringo_draft_failure_recipients', $recipients, (int) $post_id );

	$out = [];
	foreach ( (array) $recipients as $role => $email ) {
		$email = sanitize_email( (string) $email );
		if ( $email && is_email( $email ) && ! in_array( $email, $out, true ) ) {
			$out[ sanitize_key( (string) $role ) ?: 'recipient' ] = $email;
		}
	}

	return $out;
}

/**
 * Send the dedicated draft failure email once per recipient per attempt.
 *
 * @param int    $post_id Boat post ID.
 * @param string $condition Condition code.
 * @param string $message Failure detail.
 * @param array  $context Safe context.
 * @return bool True when at least one recipient was sent or already sent.
 */
function ringo_send_draft_failure_notification_email( $post_id, $condition, $message = '', $context = [] ) {
	$attempt_id = (string) get_post_meta( $post_id, '_ringo_payment_attempt_id', true );
	if ( ! $attempt_id ) {
		$attempt_id = 'legacy-' . (int) get_post_meta( $post_id, '_ringo_unpaid_time', true );
	}

	$recipients = ringo_get_draft_failure_recipients( $post_id );
	if ( empty( $recipients ) ) {
		ringo_log( 'Draft failure notification: no recipients', [ 'post_id' => $post_id ] );
		return false;
	}

	$title       = sanitize_text_field( get_the_title( $post_id ) );
	$provider    = sanitize_text_field( (string) get_post_meta( $post_id, '_ringo_payment_provider', true ) );
	$package     = sanitize_text_field( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$amount      = (float) get_post_meta( $post_id, '_ringo_amount', true );
	$draft_url   = esc_url( ringo_get_draft_edit_url( $post_id ) );
	$label       = ringo_get_draft_failure_label( $condition );
	$safe_msg    = sanitize_text_field( (string) $message );
	$sent_any    = false;
	$context     = ringo_sanitize_failure_context_value( $context );

	foreach ( $recipients as $role => $to ) {
		$meta_key = '_ringo_draft_failure_notified_' . sanitize_key( $role ) . '_attempts';
		$sent_attempts = get_post_meta( $post_id, $meta_key, true );
		$sent_attempts = is_array( $sent_attempts ) ? $sent_attempts : [];

		if ( in_array( $attempt_id, $sent_attempts, true ) ) {
			ringo_log( 'Draft failure notification: already sent for attempt', [
				'post_id'    => $post_id,
				'attempt_id' => $attempt_id,
				'role'       => $role,
				'condition'  => $condition,
			] );
			$sent_any = true;
			continue;
		}

		$is_seller = ( 'seller' === $role );
		$subject   = $is_seller
			? 'Action needed: your boat listing is still in draft'
			: 'Draft payment issue: ' . $label . ' - ' . $title;

		ob_start();
		?>
<!DOCTYPE html>
<html>
<body style="font-family:Arial,sans-serif;background:#f6f6f6;padding:20px;">
  <div style="max-width:640px;margin:0 auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">
    <h2 style="color:#d32f2f;">Boat listing remains in draft</h2>
    <?php if ( $is_seller ) : ?>
      <p>Hi,</p>
      <p>Your payment did not complete, so your boat listing is still saved as a draft and is not visible on the site.</p>
      <p><strong>Listing:</strong> <?php echo esc_html( $title ); ?></p>
      <p><strong>Payment status:</strong> <?php echo esc_html( $label ); ?></p>
      <p>Please return to your listing and try the payment again.</p>
      <p><a href="<?php echo $draft_url; ?>" style="display:inline-block;padding:11px 18px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:3px;">Return to listing</a></p>
      <p>If payment may have gone through, contact support before trying again so we can verify it.</p>
    <?php else : ?>
      <p>The dedicated draft-payment monitor detected a checkout failure before payment completed.</p>
      <table style="width:100%;border-collapse:collapse;margin-top:10px;">
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Issue</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $label ); ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Boat</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $title ); ?> (#<?php echo esc_html( $post_id ); ?>)</td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Seller</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( ringo_get_form_email( $post_id ) ?: '—' ); ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Payment method</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $provider ? ucwords( str_replace( '_', ' ', $provider ) ) : 'Not confirmed' ); ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Package</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $package ? ucwords( str_replace( '_', ' ', $package ) ) : '—' ); ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Amount</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo $amount > 0 ? esc_html( '$' . number_format( $amount, 2 ) ) : '—'; ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Details</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><?php echo esc_html( $safe_msg ?: 'The payment did not complete.' ); ?></td></tr>
        <tr><td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>Draft listing</strong></td><td style="padding:8px 10px;border-bottom:1px solid #eee;"><a href="<?php echo $draft_url; ?>">Open the draft listing</a></td></tr>
      </table>
      <p style="font-size:12px;color:#777;">This notification is separate from the boat follow-up email sequence.</p>
    <?php endif; ?>
    <div style="margin-top:20px;font-size:12px;color:#888;">&copy; 2026 BassBoat4Sale. All rights reserved.</div>
  </div>
</body>
</html>
		<?php
		$html = ob_get_clean();

		$sent = ringo_send_mail(
			'Draft failure notification (' . $role . ')',
			$to,
			$subject,
			$html,
			[ 'Reply-To: ammar@ringomedia.com' ],
			$post_id
		);

		if ( $sent ) {
			$sent_attempts[] = $attempt_id;
			$sent_attempts   = array_slice( array_values( array_unique( $sent_attempts ) ), -20 );
			update_post_meta( $post_id, $meta_key, $sent_attempts );
			$sent_any = true;
		}
	}

	return $sent_any;
}

/**
 * Record a pre-payment failure and send the dedicated notification.
 *
 * @param int    $post_id Boat post ID.
 * @param string $condition Machine condition code.
 * @param string $message Human-readable detail.
 * @param array  $context Safe diagnostic context.
 * @param bool   $notify Whether to send the dedicated email.
 * @return bool
 */
function ringo_record_draft_failure( $post_id, $condition, $message = '', $context = [], $notify = true ) {
	$post_id   = (int) $post_id;
	$condition = sanitize_key( (string) $condition );
	$message   = sanitize_text_field( (string) $message );
	$post      = get_post( $post_id );

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		ringo_log( 'Draft failure ignored: invalid boat', [ 'post_id' => $post_id, 'condition' => $condition ] );
		return false;
	}

	if ( 'paid' === get_post_meta( $post_id, '_ringo_checkout_status', true ) || 'publish' === $post->post_status ) {
		ringo_log( 'Draft failure ignored: payment already completed', [ 'post_id' => $post_id, 'condition' => $condition ] );
		return false;
	}

	if ( ! $condition ) {
		$condition = 'payment_incomplete';
	}

	$attempt_id = (string) get_post_meta( $post_id, '_ringo_payment_attempt_id', true );
	if ( ! $attempt_id ) {
		$attempt_id = ringo_begin_payment_attempt( $post_id, 'failure_detected' );
	}

	$provider = sanitize_key( (string) get_post_meta( $post_id, '_ringo_payment_provider', true ) );
	$state    = sanitize_key( (string) get_post_meta( $post_id, '_ringo_payment_state', true ) );
	$now      = time();
	$context  = ringo_sanitize_failure_context_value( $context );

	$event = [
		'time'       => $now,
		'time_mysql' => current_time( 'mysql' ),
		'attempt_id' => $attempt_id,
		'condition'  => $condition,
		'label'      => ringo_get_draft_failure_label( $condition ),
		'message'    => $message,
		'provider'   => $provider,
		'state'      => $state,
		'context'    => $context,
	];

	$history = get_post_meta( $post_id, '_ringo_draft_failure_history', true );
	$history = is_array( $history ) ? $history : [];
	$history[] = $event;
	$history = array_slice( $history, -20 );

	update_post_meta( $post_id, '_ringo_draft_failure_history', $history );
	update_post_meta( $post_id, '_ringo_draft_failure_condition', $condition );
	update_post_meta( $post_id, '_ringo_draft_failure_message', $message );
	update_post_meta( $post_id, '_ringo_draft_failure_time', $now );
	update_post_meta( $post_id, '_ringo_payment_state', 'failure_' . $condition );
	update_post_meta( $post_id, '_ringo_payment_last_activity', $now );

	ringo_log( 'Draft payment failure condition fired', [
		'post_id'    => $post_id,
		'attempt_id' => $attempt_id,
		'condition'  => $condition,
		'label'      => ringo_get_draft_failure_label( $condition ),
		'message'    => $message,
		'provider'   => $provider,
		'source'     => isset( $context['source'] ) ? $context['source'] : '',
		'context'    => $context,
	] );

	if ( ! $notify ) {
		return true;
	}

	$lock_key = 'ringo_draft_failure_' . $post_id . '_' . md5( $attempt_id );
	if ( get_transient( $lock_key ) ) {
		ringo_log( 'Draft failure notification locked by another request', [ 'post_id' => $post_id, 'attempt_id' => $attempt_id ] );
		return true;
	}

	set_transient( $lock_key, 1, 30 );
	$sent = ringo_send_draft_failure_notification_email( $post_id, $condition, $message, $context );
	delete_transient( $lock_key );

	return $sent;
}
