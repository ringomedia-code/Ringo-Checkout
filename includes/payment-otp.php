<?php
/**
 * Email code (one-time password) before payment key or mode changes, and an
 * alert email on every payment settings change.
 *
 * - Changing any Stripe/PayPal key or client ID, clearing one, or changing the
 *   Stripe or PayPal mode needs a 6 digit code emailed to RINGO_PAYMENT_OTP_EMAIL.
 * - The address lives ONLY in a wp-config constant: not in an option, not on
 *   the settings page, so an attacker with wp-admin cannot redirect it.
 * - No constant = key and mode changes are blocked (no fallback recipient: a
 *   fallback is exactly what an attacker would aim for). Prices, descriptions
 *   and timers still save without a code.
 * - Code: 10 minutes, single use, tied to the user and the login session,
 *   5 tries, sends rate limited. Stored only as an HMAC in user meta.
 * - Every change to ringo_stripe_settings (from any route that uses
 *   update_option) sends an alert: user, time, IP, field names. Never values;
 *   keys at most as "ends in ••••1234".
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const RINGO_OTP_TTL         = 600; // 10 minutes.
const RINGO_OTP_MAX_TRIES   = 5;
const RINGO_OTP_RESEND_WAIT = 60;  // Seconds between sends per user.
const RINGO_OTP_HOURLY_CAP  = 5;   // Sends per user per hour.
const RINGO_OTP_SITE_CAP    = 10;  // Sends per site per hour.
const RINGO_OTP_META        = '_ringo_payment_otp';
const RINGO_OTP_SENDS_META  = '_ringo_payment_otp_sends';

// ─── Recipient ───────────────────────────────────────────────────────────────

/**
 * The code and alert address from RINGO_PAYMENT_OTP_EMAIL, or '' when not set
 * or not a valid address.
 *
 * @return string
 */
function ringo_payment_otp_email() {
	if ( ! defined( 'RINGO_PAYMENT_OTP_EMAIL' ) ) {
		return '';
	}
	$email = trim( (string) RINGO_PAYMENT_OTP_EMAIL );
	return is_email( $email ) ? $email : '';
}

/**
 * "m•••@example.com" for showing on the settings page.
 *
 * @param string $email Address.
 * @return string
 */
function ringo_mask_email( $email ) {
	$parts = explode( '@', (string) $email, 2 );
	if ( 2 !== count( $parts ) || '' === $parts[0] ) {
		return '';
	}
	return substr( $parts[0], 0, 1 ) . '•••@' . $parts[1];
}

// ─── Which changes need a code ───────────────────────────────────────────────

/**
 * Fields whose change needs a code: every key / client ID, and both modes.
 *
 * @return string[]
 */
function ringo_otp_protected_fields() {
	return array_merge( array_keys( ringo_credential_constant_map() ), [ 'mode', 'paypal_mode' ] );
}

/**
 * Protected fields that differ between the stored and the new settings.
 *
 * @param array $old Stored settings (raw option).
 * @param array $new Sanitised new settings.
 * @return string[] Field names.
 */
function ringo_otp_protected_changes( array $old, array $new ) {
	$defaults = ringo_default_settings();
	$changed  = [];
	foreach ( ringo_otp_protected_fields() as $field ) {
		$before = array_key_exists( $field, $old ) ? (string) $old[ $field ] : (string) ( $defaults[ $field ] ?? '' );
		$after  = array_key_exists( $field, $new ) ? (string) $new[ $field ] : $before;
		if ( $before !== $after ) {
			$changed[] = $field;
		}
	}
	return $changed;
}

/**
 * Called from ringo_sanitize_settings(): keep protected changes only when a
 * valid code was entered; otherwise put the stored values back, explain why,
 * and email a code. Non protected changes go through untouched.
 *
 * @param array $out    Sanitised settings about to be saved.
 * @param array $stored Raw stored option.
 * @param array $input  Raw form input (holds 'otp_code').
 * @return array
 */
