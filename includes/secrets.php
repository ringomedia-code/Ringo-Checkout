<?php
/**
 * Payment secret handling: constants first, staging safety, masking, log redaction.
 *
 * Why this file exists (2026-11-19): a staging copy of the live database held
 * the live Stripe and PayPal secrets, and the settings page printed them in
 * plain text. Malware on staging could read them. From here on:
 *
 *  1. Keys come from constants (wp-config.php, or a file outside the web root
 *     that wp-config includes) first. A constant always wins over the database.
 *  2. A site that is not production never uses live keys: Stripe is forced to
 *     test mode and PayPal to sandbox, and a live looking Stripe key is refused.
 *  3. The settings page never prints a stored secret (see admin/settings-page.php).
 *  4. Secrets are scrubbed from the plugin log.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constant map ────────────────────────────────────────────────────────────

/**
 * Settings field => constant that overrides it.
 *
 * @return array<string,string>
 */
function ringo_credential_constant_map() {
	return [
		'stripe_live_secret'       => 'RINGO_STRIPE_LIVE_SECRET',
		'stripe_test_secret'       => 'RINGO_STRIPE_TEST_SECRET',
		'stripe_live_publishable'  => 'RINGO_STRIPE_LIVE_PUBLISHABLE',
		'stripe_test_publishable'  => 'RINGO_STRIPE_TEST_PUBLISHABLE',
		'paypal_live_client_id'    => 'RINGO_PAYPAL_LIVE_CLIENT_ID',
		'paypal_live_secret'       => 'RINGO_PAYPAL_LIVE_SECRET',
		'paypal_sandbox_client_id' => 'RINGO_PAYPAL_SANDBOX_CLIENT_ID',
		'paypal_sandbox_secret'    => 'RINGO_PAYPAL_SANDBOX_SECRET',
	];
}

/**
 * Fields that are secrets: never printed, masked on the settings page.
 *
 * @return string[]
 */
function ringo_secret_fields() {
	return [ 'stripe_live_secret', 'stripe_test_secret', 'paypal_live_secret', 'paypal_sandbox_secret' ];
}

/**
 * Fields that belong to the live side (refused on a non production site).
 *
 * @return string[]
 */
function ringo_live_credential_fields() {
	return [ 'stripe_live_secret', 'stripe_live_publishable', 'paypal_live_client_id', 'paypal_live_secret' ];
}

/**
 * Name of the constant that is set for this field, or ''.
 *
 * @param string $field Settings field.
 * @return string
 */
function ringo_credential_constant_in_use( $field ) {
	$map = ringo_credential_constant_map();
	if ( empty( $map[ $field ] ) ) {
		return '';
	}
	$name = $map[ $field ];
	if ( defined( $name ) && is_string( constant( $name ) ) && '' !== trim( constant( $name ) ) ) {
		return $name;
	}
	return '';
}

/**
 * Raw credential lookup: constant first, then the database. No staging guard.
 *
 * @param string $field Settings field.
 * @return array{value:string,source:string} source is 'constant', 'db' or ''.
 */
function ringo_get_credential( $field ) {
	$const = ringo_credential_constant_in_use( $field );
	if ( $const ) {
		return [ 'value' => trim( (string) constant( $const ) ), 'source' => 'constant' ];
	}

	$stored = get_option( 'ringo_stripe_settings', [] );
	$value  = ( is_array( $stored ) && isset( $stored[ $field ] ) ) ? trim( (string) $stored[ $field ] ) : '';

	return [ 'value' => $value, 'source' => ( '' !== $value ) ? 'db' : '' ];
}

// ─── Production detection ────────────────────────────────────────────────────

/**
 * Host name of this site, lower case, from home_url() (stored in the DB, not
 * from the request Host header, so a visitor cannot change it).
 *
 * @return string
 */
function ringo_site_host() {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	return strtolower( (string) $host );
}

/**
 * Production host allowlist.
 *
 * Set RINGO_PRODUCTION_HOSTS in wp-config ('example.com,www.example.com') or
 * use the 'ringo_production_hosts' filter. When the list is not empty, ONLY
 * those hosts count as production. When empty, the environment type and host
 * name checks below decide.
 *
 * @return string[]
 */
