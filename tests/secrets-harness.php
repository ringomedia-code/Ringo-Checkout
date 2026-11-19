<?php
/**
 * Standalone test harness for the payment secret hardening (includes/secrets.php).
 *
 * No WordPress needed: minimal stubs below. Each scenario runs in its own PHP
 * process because constants cannot be undefined.
 *
 *   php tests/secrets-harness.php          # run all scenarios, exit 1 on failure
 *   php tests/secrets-harness.php <name>   # run one scenario
 *   php tests/secrets-harness.php --list   # scenario names
 *   tests/run-secrets-harness.sh           # same as no argument, for PHP builds that
 *                                          # cannot spawn themselves (e.g. php-wasm):
 *                                          # PHP_CMD="npx @php-wasm/cli@3.1.58" tests/run-secrets-harness.sh
 *
 * Fake keys are built at runtime ('sk_' . 'live_' ...) so no key-shaped string
 * sits in the repository.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$ROOT = dirname( __DIR__ ) . '/';

// ─── Runner ──────────────────────────────────────────────────────────────────

$scenarios = [
	'prod_db_keys', 'staging_host_forces_test', 'env_type_forces_test', 'allowlist',
	'filter_override', 'constants_win', 'legacy_constant', 'settings_page_never_prints',
	'settings_page_locked', 'sanitize_rules', 'sanitize_non_production', 'redaction',
	'logger_redacts', 'staging_notice', 'autoload_off',
	'otp_no_constant', 'otp_blocks_and_sends', 'otp_valid_code', 'otp_single_use', 'otp_wrong_tries',
	'otp_expired', 'otp_session_bound', 'otp_rate_limit', 'otp_protected_fields', 'otp_page_field',
	'alert_email', 'alert_add_option',
];

if ( isset( $argv[1] ) && '--list' === $argv[1] ) {
	echo implode( "\n", $scenarios ) . "\n";
	exit( 0 );
}

if ( PHP_SAPI === 'cli' && ! isset( $argv[1] ) ) {
	$fail = 0;
	foreach ( $scenarios as $name ) {
		$out  = [];
		$code = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $name ) . ' 2>&1', $out, $code );
		echo implode( "\n", $out ) . "\n";
		if ( 0 !== $code ) {
			$fail++;
			echo "!! scenario {$name} FAILED (exit {$code})\n";
		}
	}
	echo $fail ? "\nFAILED: {$fail} scenario(s)\n" : "\nALL SCENARIOS PASSED\n";
	exit( $fail ? 1 : 0 );
}

$SCENARIO = $argv[1];

// ─── Fake keys ───────────────────────────────────────────────────────────────

function fake( $prefix, $tail ) {
	return $prefix . str_pad( $tail, 24, 'x' );
}
$K = [
	'sk_live' => fake( 'sk_' . 'live_', 'LIVEsecret1111' ) . 'L1VE',
	'sk_test' => fake( 'sk_' . 'test_', 'TESTsecret2222' ) . 'T3ST',
	'pk_live' => fake( 'pk_' . 'live_', 'LIVEpub' ),
	'pk_test' => fake( 'pk_' . 'test_', 'TESTpub' ),
	'pp_live_id'  => 'PPLIVECLIENTID' . str_repeat( 'a', 20 ),
	'pp_live_sec' => 'PPLIVESECRET' . str_repeat( 'b', 30 ) . 'PPL1',
	'pp_sbx_id'   => 'PPSBXCLIENTID' . str_repeat( 'c', 20 ),
	'pp_sbx_sec'  => 'PPSBXSECRET' . str_repeat( 'd', 30 ) . 'PPS1',
	'const_live'  => fake( 'rk_' . 'live_', 'CONSTANTlive' ) . 'C0NS',
];

// ─── WordPress stubs ─────────────────────────────────────────────────────────

define( 'ABSPATH', '/tmp/' );
define( 'RINGO_CHECKOUT_DIR', $ROOT );

$GLOBALS['W'] = [
	'options'   => [],
	'autoload'  => [ 'ringo_stripe_settings' => true ],
	'home'      => 'https://bassboat4sale.example',
	'env'       => 'production',
	'filters'   => [],
	'errors'    => [],
	'can'       => true,
	'autoload_calls' => 0,
];

function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['W']['options'] ) ? $GLOBALS['W']['options'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['W']['options'][ $k ] = $v; return true; }
function home_url() { return $GLOBALS['W']['home']; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function wp_get_environment_type() { return $GLOBALS['W']['env']; }
function add_filter( $tag, $cb ) { $GLOBALS['W']['filters'][ $tag ][] = $cb; }
function apply_filters( $tag, $v ) {
	$args = func_get_args();
	foreach ( $GLOBALS['W']['filters'][ $tag ] ?? [] as $cb ) {
		$args[1] = $cb( ...array_slice( $args, 1 ) );
	}
	return $args[1];
}
function add_action() {}
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return (string) $s; }
function wp_parse_args( $a, $d ) { return array_merge( $d, (array) $a ); }
function add_settings_error( $s, $c, $m ) { $GLOBALS['W']['errors'][] = $m; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function current_time( $t ) { return '2026-11-19 12:00:00'; }
function current_user_can( $c ) { return $GLOBALS['W']['can']; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses( $s ) { return $s; }
function selected( $a, $b ) { if ( $a === $b ) { echo ' selected="selected"'; } }
function wp_load_alloptions() { $o = []; foreach ( $GLOBALS['W']['autoload'] as $k => $on ) { if ( $on ) { $o[ $k ] = 1; } } return $o; }
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['W'] += [ 'mail' => [], 'umeta' => [], 'transients' => [], 'user' => 1, 'session' => 'sess-A' ];
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function get_current_user_id() { return $GLOBALS['W']['user']; }
function get_userdata( $id ) { return (object) [ 'user_login' => 'admin' . $id, 'user_email' => 'admin' . $id . '@example.test' ]; }
function wp_get_session_token() { return $GLOBALS['W']['session']; }
function wp_salt( $s ) { return 'test-salt'; }
function get_user_meta( $u, $k, $single ) { return $GLOBALS['W']['umeta'][ $u ][ $k ] ?? ''; }
function update_user_meta( $u, $k, $v ) { $GLOBALS['W']['umeta'][ $u ][ $k ] = $v; return true; }
function delete_user_meta( $u, $k ) { unset( $GLOBALS['W']['umeta'][ $u ][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['W']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['W']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['W']['transients'][ $k ] ); return true; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['W']['mail'][] = [ 'to' => $to, 'subject' => $subject, 'body' => $body ]; return true; }
function admin_url( $p = '' ) { return 'https://site.example/wp-admin/' . $p; }
function wp_nonce_url( $u, $a ) { return $u . '&_wpnonce=n'; }
function esc_url( $u ) { return $u; }
function add_query_arg( $k, $v, $u ) { return $u . '&' . $k . '=' . $v; }
function wp_set_option_autoload( $k, $on ) { $GLOBALS['W']['autoload'][ $k ] = (bool) $on; $GLOBALS['W']['autoload_calls']++; return true; }

// ─── Assertions ──────────────────────────────────────────────────────────────

$PASS = 0;
function ok( $cond, $label ) {
	global $PASS, $SCENARIO;
	if ( ! $cond ) {
		fwrite( STDOUT, "  FAIL [{$SCENARIO}] {$label}\n" );
		exit( 1 );
	}
	$PASS++;
}
function same( $a, $b, $label ) { ok( $a === $b, $label . ' (got ' . var_export( $a === '' ? '' : ( is_string( $a ) ? 'len' . strlen( $a ) . ':' . substr( $a, 0, 3 ) . '…' : $a ), true ) . ')' ); }
function no_secret_in( $html, $label ) {
	global $K;
	foreach ( [ 'sk_live', 'sk_test', 'pp_live_sec', 'pp_sbx_sec', 'const_live' ] as $k ) {
		ok( false === strpos( $html, $K[ $k ] ), "{$label}: {$k} not in output" );
	}
}
function db( array $vals ) {
	$GLOBALS['W']['options']['ringo_stripe_settings'] = $vals;
}
function full_db( $mode = 'live', $pp = 'live' ) {
	global $K;
	db( [
		'mode' => $mode, 'paypal_mode' => $pp,
		'stripe_live_secret' => $K['sk_live'], 'stripe_test_secret' => $K['sk_test'],
		'stripe_live_publishable' => $K['pk_live'], 'stripe_test_publishable' => $K['pk_test'],
		'paypal_live_client_id' => $K['pp_live_id'], 'paypal_live_secret' => $K['pp_live_sec'],
		'paypal_sandbox_client_id' => $K['pp_sbx_id'], 'paypal_sandbox_secret' => $K['pp_sbx_sec'],
	] );
}
function load_plugin() {
	global $ROOT;
	require_once $ROOT . 'includes/helpers.php';
	require_once $ROOT . 'includes/settings.php';
	require_once $ROOT . 'includes/secrets.php';
	require_once $ROOT . 'includes/payment-otp.php';
	require_once $ROOT . 'includes/stripe.php';
	require_once $ROOT . 'includes/paypal.php';
	require_once $ROOT . 'admin/settings-page.php';
}
function capture( callable $fn ) { ob_start(); $fn(); return ob_get_clean(); }
const OTP_TO = 'payments-owner@example.test';
function last_mail() { $m = $GLOBALS['W']['mail']; return $m ? end( $m ) : null; }
function code_from_mail() {
	$m = last_mail();
	return ( $m && preg_match( '/^\s+(\d{6})$/m', $m['body'], $x ) ) ? $x[1] : '';
}
/** Ask for a code like the "Email me a code" button and return it from the captured email. */
function code() {
	$r = ringo_otp_send( get_current_user_id() );
	ok( true === $r, 'code sent' );
	return code_from_mail();
}
function all_mail_text() { return json_encode( $GLOBALS['W']['mail'] ); }