function ringo_otp_guard_settings( array $out, array $stored, array $input ) {
	// WordPress may run the sanitize callback twice in one save (first save of
	// a new option). Once a code is accepted in this request, it stays accepted.
	static $approved_this_request = false;

	$changed = ringo_otp_protected_changes( $stored, $out );
	if ( ! $changed || $approved_this_request ) {
		return $out;
	}

	$to = ringo_payment_otp_email();
	if ( '' === $to ) {
		ringo_otp_revert( $out, $stored, $changed );
		ringo_otp_error( 'ringo_otp_no_email', 'Payment key and mode changes are locked: define RINGO_PAYMENT_OTP_EMAIL in wp-config.php to enable them. Not saved: ' . implode( ', ', $changed ) . '. Other settings were saved.' );
		return $out;
	}

	$code = isset( $input['otp_code'] ) ? preg_replace( '/\D/', '', (string) $input['otp_code'] ) : '';
	if ( '' !== $code ) {
		$result = ringo_otp_verify( get_current_user_id(), $code );
		if ( true === $result ) {
			$approved_this_request = true;
			return $out;
		}
		ringo_otp_revert( $out, $stored, $changed );
		ringo_otp_error( 'ringo_otp_bad', $result . ' Not saved: ' . implode( ', ', $changed ) . '. Re-enter the keys and the code, then save again.' );
		return $out;
	}

	ringo_otp_revert( $out, $stored, $changed );
	$sent = ringo_otp_send( get_current_user_id(), $changed );
	$msg  = ( true === $sent )
		? 'A 6 digit code was emailed to ' . ringo_mask_email( $to ) . '. Enter it in "Confirmation code", re-enter the key changes, and save again (valid 10 minutes).'
		: $sent;
	ringo_otp_error( 'ringo_otp_needed', 'Changing ' . implode( ', ', $changed ) . ' needs an email code. ' . $msg . ' Other settings were saved.' );
	return $out;
}

/**
 * Put the stored values back for the given fields.
 *
 * @param array    $out     Settings (by reference).
 * @param array    $stored  Stored option.
 * @param string[] $fields  Fields.
 */
function ringo_otp_revert( array &$out, array $stored, array $fields ) {
	$defaults = ringo_default_settings();
	foreach ( $fields as $field ) {
		$out[ $field ] = array_key_exists( $field, $stored ) ? $stored[ $field ] : ( $defaults[ $field ] ?? '' );
	}
}

/**
 * Settings page error (shown once per code in a request).
 *
 * @param string $code    Error code.
 * @param string $message Message (never contains a secret).
 */
function ringo_otp_error( $code, $message ) {
	static $shown = [];
	if ( isset( $shown[ $code ] ) ) {
		return;
	}
	$shown[ $code ] = true;
	add_settings_error( 'ringo_stripe_settings', $code, $message );
}

// ─── Code create / send / verify ─────────────────────────────────────────────

/**
 * HMAC of the code, bound to the user and the login session.
 *
 * @param string $code    6 digits.
 * @param int    $user_id User.
 * @param string $session Session token.
 * @return string
 */
function ringo_otp_hash( $code, $user_id, $session ) {
	return hash_hmac( 'sha256', $code . '|' . (int) $user_id . '|' . $session, wp_salt( 'auth' ) );
}

/**
 * Current login session token (the code only works in the session that asked for it).
 *
 * @return string
 */
function ringo_otp_session() {
	return function_exists( 'wp_get_session_token' ) ? (string) wp_get_session_token() : '';
}

/**
 * Client IP for alerts. REMOTE_ADDR only: forwarded headers can be forged.
 *
 * @return string
 */
function ringo_otp_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
}

/**
 * "name (ID 3, email)" for alerts.
 *
 * @param int $user_id User.
 * @return string
 */
function ringo_otp_user_label( $user_id ) {
	$user_id = (int) $user_id;
	if ( ! $user_id ) {
		return 'no logged in user (wp-cli, cron or code)';
	}
	$u = get_userdata( $user_id );
	if ( ! $u ) {
		return 'user ID ' . $user_id;
	}
	return sprintf( '%s (ID %d, %s)', $u->user_login, $user_id, $u->user_email );
}

/**
 * Create a code and email it, within the rate limits.
 *
 * @param int      $user_id User asking.
 * @param string[] $fields  Fields the user tried to change (names only).
 * @return true|string True, or the reason no code was sent.
 */
