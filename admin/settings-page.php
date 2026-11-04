<?php
/**
 * Admin settings page.
 *
 * Registers all settings sections and fields, and renders the page HTML.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Register Settings API ───────────────────────────────────────────────────

add_action( 'admin_init', 'ringo_register_settings' );

function ringo_register_settings() {
	register_setting(
		'ringo_stripe_settings_group',
		'ringo_stripe_settings',
		'ringo_sanitize_settings'
	);

	// Stripe section.
	add_settings_section(
		'ringo_stripe_main',
		'Stripe Settings (Inline Card)',
		function () {
			echo '<p>Configure Stripe keys and mode (inline card payments, no redirect).</p>';
		},
		'ringo-stripe-checkout'
	);

	add_settings_field( 'ringo_mode',     'Stripe Mode',              'ringo_field_mode',     'ringo-stripe-checkout', 'ringo_stripe_main' );
	add_settings_field( 'ringo_test_key', 'Stripe Test Secret Key',   'ringo_field_test_key', 'ringo-stripe-checkout', 'ringo_stripe_main' );
	add_settings_field( 'ringo_live_key', 'Stripe Live Secret Key',   'ringo_field_live_key', 'ringo-stripe-checkout', 'ringo_stripe_main' );
	add_settings_field( 'ringo_test_pub', 'Stripe Test Publishable Key', 'ringo_field_test_pub', 'ringo-stripe-checkout', 'ringo_stripe_main' );
	add_settings_field( 'ringo_live_pub', 'Stripe Live Publishable Key', 'ringo_field_live_pub', 'ringo-stripe-checkout', 'ringo_stripe_main' );

	// PayPal section.
	add_settings_section(
		'ringo_paypal_main',
		'PayPal Settings (Popup Buttons)',
		function () {
			echo '<p>Configure PayPal REST API credentials. PayPal opens inside a popup (no redirect).</p>';
		},
		'ringo-stripe-checkout'
	);

	add_settings_field( 'ringo_paypal_mode',          'PayPal Mode',             'ringo_field_paypal_mode',          'ringo-stripe-checkout', 'ringo_paypal_main' );
	add_settings_field( 'ringo_paypal_sandbox_client', 'PayPal Sandbox Client ID','ringo_field_paypal_sandbox_client','ringo-stripe-checkout', 'ringo_paypal_main' );
	add_settings_field( 'ringo_paypal_sandbox_secret', 'PayPal Sandbox Secret',   'ringo_field_paypal_sandbox_secret','ringo-stripe-checkout', 'ringo_paypal_main' );
	add_settings_field( 'ringo_paypal_live_client',    'PayPal Live Client ID',   'ringo_field_paypal_live_client',   'ringo-stripe-checkout', 'ringo_paypal_main' );
	add_settings_field( 'ringo_paypal_live_secret',    'PayPal Live Secret',      'ringo_field_paypal_live_secret',   'ringo-stripe-checkout', 'ringo_paypal_main' );

	// Prices section.
	add_settings_section(
		'ringo_prices',
		'Package Prices (USD)',
		function () { echo '<p>Prices used for both Stripe and PayPal.</p>'; },
		'ringo-stripe-checkout'
	);

	foreach ( [ 'standard', 'featured', 'vip', 'pro' ] as $k ) {
		add_settings_field(
			'ringo_price_' . $k,
			ucfirst( $k ) . ' price',
			function () use ( $k ) { ringo_price_input( $k ); },
			'ringo-stripe-checkout',
			'ringo_prices'
		);
	}

	// Descriptions section.
	add_settings_section(
		'ringo_descriptions',
		'Package Descriptions',
		function () { echo '<p>Shown in the Stripe card popup and PayPal popup.</p>'; },
		'ringo-stripe-checkout'
	);

	foreach ( [ 'standard', 'featured', 'vip', 'pro' ] as $k ) {
		add_settings_field(
			'ringo_desc_' . $k,
			ucfirst( $k ) . ' description',
			function () use ( $k ) { ringo_desc_textarea( $k ); },
			'ringo-stripe-checkout',
			'ringo_descriptions'
		);
	}

	// Abandoned checkout / stuck-checkout timing section.
	add_settings_section(
		'ringo_abandoned',
		'Checkout Timing',
		function () { echo '<p>Controls how long to wait before treating a checkout as abandoned or stuck. All values are in seconds.</p>'; },
		'ringo-stripe-checkout'
	);

	add_settings_field(
		'ringo_abandoned_after',
		'Abandoned after (seconds)',
		'ringo_field_abandoned_after',
		'ringo-stripe-checkout',
		'ringo_abandoned'
	);

	add_settings_field(
		'ringo_stuck_watchdog_after',
		'Payment watchdog "stuck" after (seconds)',
		'ringo_field_stuck_watchdog_after',
		'ringo-stripe-checkout',
		'ringo_abandoned'
	);

	add_settings_field(
		'ringo_stuck_assets_after',
		'Stuck photo upload after (seconds)',
		'ringo_field_stuck_assets_after',
		'ringo-stripe-checkout',
		'ringo_abandoned'
	);
}

// ─── Field callbacks ─────────────────────────────────────────────────────────

function ringo_field_mode() {
	$s = ringo_get_settings(); ?>
	<select id="ringo_stripe_mode" name="ringo_stripe_settings[mode]">
		<option value="test" <?php selected( $s['mode'], 'test' ); ?>>Test</option>
		<option value="live" <?php selected( $s['mode'], 'live' ); ?>>Live</option>
	</select>
	<?php
}

function ringo_field_test_key() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" autocomplete="new-password" name="ringo_stripe_settings[stripe_test_secret]" value="%s" class="regular-text" />',
		esc_attr( $s['stripe_test_secret'] )
	);
	echo '<p class="description">Starts with <code>sk_test_</code></p>';
}

function ringo_field_live_key() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" autocomplete="new-password" name="ringo_stripe_settings[stripe_live_secret]" value="%s" class="regular-text" />',
		esc_attr( $s['stripe_live_secret'] )
	);
	echo '<p class="description">Starts with <code>sk_live_</code></p>';
}

function ringo_field_test_pub() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" name="ringo_stripe_settings[stripe_test_publishable]" value="%s" class="regular-text" />',
		esc_attr( $s['stripe_test_publishable'] )
	);
	echo '<p class="description">Starts with <code>pk_test_</code></p>';
}

function ringo_field_live_pub() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" name="ringo_stripe_settings[stripe_live_publishable]" value="%s" class="regular-text" />',
		esc_attr( $s['stripe_live_publishable'] )
	);
	echo '<p class="description">Starts with <code>pk_live_</code></p>';
}

function ringo_field_paypal_mode() {
	$s = ringo_get_settings(); ?>
	<select id="ringo_paypal_mode" name="ringo_stripe_settings[paypal_mode]">
		<option value="sandbox" <?php selected( $s['paypal_mode'], 'sandbox' ); ?>>Sandbox</option>
		<option value="live"    <?php selected( $s['paypal_mode'], 'live' ); ?>>Live</option>
	</select>
	<?php
}

function ringo_field_paypal_sandbox_client() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" name="ringo_stripe_settings[paypal_sandbox_client_id]" value="%s" class="regular-text" />',
		esc_attr( $s['paypal_sandbox_client_id'] )
	);
}

function ringo_field_paypal_sandbox_secret() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" autocomplete="new-password" name="ringo_stripe_settings[paypal_sandbox_secret]" value="%s" class="regular-text" />',
		esc_attr( $s['paypal_sandbox_secret'] )
	);
}

function ringo_field_paypal_live_client() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" name="ringo_stripe_settings[paypal_live_client_id]" value="%s" class="regular-text" />',
		esc_attr( $s['paypal_live_client_id'] )
	);
}

function ringo_field_paypal_live_secret() {
	$s = ringo_get_settings();
	printf(
		'<input type="text" autocomplete="new-password" name="ringo_stripe_settings[paypal_live_secret]" value="%s" class="regular-text" />',
		esc_attr( $s['paypal_live_secret'] )
	);
}

function ringo_price_input( $key ) {
	$s   = ringo_get_settings();
	$val = isset( $s['prices'][ $key ] ) ? (float) $s['prices'][ $key ] : 0.0;
	printf(
		'<input type="number" step="0.01" min="0" name="ringo_stripe_settings[prices][%s]" value="%s" />',
		esc_attr( $key ),
		esc_attr( number_format( $val, 2, '.', '' ) )
	);
}

function ringo_desc_textarea( $key ) {
	$s   = ringo_get_settings();
	$val = isset( $s['descriptions'][ $key ] ) ? (string) $s['descriptions'][ $key ] : '';
	printf(
		'<textarea name="ringo_stripe_settings[descriptions][%s]" rows="3" class="large-text">%s</textarea>',
		esc_attr( $key ),
		esc_textarea( $val )
	);
}

function ringo_field_abandoned_after() {
	$s = ringo_get_settings();
	printf(
		'<input type="number" min="0" name="ringo_stripe_settings[abandoned_after]" value="%s" />',
		esc_attr( (int) $s['abandoned_after'] )
	);
	echo '<p class="description">Example: 60 = 1 minute. How old an unpaid draft must be before the abandoned-checkout cron sends follow-up emails.</p>';
}

function ringo_field_stuck_watchdog_after() {
	$s = ringo_get_settings();
	printf(
		'<input type="number" min="30" name="ringo_stripe_settings[stuck_watchdog_after]" value="%s" />',
		esc_attr( (int) $s['stuck_watchdog_after'] )
	);
	echo '<p class="description">Example: 120 = 2 minutes. How long the payment-method screen can sit idle in the buyer\'s browser before it is reported as stuck.</p>';
}

function ringo_field_stuck_assets_after() {
	$s = ringo_get_settings();
	printf(
		'<input type="number" min="60" name="ringo_stripe_settings[stuck_assets_after]" value="%s" />',
		esc_attr( (int) $s['stuck_assets_after'] )
	);
	echo '<p class="description">Example: 600 = 10 minutes. How long a paid listing\'s background photo upload can run before it is flagged and the seller is asked to re-upload.</p>';
}

// ─── Page renderer ───────────────────────────────────────────────────────────

/**
 * Render the Settings admin page.
 */
