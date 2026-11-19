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

	// Email code for key / mode changes (includes/payment-otp.php).
	add_settings_section(
		'ringo_otp',
		'Confirm key and mode changes',
		function () {
			echo '<p>Changing any Stripe or PayPal key, clearing one, or switching Stripe or PayPal mode needs a 6 digit code sent by email. Prices, descriptions and timings save without a code.</p>';
		},
		'ringo-stripe-checkout'
	);
	add_settings_field( 'ringo_otp_code', 'Confirmation code', 'ringo_field_otp_code', 'ringo-stripe-checkout', 'ringo_otp' );

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

/**
 * Render one credential input.
 *
 * Secrets are NEVER printed: the input is type="password" with an empty value,
 * and only "Saved, ends in ••••1234" is shown. Leaving it empty on save keeps
 * the stored value; the "Clear" box blanks it. A field set by a constant is
 * shown locked. See includes/secrets.php.
 *
 * @param string $field Settings field.
 * @param string $hint  Help text (HTML allowed: code tags only).
 */
function ringo_render_credential_field( $field, $hint = '' ) {
	$name      = 'ringo_stripe_settings[' . $field . ']';
	$is_secret = in_array( $field, ringo_secret_fields(), true );
	$const     = ringo_credential_constant_in_use( $field );
	$cred      = ringo_get_credential( $field );
	$notes     = [];

	if ( $is_secret ) {
		if ( $const ) {
			printf(
				'<input type="password" name="%s" value="" class="regular-text" disabled="disabled" placeholder="Set in wp-config (locked)" />',
				esc_attr( $name )
			);
			$notes[] = 'Set in wp-config (locked): <code>' . esc_html( $const ) . '</code>, ends in ' . esc_html( ringo_mask_secret( $cred['value'] ) ) . '.';
		} elseif ( in_array( $field, ringo_live_credential_fields(), true ) && ! ringo_is_production_site() ) {
			// Off production a live secret can only be cleared, never entered.
			// The name stays on the (disabled) input so the row can be found.
			printf(
				'<input type="password" name="%s" value="" class="regular-text ringo-live-locked" disabled="disabled" placeholder="Live keys cannot be entered on this site" />',
				esc_attr( $name )
			);
			if ( '' !== $cred['value'] ) {
				$notes[] = '<strong>A live key is stored here</strong>, ends in ' . esc_html( ringo_mask_secret( $cred['value'] ) ) . '.';
				$notes[] = sprintf(
					'<label><input type="checkbox" name="ringo_stripe_settings[clear][%s]" value="1" /> Clear this saved key</label> (needs the email code)',
					esc_attr( $field )
				);
			} else {
				$notes[] = 'Not set.';
			}
		} else {
			printf(
				'<input type="password" autocomplete="new-password" name="%s" value="" class="regular-text" placeholder="%s" />',
				esc_attr( $name ),
				esc_attr( '' !== $cred['value'] ? 'Leave empty to keep the saved key' : '' )
			);
			if ( '' !== $cred['value'] ) {
				$notes[] = 'Saved, ends in ' . esc_html( ringo_mask_secret( $cred['value'] ) ) . '.';
				$notes[] = sprintf(
					'<label><input type="checkbox" name="ringo_stripe_settings[clear][%s]" value="1" /> Clear this saved key</label>',
					esc_attr( $field )
				);
			} else {
				$notes[] = 'Not set.';
			}
		}
	} else {
		// Client IDs and publishable keys are public by design.
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text"%s />',
			esc_attr( $name ),
			esc_attr( $cred['value'] ),
			$const ? ' disabled="disabled"' : ''
		);
		if ( $const ) {
			$notes[] = 'Set in wp-config (locked): <code>' . esc_html( $const ) . '</code>.';
		}
	}

	if ( in_array( $field, ringo_live_credential_fields(), true ) && ! ringo_is_production_site() ) {
		$notes[] = '<strong>Ignored on this site</strong> (not production).';
	}
	if ( $hint ) {
		$notes[] = wp_kses( $hint, [ 'code' => [] ] );
	}

	echo '<p class="description">' . implode( ' ', $notes ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- parts escaped above.
}