function ringo_otp_send( $user_id, array $fields = [] ) {
	$user_id = (int) $user_id;
	$to      = ringo_payment_otp_email();
	if ( '' === $to ) {
		return 'No code sent: RINGO_PAYMENT_OTP_EMAIL is not defined.';
	}
	if ( ! $user_id || ! current_user_can( 'manage_options' ) ) {
		return 'No code sent: administrators only.';
	}

	$now   = time();
	$sends = get_user_meta( $user_id, RINGO_OTP_SENDS_META, true );
	$sends = is_array( $sends ) ? array_values( array_filter( $sends, static function ( $t ) use ( $now ) {
		return (int) $t > $now - HOUR_IN_SECONDS;
	} ) ) : [];

	if ( $sends && ( $now - max( $sends ) ) < RINGO_OTP_RESEND_WAIT ) {
		return 'A code was sent less than a minute ago; use that one or wait a minute.';
	}
	if ( count( $sends ) >= RINGO_OTP_HOURLY_CAP ) {
		return 'Too many codes requested this hour; try again later.';
	}
	$site_sends = (int) get_transient( 'ringo_payment_otp_site_sends' );
	if ( $site_sends >= RINGO_OTP_SITE_CAP ) {
		return 'Too many codes requested on this site this hour; try again later.';
	}

	$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
	update_user_meta( $user_id, RINGO_OTP_META, [
		'hash'    => ringo_otp_hash( $code, $user_id, ringo_otp_session() ),
		'expires' => $now + RINGO_OTP_TTL,
		'tries'   => 0,
	] );
	$sends[] = $now;
	update_user_meta( $user_id, RINGO_OTP_SENDS_META, $sends );
	set_transient( 'ringo_payment_otp_site_sends', $site_sends + 1, HOUR_IN_SECONDS );

	$site = ringo_site_host();
	$body = implode( "\n", [
		'Code to confirm a payment settings change on ' . $site . ':',
		'',
		'    ' . $code,
		'',
		'Valid 10 minutes, once, for the same login session.',
		'Requested by: ' . ringo_otp_user_label( $user_id ),
		'IP: ' . ringo_otp_ip(),
		'Time: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
		$fields ? 'Fields: ' . implode( ', ', $fields ) : 'Fields: (requested from the settings page)',
		'',
		'If you did not expect this, someone with an admin login is trying to change the Stripe or PayPal settings. Do not share the code.',
	] );
	$ok = wp_mail( $to, '[' . $site . '] Payment settings code', $body );

	return $ok ? true : 'The code email could not be sent (check the mail setup).';
}

/**
 * Check a code. Single use; 5 wrong tries burn it.
 *
 * @param int    $user_id User.
 * @param string $code    Entered code.
 * @return true|string True, or why it failed.
 */
function ringo_otp_verify( $user_id, $code ) {
	$user_id = (int) $user_id;
	$rec     = $user_id ? get_user_meta( $user_id, RINGO_OTP_META, true ) : null;
	if ( ! is_array( $rec ) || empty( $rec['hash'] ) ) {
		return 'No active code: request a new one.';
	}
	if ( time() > (int) $rec['expires'] ) {
		delete_user_meta( $user_id, RINGO_OTP_META );
		return 'The code expired: request a new one.';
	}
	if ( (int) $rec['tries'] >= RINGO_OTP_MAX_TRIES ) {
		delete_user_meta( $user_id, RINGO_OTP_META );
		return 'Too many wrong codes: request a new one.';
	}

	$expected = ringo_otp_hash( (string) $code, $user_id, ringo_otp_session() );
	if ( hash_equals( (string) $rec['hash'], $expected ) ) {
		delete_user_meta( $user_id, RINGO_OTP_META ); // Single use.
		return true;
	}

	$rec['tries'] = (int) $rec['tries'] + 1;
	if ( $rec['tries'] >= RINGO_OTP_MAX_TRIES ) {
		delete_user_meta( $user_id, RINGO_OTP_META );
		ringo_payment_alert( 'Payment settings code locked after ' . RINGO_OTP_MAX_TRIES . ' wrong tries', [ 'user' => ringo_otp_user_label( $user_id ) ] );
		return 'Too many wrong codes: the code is cancelled and an alert was sent.';
	}
	update_user_meta( $user_id, RINGO_OTP_META, $rec );
	return sprintf( 'Wrong code (%d of %d tries used).', $rec['tries'], RINGO_OTP_MAX_TRIES );
}