function ringo_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Ringo Custom Checkout — Settings</h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'ringo_stripe_settings_group' );
			do_settings_sections( 'ringo-stripe-checkout' );
			submit_button( 'Save settings' );
			?>
		</form>
	</div>

	<script>
	(function () {
		function closestRow(el) {
			return el ? el.closest('tr') : null;
		}

		function toggleFieldRows(selectId, showValues, fieldSelectors, hideFieldSelectors) {
			var sel = document.getElementById(selectId);
			if (!sel) return;

			var isShow = showValues.indexOf(sel.value) !== -1;

			fieldSelectors.forEach(function (qs) {
				var row = closestRow(document.querySelector(qs));
				if (row) row.style.display = isShow ? '' : 'none';
			});

			hideFieldSelectors.forEach(function (qs) {
				var row = closestRow(document.querySelector(qs));
				if (row) row.style.display = isShow ? 'none' : '';
			});
		}

		function applyStripeMode() {
			toggleFieldRows(
				'ringo_stripe_mode',
				['test'],
				[
					'input[name="ringo_stripe_settings[stripe_test_secret]"]',
					'input[name="ringo_stripe_settings[stripe_test_publishable]"]',
				],
				[
					'input[name="ringo_stripe_settings[stripe_live_secret]"]',
					'input[name="ringo_stripe_settings[stripe_live_publishable]"]',
				]
			);
		}

		function applyPayPalMode() {
			toggleFieldRows(
				'ringo_paypal_mode',
				['sandbox'],
				[
					'input[name="ringo_stripe_settings[paypal_sandbox_client_id]"]',
					'input[name="ringo_stripe_settings[paypal_sandbox_secret]"]',
				],
				[
					'input[name="ringo_stripe_settings[paypal_live_client_id]"]',
					'input[name="ringo_stripe_settings[paypal_live_secret]"]',
				]
			);
		}

		document.addEventListener('DOMContentLoaded', function () {
			applyStripeMode();
			applyPayPalMode();

			var stripeMode = document.getElementById('ringo_stripe_mode');
			if (stripeMode) stripeMode.addEventListener('change', applyStripeMode);

			var paypalMode = document.getElementById('ringo_paypal_mode');
			if (paypalMode) paypalMode.addEventListener('change', applyPayPalMode);
		});
	}());
	</script>
	<?php
}