// ─── Scenarios ───────────────────────────────────────────────────────────────

switch ( $SCENARIO ) {

	case 'prod_db_keys': // Production host, keys in DB: behaves as before.
		load_plugin();
		full_db();
		ok( ringo_is_production_site(), 'production detected' );
		same( ringo_get_active_stripe_secret(), $K['sk_live'], 'live Stripe secret used' );
		same( ringo_get_active_stripe_publishable(), $K['pk_live'], 'live publishable used' );
		$c = ringo_get_paypal_active_credentials();
		same( $c['mode'], 'live', 'PayPal live' );
		same( $c['secret'], $K['pp_live_sec'], 'PayPal live secret' );
		full_db( 'test', 'sandbox' );
		same( ringo_get_active_stripe_secret(), $K['sk_test'], 'test mode still uses test key' );
		same( ringo_get_paypal_active_credentials()['api_base'], 'https://api-m.sandbox.paypal.com', 'sandbox base' );
		break;

	case 'staging_host_forces_test': // The 11-16 incident: live DB copied to staging4.
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		load_plugin();
		full_db();
		ok( ! ringo_is_production_site(), 'staging host is not production' );
		ok( false !== strpos( ringo_non_production_reason(), 'staging' ), 'reason names staging' );
		same( ringo_get_effective_stripe_mode(), 'test', 'Stripe forced to test' );
		same( ringo_get_active_stripe_secret(), $K['sk_test'], 'test secret used, never live' );
		same( ringo_get_active_stripe_publishable(), $K['pk_test'], 'test publishable used' );
		$c = ringo_get_paypal_active_credentials();
		same( $c['mode'], 'sandbox', 'PayPal forced to sandbox' );
		same( $c['secret'], $K['pp_sbx_sec'], 'sandbox secret used' );
		same( ringo_get_usable_credential( 'stripe_live_secret' ), '', 'live Stripe secret unusable' );
		same( ringo_get_usable_credential( 'paypal_live_secret' ), '', 'live PayPal secret unusable' );
		// Live key pasted into the TEST field is refused too.
		$o = get_option( 'ringo_stripe_settings' );
		$o['stripe_test_secret'] = $K['sk_live'];
		db( $o );
		same( ringo_get_active_stripe_secret(), '', 'live key in test field refused off production' );
		break;

	case 'env_type_forces_test':
		$GLOBALS['W']['env'] = 'staging';
		load_plugin();
		full_db();
		ok( ! ringo_is_production_site(), 'WP_ENVIRONMENT_TYPE=staging is not production' );
		same( ringo_get_active_stripe_secret(), $K['sk_test'], 'test secret' );
		break;

	case 'allowlist':
		define( 'RINGO_PRODUCTION_HOSTS', 'bassboat4sale.example, www.bassboat4sale.example' );
		$GLOBALS['W']['home'] = 'https://copy.bassboat4sale.example'; // no "staging" in the name.
		load_plugin();
		full_db();
		ok( ! ringo_is_production_site(), 'host outside allowlist is not production' );
		same( ringo_get_active_stripe_secret(), $K['sk_test'], 'test key off allowlist' );
		$GLOBALS['W']['home'] = 'https://www.bassboat4sale.example';
		ok( ringo_is_production_site(), 'allowlisted host is production' );
		same( ringo_get_active_stripe_secret(), $K['sk_live'], 'live key on allowlisted host' );
		add_filter( 'ringo_production_hosts', function ( $h ) { $h[] = 'copy.bassboat4sale.example'; return $h; } );
		$GLOBALS['W']['home'] = 'https://copy.bassboat4sale.example';
		ok( ringo_is_production_site(), 'ringo_production_hosts filter adds a host' );
		break;

	case 'filter_override':
		load_plugin();
		full_db();
		add_filter( 'ringo_is_production_site', '__return_false_x' );
		function __return_false_x() { return false; }
		same( ringo_get_active_stripe_secret(), $K['sk_test'], 'filter can force non production' );
		break;

	case 'constants_win':
		define( 'RINGO_STRIPE_LIVE_SECRET', $K['const_live'] );
		define( 'RINGO_PAYPAL_LIVE_SECRET', 'CONSTPAYPALSECRET' . str_repeat( 'z', 20 ) );
		load_plugin();
		full_db();
		same( ringo_get_active_stripe_secret(), $K['const_live'], 'constant wins over DB' );
		same( ringo_get_credential( 'stripe_live_secret' )['source'], 'constant', 'source = constant' );
		same( ringo_get_paypal_active_credentials()['secret'], RINGO_PAYPAL_LIVE_SECRET, 'PayPal constant wins' );
		same( ringo_get_paypal_active_credentials()['client_id'], $K['pp_live_id'], 'client id still from DB when no constant' );
		db( [ 'mode' => 'live', 'paypal_mode' => 'live' ] );
		same( ringo_get_active_stripe_secret(), $K['const_live'], 'constant works with empty DB' );
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		same( ringo_get_active_stripe_secret(), '', 'staging: no live CONSTANT used' );
		same( ringo_get_usable_credential( 'stripe_live_secret' ), '', 'live CONSTANT unusable off production' );
		break;

	case 'legacy_constant':
		define( 'RINGO_STRIPE_SECRET_KEY', $K['sk_live'] );
		load_plugin();
		db( [ 'mode' => 'live' ] );
		same( ringo_get_active_stripe_secret(), $K['sk_live'], 'legacy constant still works on production' );
		full_db();
		same( ringo_get_active_stripe_secret(), $K['sk_live'], 'DB live key still preferred over legacy (unchanged order)' );
		db( [ 'mode' => 'live' ] );
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		same( ringo_get_active_stripe_secret(), '', 'live legacy constant refused off production' );
		break;

	case 'settings_page_never_prints':
		load_plugin();
		full_db();
		$html = capture( function () {
			foreach ( [ 'ringo_field_test_key', 'ringo_field_live_key', 'ringo_field_test_pub', 'ringo_field_live_pub',
				'ringo_field_paypal_sandbox_client', 'ringo_field_paypal_sandbox_secret',
				'ringo_field_paypal_live_client', 'ringo_field_paypal_live_secret' ] as $f ) {
				$f();
			}
		} );
		no_secret_in( $html, 'settings page' );
		same( substr_count( $html, 'type="password"' ), 4, 'four secret inputs are password type' );
		same( substr_count( $html, 'type="password" autocomplete="new-password" name="ringo_stripe_settings[stripe_live_secret]" value=""' ), 1, 'live secret value is empty' );
		ok( false !== strpos( $html, 'Saved, ends in ••••L1VE' ), 'shows last 4 of live Stripe secret' );
		ok( false !== strpos( $html, 'Saved, ends in ••••PPL1' ), 'shows last 4 of live PayPal secret' );
		same( substr_count( $html, '[clear][' ), 4, 'clear checkbox for each saved secret' );
		ok( false !== strpos( $html, $K['pp_live_id'] ), 'public client id still shown' );
		db( [] );
		$html = capture( 'ringo_field_live_key' );
		ok( false !== strpos( $html, 'Not set.' ) && false === strpos( $html, '[clear]' ), 'unset secret: "Not set", no clear box' );
		break;

	case 'settings_page_locked':
		define( 'RINGO_STRIPE_LIVE_SECRET', $K['const_live'] );
		define( 'RINGO_PAYPAL_LIVE_CLIENT_ID', 'CONSTCLIENT' );
		load_plugin();
		full_db();
		$html = capture( 'ringo_field_live_key' );
		no_secret_in( $html, 'locked field' );
		ok( false !== strpos( $html, 'Set in wp-config (locked)' ), 'locked note' );
		ok( false !== strpos( $html, 'disabled="disabled"' ), 'input disabled' );
		ok( false !== strpos( $html, 'RINGO_STRIPE_LIVE_SECRET' ), 'names the constant' );
		ok( false === strpos( $html, '[clear]' ), 'no clear box for a constant' );
		$html = capture( 'ringo_field_paypal_live_client' );
		ok( false !== strpos( $html, 'CONSTCLIENT' ) && false !== strpos( $html, 'disabled' ), 'constant client id shown locked' );
		break;

	case 'sanitize_rules':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		full_db();
		// Empty secret fields = keep. Public fields are what was submitted.
		$in  = [ 'mode' => 'live', 'paypal_mode' => 'live',
			'stripe_live_secret' => '', 'stripe_test_secret' => '', 'paypal_live_secret' => '', 'paypal_sandbox_secret' => '',
			'stripe_live_publishable' => $K['pk_live'], 'stripe_test_publishable' => $K['pk_test'],
			'paypal_live_client_id' => $K['pp_live_id'], 'paypal_sandbox_client_id' => $K['pp_sbx_id'] ];
		$out = ringo_sanitize_settings( $in );
		same( $out['stripe_live_secret'], $K['sk_live'], 'empty keeps live Stripe secret' );
		same( $out['paypal_live_secret'], $K['pp_live_sec'], 'empty keeps live PayPal secret' );
		same( $out['stripe_test_secret'], $K['sk_test'], 'empty keeps test secret' );
		ok( ! isset( $out['clear'] ), 'clear flags are not stored' );
		// New value replaces.
		$new = fake( 'rk_' . 'live_', 'NEWkey' );
		$out = ringo_sanitize_settings( array_merge( $in, [ 'stripe_live_secret' => '  ' . $new . ' ', 'otp_code' => code() ] ) );
		same( $out['stripe_live_secret'], $new, 'new value saved (trimmed)' );
		// Clear.
		$out = ringo_sanitize_settings( array_merge( $in, [ 'clear' => [ 'stripe_live_secret' => '1', 'paypal_live_secret' => '1' ] ] ) );
		same( $out['stripe_live_secret'], '', 'clear blanks Stripe live' );
		same( $out['paypal_live_secret'], '', 'clear blanks PayPal live' );
		same( $out['paypal_sandbox_secret'], $K['pp_sbx_sec'], 'clear touches only ticked fields' );
		// WordPress runs the sanitize callback twice on first save: second pass is stable.
		$GLOBALS['W']['options'] = [];
		$first  = ringo_sanitize_settings( array_merge( $in, [ 'stripe_live_secret' => $new ] ) );
		$second = ringo_sanitize_settings( $first );
		same( $second['stripe_live_secret'], $new, 'double sanitize on first save keeps the key' );
		break;

	case 'sanitize_non_production':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		load_plugin();
		db( [ 'mode' => 'test' ] );
		$in  = [ 'mode' => 'live', 'stripe_live_secret' => $K['sk_live'], 'stripe_test_secret' => $K['sk_live'],
			'paypal_live_secret' => $K['pp_live_sec'], 'paypal_live_client_id' => $K['pp_live_id'],
			'paypal_sandbox_secret' => $K['pp_sbx_sec'], 'otp_code' => code() ];
		$out = ringo_sanitize_settings( $in );
		same( $out['stripe_live_secret'], '', 'new live Stripe secret not saved on staging' );
		same( $out['stripe_test_secret'], '', 'live key typed into test field not saved on staging' );
		same( $out['paypal_live_secret'], '', 'new live PayPal secret not saved on staging' );
		same( $out['paypal_live_client_id'], '', 'new live PayPal client id not saved on staging' );
		same( $out['paypal_sandbox_secret'], $K['pp_sbx_sec'], 'sandbox secret saved on staging' );
		ok( count( $GLOBALS['W']['errors'] ) === 4, 'admin told why (4 refusals)' );
		no_secret_in( implode( ' ', $GLOBALS['W']['errors'] ), 'refusal messages' );
		// Existing live secret (copied DB) is not silently wiped, but can be cleared.
		full_db();
		$out = ringo_sanitize_settings( [ 'stripe_live_secret' => '', 'paypal_live_client_id' => $K['pp_live_id'] ] );
		same( $out['stripe_live_secret'], $K['sk_live'], 'existing copied secret kept until cleared' );
		same( $out['paypal_live_client_id'], $K['pp_live_id'], 'unchanged live client id resubmitted is kept' );
		$out = ringo_sanitize_settings( [ 'clear' => [ 'stripe_live_secret' => 1 ] ] );
		same( $out['stripe_live_secret'], '', 'clear works on staging' );
		break;

	case 'redaction':
		load_plugin();
		full_db();
		$pi = 'pi_' . 'ABC123' . '_secret_' . 'XYZ789';
		$s  = "Invalid key {$K['sk_live']} and {$K['sk_test']} client {$pi} auth Bearer FAKEtokenFAKEtokenFAKE and pp {$K['pp_live_sec']}";
		$r  = ringo_redact_secrets( $s );
		no_secret_in( $r, 'redacted string' );
		ok( false === strpos( $r, $pi ), 'client secret redacted' );
		ok( false === strpos( $r, 'FAKEtokenFAKEtokenFAKE' ), 'bearer token redacted' );
		ok( false !== strpos( $r, 'Invalid key [redacted]' ), 'message text kept' );
		same( ringo_redact_secrets( 'Basic package for boat 12' ), 'Basic package for boat 12', 'plain English "Basic" untouched' );
		$a = ringo_redact_secrets( [ 'post_id' => 7, 'secret' => 'abc', 'nested' => [ 'err' => $K['sk_live'], 'client_secret' => 'x' ], 'stripe_live_secret' => 'y' ] );
		same( $a['post_id'], 7, 'non secret value kept' );
		same( $a['secret'], '[redacted]', 'key named secret redacted' );
		same( $a['nested']['client_secret'], '[redacted]', 'nested client_secret redacted' );
		same( $a['nested']['err'], '[redacted]', 'nested key value redacted' );
		same( $a['stripe_live_secret'], '[redacted]', 'settings field name redacted' );
		break;

	case 'logger_redacts':
		load_plugin();
		full_db();
		ringo_log( 'Stripe failed: ' . $K['sk_live'], [ 'creds' => ringo_get_paypal_active_credentials() ] );
		$logs = get_option( 'ringo_boat_logs' );
		$dump = json_encode( $logs );
		no_secret_in( $dump, 'boat log' );
		ok( false !== strpos( $dump, 'Stripe failed: [redacted]' ), 'log line kept, key removed' );
		break;

	case 'staging_notice':
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		load_plugin();
		full_db();
		$html = capture( 'ringo_payment_safety_admin_notice' );
		ok( false !== strpos( $html, 'not production' ), 'notice shown on staging' );
		ok( false !== strpos( $html, 'stripe_live_secret' ) && false !== strpos( $html, 'paypal_live_secret' ), 'names live secrets stored in DB' );
		no_secret_in( $html, 'notice' );
		$GLOBALS['W']['can'] = false;
		same( capture( 'ringo_payment_safety_admin_notice' ), '', 'hidden from non admins' );
		$GLOBALS['W']['can']  = true;
		$GLOBALS['W']['home'] = 'https://bassboat4sale.example';
		same( capture( 'ringo_payment_safety_admin_notice' ), '', 'no notice on production' );
		break;

	case 'autoload_off':
		load_plugin();
		ringo_settings_autoload_off();
		same( $GLOBALS['W']['autoload']['ringo_stripe_settings'], false, 'autoload turned off' );
		ringo_settings_autoload_off();
		same( $GLOBALS['W']['autoload_calls'], 1, 'runs once, then no-op' );
		break;

	case 'otp_no_constant': // No RINGO_PAYMENT_OTP_EMAIL: key/mode changes blocked, other fields save.
		load_plugin();
		full_db( 'test', 'sandbox' );
		$out = ringo_sanitize_settings( [ 'mode' => 'live', 'stripe_live_secret' => fake( 'rk_' . 'live_', 'NEW' ), 'prices' => [ 'vip' => 300 ] ] );
		same( $out['mode'], 'test', 'mode change blocked' );
		same( $out['stripe_live_secret'], $K['sk_live'], 'key change blocked' );
		same( $out['prices']['vip'], 300.0, 'price still saved' );
		ok( false !== strpos( implode( ' ', $GLOBALS['W']['errors'] ), 'define RINGO_PAYMENT_OTP_EMAIL' ), 'clear notice' );
		same( count( $GLOBALS['W']['mail'] ), 0, 'no email sent anywhere' );
		ok( true !== ringo_otp_send( 1 ), 'send refused without constant' );
		ok( false !== strpos( capture( 'ringo_field_otp_code' ), 'Locked' ), 'settings page says locked' );
		break;

	case 'otp_blocks_and_sends':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		full_db();
		$new = fake( 'rk_' . 'live_', 'NEWKEY' );
		$out = ringo_sanitize_settings( [ 'mode' => 'live', 'paypal_mode' => 'live', 'stripe_live_secret' => $new, 'abandoned_after' => 999 ] );
		same( $out['stripe_live_secret'], $K['sk_live'], 'key kept without code' );
		same( $out['abandoned_after'], 999, 'timer saved without code' );
		same( count( $GLOBALS['W']['mail'] ), 1, 'one code email' );
		same( last_mail()['to'], OTP_TO, 'sent to the constant address only' );
		ok( 6 === strlen( code_from_mail() ), '6 digit code in email' );
		ok( false !== strpos( last_mail()['body'], 'stripe_live_secret' ), 'email names the field' );
		ok( false === strpos( all_mail_text(), $new ), 'new key never in email' );
		no_secret_in( all_mail_text(), 'code email' );
		$rec = get_user_meta( 1, RINGO_OTP_META, true );
		ok( is_array( $rec ) && 64 === strlen( $rec['hash'] ) && false === strpos( json_encode( $rec ), code_from_mail() ), 'stored hashed, not the code' );
		ok( false !== strpos( implode( ' ', $GLOBALS['W']['errors'] ), 'p•••@example.test' ), 'notice shows masked address' );
		ok( false === strpos( implode( ' ', $GLOBALS['W']['errors'] ), OTP_TO ), 'notice never shows the full address' );
		break;

	case 'otp_valid_code':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		full_db( 'test', 'sandbox' );
		$c   = code();
		$new = fake( 'rk_' . 'live_', 'NEWKEY' );
		$out = ringo_sanitize_settings( [ 'mode' => 'live', 'paypal_mode' => 'sandbox', 'stripe_live_secret' => $new, 'otp_code' => $c ] );
		same( $out['mode'], 'live', 'mode change saved with code' );
		same( $out['stripe_live_secret'], $new, 'key saved with code' );
		ok( ! isset( $out['otp_code'] ), 'code not stored in the option' );
		same( get_user_meta( 1, RINGO_OTP_META, true ), '', 'code consumed' );
		break;

	case 'otp_single_use':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		$c = code();
		same( ringo_otp_verify( 1, $c ), true, 'first use ok' );
		ok( true !== ringo_otp_verify( 1, $c ), 'second use refused' );
		break;

	case 'otp_wrong_tries':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		$c     = code();
		$wrong = ( '000000' === $c ) ? '111111' : '000000';
		for ( $i = 1; $i <= 4; $i++ ) {
			ok( true !== ringo_otp_verify( 1, $wrong ), "wrong try {$i} refused" );
		}
		$r = ringo_otp_verify( 1, $wrong );
		ok( false !== strpos( $r, 'cancelled' ), '5th wrong try cancels the code' );
		ok( false !== strpos( last_mail()['subject'], 'locked' ), 'lockout alert emailed' );
		ok( true !== ringo_otp_verify( 1, $c ), 'right code refused after lockout' );
		break;

	case 'otp_expired':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		$c = code();
		$GLOBALS['W']['umeta'][1][ RINGO_OTP_META ]['expires'] = time() - 1;
		ok( false !== strpos( (string) ringo_otp_verify( 1, $c ), 'expired' ), 'expired code refused' );
		break;

	case 'otp_session_bound':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		$c = code();
		$GLOBALS['W']['session'] = 'sess-B';
		ok( true !== ringo_otp_verify( 1, $c ), 'code refused in another session' );
		$GLOBALS['W']['session'] = 'sess-A';
		$GLOBALS['W']['user']    = 2;
		ok( true !== ringo_otp_verify( 2, $c ), 'code refused for another user' );
		$GLOBALS['W']['user']    = 1;
		same( ringo_otp_verify( 1, $c ), true, 'still valid for the right user and session' );
		break;

	case 'otp_rate_limit':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		code();
		ok( true !== ringo_otp_send( 1 ), 'second send within a minute refused' );
		same( count( $GLOBALS['W']['mail'] ), 1, 'only one email' );
		$GLOBALS['W']['umeta'][1][ RINGO_OTP_SENDS_META ] = array_fill( 0, 5, time() - 120 );
		ok( false !== strpos( (string) ringo_otp_send( 1 ), 'this hour' ), 'hourly cap per user' );
		$GLOBALS['W']['umeta'][1][ RINGO_OTP_SENDS_META ] = [];
		$GLOBALS['W']['transients']['ringo_payment_otp_site_sends'] = 10;
		ok( false !== strpos( (string) ringo_otp_send( 1 ), 'on this site' ), 'hourly cap per site' );
		$GLOBALS['W']['transients'] = [];
		$GLOBALS['W']['can'] = false;
		ok( true !== ringo_otp_send( 1 ), 'non admin cannot request a code' );
		break;

	case 'otp_protected_fields':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		full_db();
		$old = get_option( 'ringo_stripe_settings' );
		foreach ( [ 'mode' => 'test', 'paypal_mode' => 'sandbox', 'stripe_live_publishable' => 'pk_x', 'paypal_live_client_id' => 'X', 'paypal_sandbox_secret' => '' ] as $f => $v ) {
			same( ringo_otp_protected_changes( $old, array_merge( $old, [ $f => $v ] ) ), [ $f ], "{$f} change needs a code" );
		}
		same( ringo_otp_protected_changes( $old, array_merge( $old, [ 'prices' => [ 'vip' => 1 ], 'descriptions' => [ 'vip' => 'x' ], 'abandoned_after' => 5 ] ) ), [], 'prices, descriptions, timers need no code' );
		$out = ringo_sanitize_settings( [ 'mode' => 'live', 'paypal_mode' => 'live', 'paypal_live_client_id' => $K['pp_live_id'], 'stripe_live_publishable' => $K['pk_live'],
			'paypal_sandbox_client_id' => $K['pp_sbx_id'], 'stripe_test_publishable' => $K['pk_test'], 'clear' => [ 'paypal_live_secret' => 1 ] ] );
		same( $out['paypal_live_secret'], $K['pp_live_sec'], 'clear without code is blocked' );
		break;

	case 'otp_page_field':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		$html = capture( 'ringo_field_otp_code' );
		ok( false !== strpos( $html, 'name="ringo_stripe_settings[otp_code]"' ), 'code input present' );
		ok( false !== strpos( $html, 'ringo_send_payment_otp' ) && false !== strpos( $html, '_wpnonce' ), 'send link has nonce' );
		ok( false !== strpos( $html, 'p•••@example.test' ) && false === strpos( $html, OTP_TO ), 'address masked on page' );
		break;

	case 'alert_email':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		load_plugin();
		full_db();
		$old = get_option( 'ringo_stripe_settings' );
		$new = array_merge( $old, [ 'stripe_live_secret' => $K['const_live'], 'paypal_live_secret' => '', 'mode' => 'test', 'prices' => [ 'vip' => 1 ] ] );
		ringo_payment_settings_changed( $old, $new );
		$m = last_mail();
		same( $m['to'], OTP_TO, 'alert to constant address' );
		ok( false !== strpos( $m['subject'], 'Payment settings changed' ), 'alert subject' );
		foreach ( [ 'admin1 (ID 1', '203.0.113.9', 'UTC', 'stripe_live_secret: set, ends in ••••C0NS', 'paypal_live_secret: cleared', '  - mode', 'prices.vip' ] as $needle ) {
			ok( false !== strpos( $m['body'], $needle ), "alert has: {$needle}" );
		}
		ok( false === strpos( $m['body'], 'test' . "\n" ) && false === strpos( $m['body'], ': test' ), 'mode value not shown' );
		no_secret_in( all_mail_text(), 'alert email' );
		no_secret_in( json_encode( get_option( 'ringo_boat_logs' ) ), 'alert log line' );
		$n = count( $GLOBALS['W']['mail'] );
		ringo_payment_settings_changed( $old, $old );
		same( count( $GLOBALS['W']['mail'] ), $n, 'no alert when nothing changed' );
		$GLOBALS['W']['user'] = 0;
		ringo_payment_settings_changed( $old, $new );
		ok( false !== strpos( last_mail()['body'], 'no logged in user' ), 'wp-cli / code changes alerted too' );
		break;

	case 'alert_add_option':
		define( 'RINGO_PAYMENT_OTP_EMAIL', OTP_TO );
		load_plugin();
		ringo_payment_settings_changed( [], [ 'stripe_live_secret' => $K['sk_live'] ] );
		ok( false !== strpos( last_mail()['body'], 'ends in ••••L1VE' ), 'first save alerted, last 4 only' );
		no_secret_in( all_mail_text(), 'first save alert' );
		break;

	default:
		echo "unknown scenario {$SCENARIO}\n";
		exit( 2 );
}

echo "  ok   [{$SCENARIO}] {$PASS} checks\n";
