<?php
/**
 * Wires up automatic update checks against the plugin's GitHub repository,
 * using the Plugin Update Checker library (https://github.com/YahnisElsts/plugin-update-checker).
 *
 * WordPress core only checks wordpress.org by default, so a self-hosted plugin
 * like this one needs its own update source or the admin never sees new versions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once RINGO_CHECKOUT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ringo_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/ringomedia-code/Ringo-Checkout',
	RINGO_CHECKOUT_FILE,
	'ringo-checkout'
);

$ringo_update_checker->setBranch( 'main' );

// If the repo is ever made private, uncomment and supply a GitHub personal access token:
// $ringo_update_checker->setAuthentication( 'your-token-here' );