/**
 * admin-post.php?action=ringo_send_payment_otp: "Email me a code" link.
 */
function ringo_handle_send_payment_otp() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'ringo_send_payment_otp' );
	$result = ringo_otp_send( get_current_user_id() );
	$flag   = ( true === $result ) ? 'sent' : 'failed';
	set_transient( 'ringo_otp_notice_' . get_current_user_id(), ( true === $result ) ? 'A code was emailed to ' . ringo_mask_email( ringo_payment_otp_email() ) . '.' : $result, 60 );
	wp_safe_redirect( add_query_arg( 'ringo_otp', $flag, admin_url( 'admin.php?page=ringo-stripe-checkout' ) ) );
	exit;
}
add_action( 'admin_post_ringo_send_payment_otp', 'ringo_handle_send_payment_otp' );

// ─── Alert on every change ───────────────────────────────────────────────────

/**
 * Field names that differ between two settings arrays, with keys described as
 * "set, ends in ••••1234" / "cleared" at most. Never a value.
 *
 * @param array $old Old.
 * @param array $new New.
 * @return string[] Lines.
 */
function ringo_settings_change_lines( $old, $new ) {
	$old   = is_array( $old ) ? $old : [];
	$new   = is_array( $new ) ? $new : [];
	$keys  = array_keys( ringo_credential_constant_map() );
	$lines = [];

	$flat = static function ( $a, $prefix = '' ) use ( &$flat ) {
		$r = [];
		foreach ( $a as $k => $v ) {
			$name = '' === $prefix ? (string) $k : $prefix . '.' . $k;
			if ( is_array( $v ) ) {
				$r += $flat( $v, $name );
			} else {
				$r[ $name ] = (string) $v;
			}
		}
		return $r;
	};
	$o = $flat( $old );
	$n = $flat( $new );

	foreach ( array_unique( array_merge( array_keys( $o ), array_keys( $n ) ) ) as $name ) {
		$before = $o[ $name ] ?? '';
		$after  = $n[ $name ] ?? '';
		if ( $before === $after ) {
			continue;
		}
		if ( in_array( $name, $keys, true ) ) {
			$lines[] = '' === $after ? $name . ': cleared' : $name . ': set, ends in ' . ringo_mask_secret( $after );
		} else {
			$lines[] = $name;
		}
	}
	return $lines;
}

/**
 * Send an alert email to RINGO_PAYMENT_OTP_EMAIL (and log it, redacted).
 *
 * @param string $subject What happened.
 * @param array  $lines   Extra "label: text" lines (no values of secrets).
 */
function ringo_payment_alert( $subject, array $lines = [] ) {
	$site = ringo_site_host();
	$body = [ $subject . ' on ' . $site, '' ];
	foreach ( $lines as $k => $v ) {
		$body[] = is_int( $k ) ? (string) $v : $k . ': ' . $v;
	}
	$body[] = 'IP: ' . ringo_otp_ip();
	$body[] = 'Time: ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC';
	$body   = implode( "\n", $body );

	if ( function_exists( 'ringo_log' ) ) {
		ringo_log( 'Payment alert: ' . $subject, [ 'lines' => $lines ] );
	}
	$to = ringo_payment_otp_email();
	if ( '' !== $to ) {
		wp_mail( $to, '[' . $site . '] ' . $subject, ringo_redact_secrets( $body ) );
	}
}

/**
 * update_option / add_option hook for ringo_stripe_settings.
 *
 * @param mixed $old Old value.
 * @param mixed $new New value.
 */
function ringo_payment_settings_changed( $old, $new ) {
	$lines = ringo_settings_change_lines( $old, $new );
	if ( ! $lines ) {
		return;
	}
	ringo_payment_alert( 'Payment settings changed', array_merge(
		[ 'User' => ringo_otp_user_label( get_current_user_id() ), 'Changed fields' => '' ],
		array_map( static function ( $l ) { return '  - ' . $l; }, $lines )
	) );
}
add_action( 'update_option_ringo_stripe_settings', 'ringo_payment_settings_changed', 10, 2 );
add_action( 'add_option_ringo_stripe_settings', static function ( $option, $value ) {
	ringo_payment_settings_changed( [], $value );
}, 10, 2 );