function ringo_field_test_key()  { ringo_render_credential_field( 'stripe_test_secret', 'Starts with <code>sk_test_</code> or <code>rk_test_</code>.' ); }
function ringo_field_live_key()  { ringo_render_credential_field( 'stripe_live_secret', 'Use a restricted key (<code>rk_live_</code>), see the README.' ); }
function ringo_field_test_pub()  { ringo_render_credential_field( 'stripe_test_publishable', 'Starts with <code>pk_test_</code>' ); }
function ringo_field_live_pub()  { ringo_render_credential_field( 'stripe_live_publishable', 'Starts with <code>pk_live_</code>' ); }

function ringo_field_paypal_mode() {
	$s = ringo_get_settings(); ?>
	<select id="ringo_paypal_mode" name="ringo_stripe_settings[paypal_mode]">
		<option value="sandbox" <?php selected( $s['paypal_mode'], 'sandbox' ); ?>>Sandbox</option>
		<option value="live"    <?php selected( $s['paypal_mode'], 'live' ); ?>>Live</option>
	</select>
	<?php
}

function ringo_field_paypal_sandbox_client() { ringo_render_credential_field( 'paypal_sandbox_client_id' ); }
function ringo_field_paypal_sandbox_secret() { ringo_render_credential_field( 'paypal_sandbox_secret' ); }
function ringo_field_paypal_live_client()    { ringo_render_credential_field( 'paypal_live_client_id' ); }
function ringo_field_paypal_live_secret()    { ringo_render_credential_field( 'paypal_live_secret' ); }

/**
 * Confirmation code input plus "Email me a code" link. The recipient comes
 * only from RINGO_PAYMENT_OTP_EMAIL and is shown masked.
 */
function ringo_field_otp_code() {
	$to = ringo_payment_otp_email();
	if ( '' === $to ) {
		echo '<p><strong>Locked.</strong> Key and mode changes are disabled until <code>RINGO_PAYMENT_OTP_EMAIL</code> is defined in wp-config.php (see the plugin README).</p>';
		return;
	}
	echo '<input type="text" name="ringo_stripe_settings[otp_code]" value="" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" style="width:8em" /> ';
	printf(
		'<a class="button" href="%s">Email me a code</a>',
		esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ringo_send_payment_otp' ), 'ringo_send_payment_otp' ) )
	);
	echo '<p class="description">Codes go to ' . esc_html( ringo_mask_email( $to ) ) . ' (set in wp-config). Valid 10 minutes, once, in this login session.</p>';
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
		<?php
		settings_errors( 'ringo_stripe_settings' );
		$ringo_otp_notice = get_transient( 'ringo_otp_notice_' . get_current_user_id() );
		if ( $ringo_otp_notice ) {
			delete_transient( 'ringo_otp_notice_' . get_current_user_id() );
			echo '<div class="notice notice-info"><p>' . esc_html( $ringo_otp_notice ) . '</p></div>';
		}
		?>
		<p>
			<strong>In use now:</strong>
			Stripe <?php echo esc_html( ucfirst( ringo_get_effective_stripe_mode() ) ); ?>,
			PayPal <?php echo esc_html( ucfirst( ringo_get_effective_paypal_mode() ) ); ?>.
			<?php if ( ! ringo_is_production_site() ) : ?>
				This site is not production, so Test and Sandbox are forced.
			<?php endif; ?>
			Keys set as constants in wp-config always win over this page (see the plugin README).
		</p>
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
		// Off production the Live rows stay visible so leftover live secrets
		// can be cleared (the mode is forced to Test/Sandbox there).
		var keepLiveRows = <?php echo ringo_is_production_site() ? 'false' : 'true'; ?>;
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
				if (row) row.style.display = (isShow && !keepLiveRows) ? 'none' : '';
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
