<?php
/**
 * Ringo Checkout payment keys. EXAMPLE, copy and fill in on the server only.
 *
 * Put the real file OUTSIDE the web root (one folder above public_html), for
 * example /home/<user>/www/<site>/ringo-payment-keys.php, with permissions 600,
 * and include it from wp-config.php (see README, "Payment keys"):
 *
 *     $ringo_keys = __DIR__ . '/../ringo-payment-keys.php';
 *     if ( is_readable( $ringo_keys ) ) {
 *         require_once $ringo_keys;
 *     }
 *
 * Never commit the real file. Constants here always win over the keys saved on
 * the plugin settings page. Leave out any line you do not use.
 */

// Only these hosts may use live keys. Everything else (staging copies) is
// forced to Stripe Test and PayPal Sandbox.
define( 'RINGO_PRODUCTION_HOSTS', 'example.com,www.example.com' );

// Where change codes and change alerts go. Needed to change keys or modes on
// the settings page. Placeholder: put the real address only on the server.
define( 'RINGO_PAYMENT_OTP_EMAIL', 'owner@example.com' );

// Stripe. Live: a RESTRICTED key (rk_live_...) with only the permissions in the README.
define( 'RINGO_STRIPE_LIVE_SECRET',      'rk_live_REPLACE_ME' );
define( 'RINGO_STRIPE_LIVE_PUBLISHABLE', 'pk_live_REPLACE_ME' );
define( 'RINGO_STRIPE_TEST_SECRET',      'rk_test_REPLACE_ME' );
define( 'RINGO_STRIPE_TEST_PUBLISHABLE', 'pk_test_REPLACE_ME' );

// PayPal REST app (Accept payments only, Payouts off).
define( 'RINGO_PAYPAL_LIVE_CLIENT_ID',    'REPLACE_ME' );
define( 'RINGO_PAYPAL_LIVE_SECRET',       'REPLACE_ME' );
define( 'RINGO_PAYPAL_SANDBOX_CLIENT_ID', 'REPLACE_ME' );
define( 'RINGO_PAYPAL_SANDBOX_SECRET',    'REPLACE_ME' );
