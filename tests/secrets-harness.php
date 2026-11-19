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
	require_once $ROOT . 'includes/stripe.php';
	require_once $ROOT . 'includes/paypal.php';
	require_once $ROOT . 'admin/settings-page.php';
}
function capture( callable $fn ) { ob_start(); $fn(); return ob_get_clean(); }

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
		$out = ringo_sanitize_settings( array_merge( $in, [ 'stripe_live_secret' => '  ' . $new . ' ' ] ) );
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
		$GLOBALS['W']['home'] = 'https://staging4.bassboat4sale.example';
		load_plugin();
		db( [ 'mode' => 'test' ] );
		$in  = [ 'mode' => 'live', 'stripe_live_secret' => $K['sk_live'], 'stripe_test_secret' => $K['sk_live'],
			'paypal_live_secret' => $K['pp_live_sec'], 'paypal_live_client_id' => $K['pp_live_id'],
			'paypal_sandbox_secret' => $K['pp_sbx_sec'] ];
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

	default:
		echo "unknown scenario {$SCENARIO}\n";
		exit( 2 );
}

echo "  ok   [{$SCENARIO}] {$PASS} checks\n";
