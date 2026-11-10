<?php
/**
 * Admin menu registration.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'ringo_register_admin_menus', 50 );

/**
 * Register the top-level menu page and its two subpages.
 */
function ringo_register_admin_menus() {
	add_menu_page(
		'Ringo Custom Checkout',
		'Ringo Custom Checkout',
		'manage_options',
		'ringo-stripe-payments',
		'ringo_render_payments_page',
		'dashicons-money-alt',
		56
	);

	// "Payments" subpage — same callback as the top-level entry.
	add_submenu_page(
		'ringo-stripe-payments',
		'Payments',
		'Payments',
		'manage_options',
		'ringo-stripe-payments',
		'ringo_render_payments_page'
	);

	// "Settings" subpage.
	add_submenu_page(
		'ringo-stripe-payments',
		'Settings',
		'Settings',
		'manage_options',
		'ringo-stripe-checkout',
		'ringo_render_settings_page'
	);

	// Optional services shown before payment.
	add_submenu_page(
		'ringo-stripe-payments',
		'Checkout Add-ons',
		'Add-ons',
		'manage_options',
		'ringo-checkout-addons',
		'ringo_render_addons_page'
	);

	// "Email Logs" subpage.
	add_submenu_page(
		'ringo-stripe-payments',
		'Email Logs',
		'Email Logs',
		'manage_options',
		'ringo-email-logs',
		'ringo_render_logs_page'
	);

	// "Boat Logs" subpage.
	add_submenu_page(
		'ringo-stripe-payments',
		'Boat Logs',
		'Boat Logs',
		'manage_options',
		'ringo-boat-logs',
		'ringo_render_boat_logs_page'
	);

	// "Coupons" subpage.
	add_submenu_page(
		'ringo-stripe-payments',
		'Coupon Codes',
		'Coupon Codes',
		'manage_options',
		'ringo-coupons',
		'ringo_render_coupons_page'
	);

	// Hidden: Coupon Email sender (accessed via Email button, not shown in nav).
	add_submenu_page(
		null,
		'Send Coupon Email — Ringo Checkout',
		'Send Coupon Email',
		'manage_options',
		'ringo-coupon-email',
		'ringo_render_coupon_email_page'
	);
}

// Remove the settings page from Settings → (General/etc.) if it was ever
// accidentally registered there.
add_action( 'admin_menu', function () {
	remove_submenu_page( 'options-general.php', 'ringo-stripe-checkout' );
}, 999 );