function ringo_production_hosts() {
	$hosts = [];
	if ( defined( 'RINGO_PRODUCTION_HOSTS' ) ) {
		$raw   = is_array( RINGO_PRODUCTION_HOSTS ) ? RINGO_PRODUCTION_HOSTS : explode( ',', (string) RINGO_PRODUCTION_HOSTS );
		$hosts = $raw;
	}
	$hosts = (array) apply_filters( 'ringo_production_hosts', $hosts );
	$hosts = array_map( static function ( $h ) {
		return strtolower( trim( (string) $h ) );
	}, $hosts );
	return array_values( array_filter( $hosts ) );
}

/**
 * Why this site is NOT production, or '' when it is production.
 *
 * @return string Human readable reason.
 */
function ringo_non_production_reason() {
	$host = ringo_site_host();

	$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	if ( 'production' !== $env ) {
		return sprintf( 'WordPress environment type is "%s"', $env );
	}

	$allow = ringo_production_hosts();
	if ( $allow ) {
		if ( ! in_array( $host, $allow, true ) ) {
			return sprintf( 'host "%s" is not in the production host list', $host );
		}
		return '';
	}

	$markers = (array) apply_filters( 'ringo_non_production_host_markers', [ 'staging', 'localhost', '.local', '.test' ] );
	foreach ( $markers as $marker ) {
		$marker = strtolower( (string) $marker );
		if ( '' !== $marker && false !== strpos( $host, $marker ) ) {
			return sprintf( 'host "%s" contains "%s"', $host, $marker );
		}
	}

	return '';
}

/**
 * TRUE when live keys may be used on this site.
 *
 * Final say: the 'ringo_is_production_site' filter.
 *
 * @return bool
 */
function ringo_is_production_site() {
	$is_prod = ( '' === ringo_non_production_reason() );
	return (bool) apply_filters( 'ringo_is_production_site', $is_prod );
}

/**
 * TRUE when a Stripe key string is a live key (secret, restricted or publishable).
 *
 * @param string $key Key.
 * @return bool
 */
function ringo_is_live_stripe_key( $key ) {
	return (bool) preg_match( '/^(sk|rk|pk)_live_/', (string) $key );
}

// ─── Effective modes ─────────────────────────────────────────────────────────

/**
 * Stripe mode actually used: the saved mode, but always 'test' off production.
 *
 * @return string 'live' | 'test'
 */
function ringo_get_effective_stripe_mode() {
	$s = ringo_get_settings();
	if ( 'live' === $s['mode'] && ringo_is_production_site() ) {
		return 'live';
	}
	return 'test';
}

/**
 * PayPal mode actually used: the saved mode, but always 'sandbox' off production.
 *
 * @return string 'live' | 'sandbox'
 */
function ringo_get_effective_paypal_mode() {
	$s = ringo_get_settings();
	if ( 'live' === $s['paypal_mode'] && ringo_is_production_site() ) {
		return 'live';
	}
	return 'sandbox';
}

/**
 * Credential value for use in an API call, with the staging guard applied.
 *
 * Off production: live fields return '', and any Stripe value that looks live
 * (someone pasted a live key into the test field) returns ''.
 *
 * @param string $field Settings field.
 * @return string
 */
function ringo_get_usable_credential( $field ) {
	$c = ringo_get_credential( $field );
	if ( '' === $c['value'] ) {
		return '';
	}
	if ( ! ringo_is_production_site() ) {
		if ( in_array( $field, ringo_live_credential_fields(), true ) ) {
			return '';
		}
		if ( 0 === strpos( $field, 'stripe_' ) && ringo_is_live_stripe_key( $c['value'] ) ) {
			return '';
		}
	}
	return $c['value'];
}

// ─── Masking and redaction ───────────────────────────────────────────────────

/**
 * "Saved, ends in ••••1234" style hint. Never more than the last 4 characters,
 * and nothing at all for very short values.
 *
 * @param string $value Secret.
 * @return string
 */
function ringo_mask_secret( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return '';
	}
	if ( strlen( $value ) < 12 ) {
		return '••••';
	}
	return '••••' . substr( $value, -4 );
}

/**
 * Remove secrets from a string or (recursively) an array/object before it is
 * logged or emailed.
 *
 * Catches Stripe secret/restricted keys, PaymentIntent client secrets,
 * Authorization header values, and the exact values of every configured
 * secret (PayPal secrets have no recognisable prefix).
 *
 * @param mixed $data Anything.
 * @return mixed Same shape, secrets replaced with [redacted].
 */
