<?php
/**
 * Plugin Name:  Ringo Stripe + PayPal Checkout
 * Description:  Native boat listing forms with Stripe and PayPal checkout, smart form add-ons,
 *               add-on-only orders for published boats, payment emails, follow-ups, and failure tracking.
 * Version:      9.6.0
 * Requires PHP: 7.4
 * Author:       Ringo Media
 * Author URI:   https://ringomedia.com/
 * License:      GPL-2.0-or-later
 * Text Domain:  ringo-checkout
 * Update URI:   https://github.com/ringomedia-code/Ringo-Checkout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────

define( 'RINGO_CHECKOUT_VERSION', '9.6.0' );
define( 'RINGO_CHECKOUT_FILE',    __FILE__ );
define( 'RINGO_CHECKOUT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RINGO_CHECKOUT_URL',     plugin_dir_url( __FILE__ ) );

// ─── Autoload modules ────────────────────────────────────────────────────────

require_once RINGO_CHECKOUT_DIR . 'includes/updater.php';
require_once RINGO_CHECKOUT_DIR . 'includes/helpers.php';
require_once RINGO_CHECKOUT_DIR . 'includes/addons.php';
require_once RINGO_CHECKOUT_DIR . 'includes/settings.php';
require_once RINGO_CHECKOUT_DIR . 'includes/stripe.php';
require_once RINGO_CHECKOUT_DIR . 'includes/paypal.php';
require_once RINGO_CHECKOUT_DIR . 'includes/addon-orders.php';
require_once RINGO_CHECKOUT_DIR . 'includes/payment.php';
require_once RINGO_CHECKOUT_DIR . 'emails/customer.php';
require_once RINGO_CHECKOUT_DIR . 'emails/admin.php';
require_once RINGO_CHECKOUT_DIR . 'emails/abandoned.php';
require_once RINGO_CHECKOUT_DIR . 'emails/draft-failure.php';
require_once RINGO_CHECKOUT_DIR . 'frontend/native-forms.php';
require_once RINGO_CHECKOUT_DIR . 'frontend/enqueue.php';
require_once RINGO_CHECKOUT_DIR . 'unpaid-boats-cron.php';
require_once RINGO_CHECKOUT_DIR . 'frontend/ajax-stripe.php';
require_once RINGO_CHECKOUT_DIR . 'frontend/ajax-paypal.php';

// NEW: Load new AJAX handlers for payment features (Feature #1, #2, #3)
require_once RINGO_CHECKOUT_DIR . 'frontend/ajax-new-features.php';

require_once RINGO_CHECKOUT_DIR . 'frontend/thank-you.php';
require_once RINGO_CHECKOUT_DIR . 'admin/menu.php';
require_once RINGO_CHECKOUT_DIR . 'admin/settings-page.php';
require_once RINGO_CHECKOUT_DIR . 'admin/addons-page.php';
require_once RINGO_CHECKOUT_DIR . 'admin/payments-page.php';
require_once RINGO_CHECKOUT_DIR . 'admin/delete-action.php';
require_once RINGO_CHECKOUT_DIR . 'admin/mark-paid-action.php';
require_once RINGO_CHECKOUT_DIR . 'admin/logs-page.php';
require_once RINGO_CHECKOUT_DIR . 'admin/dashboard-widget.php';
require_once RINGO_CHECKOUT_DIR . 'admin/coupons-page.php';
require_once RINGO_CHECKOUT_DIR . 'admin/coupon-email.php';
require_once RINGO_CHECKOUT_DIR . 'cron/abandoned.php';

// ─── Activation / Deactivation ───────────────────────────────────────────────

register_activation_hook( RINGO_CHECKOUT_FILE, 'ringo_activate' );
register_deactivation_hook( RINGO_CHECKOUT_FILE, 'ringo_deactivate' );

function ringo_activate() {
	if ( function_exists( 'ringo_seed_default_addons' ) ) {
		ringo_seed_default_addons();
	}
	if ( ! wp_next_scheduled( 'ringo_check_abandoned_checkouts' ) ) {
		wp_schedule_event( time() + 60, 'ringo_10min', 'ringo_check_abandoned_checkouts' );
		ringo_log( 'Cron scheduled via activation' );
	}
}

function ringo_deactivate() {
	wp_clear_scheduled_hook( 'ringo_check_unpaid_boats' );
	$ts = wp_next_scheduled( 'ringo_check_abandoned_checkouts' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'ringo_check_abandoned_checkouts' );
		ringo_log( 'Cron unscheduled' );
	}
}