function ringo_redact_secrets( $data ) {
	if ( is_array( $data ) ) {
		$out = [];
		foreach ( $data as $k => $v ) {
			$key_l = strtolower( (string) $k );
			if ( in_array( $key_l, [ 'secret', 'client_secret', 'authorization', 'api_key', 'access_token' ], true ) || in_array( (string) $k, ringo_secret_fields(), true ) ) {
				$out[ $k ] = '[redacted]';
			} else {
				$out[ $k ] = ringo_redact_secrets( $v );
			}
		}
		return $out;
	}
	if ( is_object( $data ) ) {
		return ringo_redact_secrets( json_decode( wp_json_encode( $data ), true ) );
	}
	if ( ! is_string( $data ) || '' === $data ) {
		return $data;
	}

	static $known = null;
	if ( null === $known ) {
		$known = [];
		foreach ( ringo_secret_fields() as $field ) {
			$c = ringo_get_credential( $field );
			if ( strlen( $c['value'] ) >= 8 ) {
				$known[] = $c['value'];
			}
		}
		if ( defined( 'RINGO_STRIPE_SECRET_KEY' ) && is_string( RINGO_STRIPE_SECRET_KEY ) && strlen( RINGO_STRIPE_SECRET_KEY ) >= 8 ) {
			$known[] = RINGO_STRIPE_SECRET_KEY;
		}
	}
	if ( $known ) {
		$data = str_replace( $known, '[redacted]', $data );
	}

	$patterns = [
		'/\b(sk|rk)_(live|test)_[A-Za-z0-9]+/',      // Stripe secret / restricted keys.
		'/\bpi_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/',   // PaymentIntent client secret.
		'/\b(Bearer|Basic)\s+[A-Za-z0-9\-\._~\+\/]{16,}=*/', // Authorization header values.
	];
	return preg_replace( $patterns, '[redacted]', $data );
}

// ─── Autoload off ────────────────────────────────────────────────────────────

/**
 * Keep ringo_stripe_settings out of the autoloaded options (so secrets are not
 * loaded into memory and the object cache on every request).
 *
 * Runs on admin_init; costs nothing once the option is no longer autoloaded,
 * because wp_load_alloptions() is already in memory.
 */
function ringo_settings_autoload_off() {
	$all = wp_load_alloptions();
	if ( ! isset( $all['ringo_stripe_settings'] ) ) {
		return;
	}
	if ( function_exists( 'wp_set_option_autoload' ) ) { // WP 6.4+.
		wp_set_option_autoload( 'ringo_stripe_settings', false );
		return;
	}
	global $wpdb;
	$wpdb->update( $wpdb->options, [ 'autoload' => 'no' ], [ 'option_name' => 'ringo_stripe_settings' ] );
	wp_cache_delete( 'alloptions', 'options' );
}
add_action( 'admin_init', 'ringo_settings_autoload_off' );

// ─── Admin notices ───────────────────────────────────────────────────────────

/**
 * Off production: say that test/sandbox is forced, and warn loudly when live
 * secrets are still stored in this site's database.
 */
function ringo_payment_safety_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || ringo_is_production_site() ) {
		return;
	}

	$reason = ringo_non_production_reason();
	if ( '' === $reason ) {
		$reason = 'the ringo_is_production_site filter';
	}

	echo '<div class="notice notice-warning"><p><strong>Ringo Checkout:</strong> this site is not production (';
	echo esc_html( $reason );
	echo '). Live Stripe and PayPal keys are disabled here; Stripe runs in Test mode and PayPal in Sandbox, whatever the settings say.</p></div>';

	$live_in_db = [];
	foreach ( [ 'stripe_live_secret', 'paypal_live_secret' ] as $field ) {
		$c = ringo_get_credential( $field );
		if ( 'db' === $c['source'] ) {
			$live_in_db[] = $field;
		}
	}
	foreach ( [ 'stripe_test_secret' ] as $field ) {
		$c = ringo_get_credential( $field );
		if ( 'db' === $c['source'] && ringo_is_live_stripe_key( $c['value'] ) ) {
			$live_in_db[] = $field . ' (holds a live key)';
		}
	}
	if ( $live_in_db ) {
		echo '<div class="notice notice-error"><p><strong>Ringo Checkout:</strong> live payment secrets are stored in this non production database (';
		echo esc_html( implode( ', ', $live_in_db ) );
		echo '). They are not used here, but anyone who can read this database can read them. ';
		printf(
			'<a href="%s">Clear them on the Settings page</a> (tick "Clear this saved key" on each Live row, enter the email code, save).</p></div>',
			esc_url( admin_url( 'admin.php?page=ringo-stripe-checkout' ) )
		);
	}
}
add_action( 'admin_notices', 'ringo_payment_safety_admin_notice' );
