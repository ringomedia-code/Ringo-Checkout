<?php
/**
 * Native boat submission and pay-now forms.
 *
 * Replaces the JetFormBuilder forms previously identified as 1204 and 37231,
 * while preserving their field names, taxonomies, post meta, and payment flow.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ringo_boat_submission_form', 'ringo_native_submission_shortcode' );
add_shortcode( 'ringo_boat_edit_form', 'ringo_native_edit_shortcode' );
add_shortcode( 'ringo_boat_pay_now', 'ringo_native_pay_now_shortcode' );

add_filter( 'the_content', 'ringo_native_auto_inject_forms', 40 );
add_action( 'wp_footer', 'ringo_native_pay_now_footer_fallback', 5 );

add_action( 'wp_ajax_ringo_native_submit_boat', 'ringo_ajax_native_submit_boat' );
add_action( 'wp_ajax_ringo_native_finalize_boat_assets', 'ringo_ajax_native_finalize_boat_assets' );
add_action( 'wp_ajax_ringo_native_update_boat', 'ringo_ajax_native_update_boat' );
add_action( 'wp_ajax_ringo_native_prepare_payment', 'ringo_ajax_native_prepare_payment' );
add_action( 'wp_ajax_ringo_dealer_publish_free', 'ringo_ajax_dealer_publish_free' );

/**
 * AJAX: publish a dealer's unpaid draft once no paid add-on is left (the dealer pressed Pay
 * Now and then removed the add-on). Nothing is charged.
 *
 * @return void
 */
function ringo_ajax_dealer_publish_free() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in first.' ], 401 );
	}
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ringo_stripe_intent_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}
	$post_id = isset( $_POST['boat_post_id'] ) ? absint( $_POST['boat_post_id'] ) : 0;
	if ( ! $post_id || ! ringo_native_user_can_access_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to publish this listing.' ], 403 );
	}
	if ( ! ringo_boat_is_open_for_payment( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid or no longer a draft.' ], 409 );
	}
	$package = ringo_user_dealer_package( (int) get_post_field( 'post_author', $post_id ) );
	if ( ! $package || ! ringo_checkout_base_waived( $package, $post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing needs a payment.' ], 422 );
	}
	ringo_save_boat_addons( $post_id, [], 0 );
	$url = ringo_native_publish_dealer_listing( $post_id, $package );
	if ( ! $url ) {
		wp_send_json_error( [ 'message' => 'Could not publish the listing. Please contact support.' ], 500 );
	}
	wp_send_json_success( [ 'redirect' => $url ] );
}
add_action( 'wp_enqueue_scripts', 'ringo_native_enqueue_assets', 30 );
add_action( 'save_post_boats', 'ringo_native_sync_legacy_post_id_meta', 20, 3 );
add_action( 'admin_init', 'ringo_native_backfill_legacy_post_id_meta' );

/**
 * Save the legacy JetForm post-ID fields used by existing admin columns.
 *
 * The old JetForm workflow exposed the created post through request fields such
 * as new_post_id and inserted_post_id. Some JetEngine admin columns still read
 * those values instead of WordPress's built-in post ID. Native submissions have
 * a real WordPress ID, but the compatibility fields must also be stored so the
 * existing ID column does not display an em dash.
 *
 * @param int          $post_id Boat post ID.
 * @param WP_Post|null $post    Boat post object.
 * @param bool         $update  Whether this is an existing post update.
 * @return void
 */
function ringo_native_sync_legacy_post_id_meta( $post_id, $post = null, $update = false ) {
	$post_id = absint( $post_id );

	if ( ! $post_id || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! $post instanceof WP_Post ) {
		$post = get_post( $post_id );
	}

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return;
	}

	$id_value = (string) $post_id;
	foreach ( [ 'new_post_id', 'inserted_post_id', 'post_id' ] as $meta_key ) {
		if ( $id_value !== (string) get_post_meta( $post_id, $meta_key, true ) ) {
			update_post_meta( $post_id, $meta_key, $id_value );
		}
	}

	$permalink = get_permalink( $post_id );
	if ( is_string( $permalink ) && '' !== $permalink ) {
		update_post_meta( $post_id, 'inserted_boats', esc_url_raw( $permalink ) );
	}
}

/**
 * Backfill compatibility post-ID fields for native-form boats created before
 * version 7.6. Runs once on the next admin request after the update.
 *
 * @return void
 */
function ringo_native_backfill_legacy_post_id_meta() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$migration_key = 'ringo_native_post_id_meta_migration';
	if ( '7.6.0' === get_option( $migration_key ) ) {
		return;
	}

	$boat_ids = get_posts(
		[
			'post_type'              => 'boats',
			'post_status'            => 'any',
			'posts_per_page'         => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => [
				[
					'key'     => '_ringo_native_form',
					'compare' => 'EXISTS',
				],
			],
		]
	);

	foreach ( $boat_ids as $boat_id ) {
		ringo_native_sync_legacy_post_id_meta( (int) $boat_id, get_post( $boat_id ), true );
	}

	update_option( $migration_key, '7.6.0', false );
	ringo_log(
		'Native boat post ID compatibility fields backfilled',
		[
			'count'   => count( $boat_ids ),
			'version' => '7.6.0',
		]
	);
}

/**
 * Return the active request path.
 *
 * @return string
 */
function ringo_native_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	return is_string( $path ) ? untrailingslashit( $path ) : '';
}

/**
 * Automatically append the native form on the two pages used by the old forms.
 *
 * This allows the plugin to coexist with JetFormBuilder during testing. The
 * frontend script hides the matching legacy form when the native form exists.
 *
 * @param string $content Page content.
 * @return string
 */
function ringo_native_auto_inject_forms( $content ) {
	$path         = ringo_native_request_path();
	$is_edit_path = false !== strpos( $path, '/account/edit-post' );

	if (
		is_admin() ||
		wp_doing_ajax() ||
		is_feed() ||
		( ! $is_edit_path && ( ! in_the_loop() || ! is_main_query() ) ) ||
		! apply_filters( 'ringo_native_auto_inject_forms', true )
	) {
		return $content;
	}

	static $injected = [];

	if ( is_page( 'add-boat' ) && empty( $injected['add'] ) ) {
		$injected['add'] = true;
		if ( false !== strpos( $content, 'data-ringo-native-wrapper="1204"' ) ) {
			return $content;
		}
		return $content . do_shortcode( '[ringo_boat_submission_form]' );
	}

	if ( false !== strpos( $path, '/account/edit-post' ) && empty( $injected['edit'] ) ) {
		$injected['edit'] = true;
		if ( false !== strpos( $content, 'data-ringo-native-wrapper="edit-boat"' ) ) {
			return $content;
		}
		return $content . do_shortcode( '[ringo_boat_edit_form]' );
	}

	return $content;
}

/**
 * Render a JetEngine-safe native edit form fallback on the account endpoint.
 *
 * JetEngine Profile Builder may render the edit-post endpoint outside the
 * primary WordPress content loop. In that case the normal the_content filter
 * never receives the page body. The fallback is emitted hidden in the footer,
 * moved into the profile content area, and then shown. The old JetForm edit
 * form is hidden only after the native form has been placed successfully.
 *
 * @return void
 */
function ringo_native_pay_now_footer_fallback() {
	if ( is_admin() || wp_doing_ajax() ) {
		return;
	}

	$path = ringo_native_request_path();
	if ( false === strpos( $path, '/account/edit-post' ) ) {
		return;
	}

	if ( ! empty( $GLOBALS['ringo_native_edit_form_rendered'] ) ) {
		return;
	}

	$markup = ringo_native_edit_shortcode();
	if ( '' === trim( (string) $markup ) ) {
		return;
	}
	?>
	<div id="ringo-native-edit-fallback" style="display:none" aria-hidden="true">
		<?php echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- shortcode returns escaped plugin markup. ?>
	</div>
	<script>
	(function(){
		function placeRingoEditForm(){
			var fallback = document.getElementById('ringo-native-edit-fallback');
			if (!fallback || fallback.dataset.ringoPlaced === '1') return;

			var profile = document.querySelector('.jet-profile-builder-content') ||
				document.querySelector('.jet-profile-builder__content') ||
				document.querySelector('.profile-page-content') ||
				document.querySelector('.entry-content') ||
				document.querySelector('.elementor-widget-theme-post-content .elementor-widget-container') ||
				document.querySelector('main');
			if (!profile) return;

			var legacy = profile.querySelector('form.jet-form-builder:not(.ringo-native-boat-form), form[data-form-id]:not(.ringo-native-boat-form)');
			var legacyWrap = legacy && legacy.closest('.elementor-widget-jet-form-builder, .jet-form-builder-wrapper, .elementor-widget');

			if (legacyWrap && legacyWrap.parentNode) {
				legacyWrap.parentNode.insertBefore(fallback, legacyWrap.nextSibling);
				legacyWrap.style.display = 'none';
				legacyWrap.setAttribute('aria-hidden', 'true');
			} else {
				profile.appendChild(fallback);
			}

			fallback.dataset.ringoPlaced = '1';
			fallback.style.display = '';
			fallback.removeAttribute('aria-hidden');
		}

		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', placeRingoEditForm);
		} else {
			placeRingoEditForm();
		}
		window.setTimeout(placeRingoEditForm, 500);
	})();
	</script>
	<?php
}

/**
 * Render a login requirement message.
 *
 * @return string
 */
function ringo_native_login_required_message() {
	$current    = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
	$login_page = home_url( '/register-login/' );
	$url        = add_query_arg( 'redirect_to', $current, $login_page );
	$url        = apply_filters( 'ringo_native_login_url', $url, $current, $login_page );

	return '<div class="ringo-native-notice">Please <a href="' . esc_url( $url ) . '">sign in or create an account</a> to submit or pay for a boat listing.</div>';
}

/**
 * Return safe taxonomy terms for a select field.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return array<int,WP_Term>
 */
function ringo_native_get_terms( $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return [];
	}

	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		]
	);

	if ( is_wp_error( $terms ) ) {
		return [];
	}

	// Year dropdowns should show the newest model year first.
	if ( in_array( $taxonomy, [ 'boat-year', 'motor-year' ], true ) ) {
		usort(
			$terms,
			static function ( $first, $second ) {
				$first_year  = (int) preg_replace( '/\D+/', '', (string) $first->name );
				$second_year = (int) preg_replace( '/\D+/', '', (string) $second->name );

				if ( $first_year === $second_year ) {
					return strnatcasecmp( (string) $second->name, (string) $first->name );
				}

				return $second_year <=> $first_year;
			}
		);
	}

	return $terms;
}

/**
 * Render taxonomy select options.
 *
 * @param string $taxonomy   Taxonomy slug.
 * @param string $placeholder Placeholder label.
 * @param int    $selected    Selected term ID.
 * @return string
 */
function ringo_native_term_options( $taxonomy, $placeholder, $selected = 0 ) {
	$html = '<option value="">' . esc_html( $placeholder ) . '</option>';

	foreach ( ringo_native_get_terms( $taxonomy ) as $term ) {
		$html .= sprintf(
			'<option value="%1$d"%2$s>%3$s</option>',
			(int) $term->term_id,
			selected( (int) $selected, (int) $term->term_id, false ),
			esc_html( $term->name )
		);
	}

	return $html;
}

/**
 * Return package image limits matching the former package descriptions.
 *
 * @return array<string,int>
 */
function ringo_native_package_image_limits() {
	return apply_filters(
		'ringo_native_package_image_limits',
		[
			'standard'    => 4,
			'featured'    => 10,
			'vip'         => 25,
			'pro'         => 25,
			'dealer'      => 12,
			'dealer_plus' => 25,
		]
	);
}

/**
 * Package keys the signed-in user may submit: the four seller packages, plus their own
 * dealer key when they are a Dealer / Dealer Plus account.
 *
 * @param int $user_id User ID (0 = current user).
 * @return string[]
 */
function ringo_native_valid_packages( $user_id = 0 ) {
	$valid  = [ 'standard', 'featured', 'vip', 'pro' ];
	$dealer = ringo_user_dealer_package( $user_id );
	if ( $dealer ) {
		$valid[] = $dealer;
	}
	return $valid;
}

/**
 * True while the add-on popup has not been reviewed yet (new-listing form with active add-ons).
 * The dealer is then published (free) or sent to payment (add-ons) from that popup, not on creation.
 *
 * @return bool
 */
function ringo_native_addons_pending_review() {
	return isset( $_POST['addons_reviewed'] ) && '0' === sanitize_text_field( wp_unslash( $_POST['addons_reviewed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
}

/**
 * Publish a dealer listing that has no paid add-ons. Dealers pay for their plan outside
 * the site, so nothing is charged and no payment popup is shown. Returns the thank-you
 * URL on success, or '' when this listing still needs a payment.
 *
 * @param int    $post_id Boat ID.
 * @param string $package Dealer package key.
 * @return string
 */
function ringo_native_publish_dealer_listing( $post_id, $package ) {
	if ( ! ringo_package_is_dealer( $package ) || ! ringo_checkout_base_waived( $package, $post_id ) ) {
		return '';
	}
	if ( ringo_get_boat_addon_ids( $post_id ) ) {
		return '';
	}
	if ( ! ringo_process_paid( $post_id, 'dealer', 'dealer-' . (int) $post_id, $package, 0 ) ) {
		return '';
	}
	update_post_meta( $post_id, '_ringo_amount', 0 );
	ringo_log( 'Dealer listing published without payment', [ 'post_id' => (int) $post_id, 'package' => $package ] );
	return home_url( '/thank-you/?provider=dealer&boat_post_id=' . (int) $post_id );
}

/**
 * Administrators publish straight away: no payment, whatever package or add-ons they pick.
 * Returns the thank-you URL, or '' for everyone else.
 *
 * @param int    $post_id Boat ID.
 * @param string $package Package key.
 * @return string
 */
function ringo_native_publish_admin_listing( $post_id, $package ) {
	if ( ! current_user_can( 'administrator' ) ) {
		return '';
	}
	if ( ! ringo_process_paid( $post_id, 'admin', 'admin-bypass-' . get_current_user_id(), $package, 0 ) ) {
		return '';
	}
	update_post_meta( $post_id, '_ringo_amount', 0 );
	ringo_log( 'Admin listing published without payment', [ 'post_id' => (int) $post_id, 'package' => $package ] );
	return home_url( '/thank-you/?provider=admin&boat_post_id=' . (int) $post_id );
}

/**
 * Return the maximum gallery images for a package/user.
 *
 * @param int    $user_id User ID.
 * @param string $package Package key.
 * @return int
 */
function ringo_native_max_gallery_images( $user_id, $package, $addon_ids = [] ) {
	$limits     = ringo_native_package_image_limits();
	$package    = ringo_normalize_package_key( $package );
	$fallback   = isset( $limits[ $package ] ) ? (int) $limits[ $package ] : 4;
	$base_limit = max( 1, $fallback );
	$effects    = function_exists( 'ringo_get_addon_form_effects' ) ? ringo_get_addon_form_effects( $addon_ids ) : [ 'gallery_images' => 0 ];
	$extra      = max( 0, (int) ( $effects['gallery_images'] ?? 0 ) );

	return min( 100, $base_limit + $extra );
}

/**
 * Return base video URL allowances for each package.
 *
 * @return array<string,int>
 */
function ringo_native_package_video_limits() {
	return apply_filters(
		'ringo_native_package_video_limits',
		[
			'standard'    => 0,
			'featured'    => 1,
			'vip'         => 2,
			'pro'         => 2,
			'dealer'      => 1,
			'dealer_plus' => 1,
		]
	);
}

/**
 * Return the video URL field allowance after selected form add-ons.
 *
 * @param string $package Package key.
 * @param mixed  $addon_ids Selected add-on IDs.
 * @return int
 */
function ringo_native_max_video_fields( $package, $addon_ids = [] ) {
	$limits  = ringo_native_package_video_limits();
	$package = ringo_normalize_package_key( $package );
	$base    = isset( $limits[ $package ] ) ? (int) $limits[ $package ] : 0;
	$effects = function_exists( 'ringo_get_addon_form_effects' ) ? ringo_get_addon_form_effects( $addon_ids ) : [ 'video_fields' => 0 ];
	$extra   = max( 0, (int) ( $effects['video_fields'] ?? 0 ) );

	return min( 10, max( 0, $base + $extra ) );
}

/**
 * Return the largest number of video fields that the current add-on catalog can expose.
 *
 * @return int
 */
function ringo_native_renderable_video_fields() {
	$extra = 0;
	if ( function_exists( 'ringo_get_addons' ) ) {
		foreach ( ringo_get_addons( true ) as $addon ) {
			$rule = function_exists( 'ringo_resolve_addon_form_rule' ) ? ringo_resolve_addon_form_rule( $addon ) : $addon;
			if ( 'form' === ( $rule['addon_type'] ?? '' ) && 'video_fields' === ( $rule['field_effect'] ?? '' ) ) {
				$extra += max( 0, (int) ( $rule['effect_value'] ?? 0 ) );
			}
		}
	}

	return min( 10, max( 3, 2 + $extra ) );
}


/**
 * Format a ten-digit phone number as xxx-xxx-xxxx.
 *
 * Values that do not contain exactly ten digits are returned unchanged so the
 * frontend can let the user correct them.
 *
 * @param string $phone Raw phone value.
 * @return string
 */
function ringo_native_format_phone( $phone ) {
	$phone  = trim( (string) $phone );
	$digits = preg_replace( '/\D+/', '', $phone );

	if ( ! is_string( $digits ) || 10 !== strlen( $digits ) ) {
		return $phone;
	}

	return substr( $digits, 0, 3 ) . '-' . substr( $digits, 3, 3 ) . '-' . substr( $digits, 6, 4 );
}

/**
 * Render the full native new-boat form.
 *
 * @return string
 */
function ringo_native_submission_shortcode() {
	if ( ! is_user_logged_in() ) {
		return ringo_native_login_required_message();
	}

	$user           = wp_get_current_user();
	$settings       = ringo_get_settings();
	$dealer_package = ringo_user_dealer_package( $user->ID );
	$is_dealer      = '' !== $dealer_package;
	$package        = $is_dealer ? $dealer_package : ringo_normalize_package_key( (string) get_user_meta( $user->ID, 'package_name', true ) );

	if ( ! $is_dealer && ! in_array( $package, [ 'standard', 'featured', 'vip', 'pro' ], true ) ) {
		$package = 'standard';
	}
	$package_keys = $is_dealer ? [ $dealer_package ] : [ 'standard', 'featured', 'vip', 'pro' ];

	$phone               = ringo_native_format_phone( (string) get_user_meta( $user->ID, 'phone', true ) );
	$motor_year_taxonomy = taxonomy_exists( 'motor-year' ) ? 'motor-year' : 'boat-year';
	$prices              = isset( $settings['prices'] ) && is_array( $settings['prices'] ) ? $settings['prices'] : [];
	$descriptions        = isset( $settings['descriptions'] ) && is_array( $settings['descriptions'] ) ? $settings['descriptions'] : [];
	$addons_catalog      = ringo_get_addons( true );
	$preselected_addons  = ringo_calculate_addons( $_GET['ringo_addons'] ?? [] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$max_image           = ringo_native_max_gallery_images( $user->ID, $package, $preselected_addons['ids'] );
	$max_videos          = ringo_native_max_video_fields( $package, $preselected_addons['ids'] );
	$render_video_fields = ringo_native_renderable_video_fields();

	// Add-ons are offered in a popup right before payment (see showAddonWindow() in enqueue.php), and only
	// when at least one add-on is enabled in the dashboard. With none, that screen is skipped entirely.
	$has_addons = ! empty( $addons_catalog );

	ob_start();
	?>
	<div class="ringo-native-form-shell" data-ringo-native-wrapper="1204">
		<div class="ringo-native-form-intro">
			<div>
				<span class="ringo-native-form-eyebrow">Sell Your Boat</span>
				<h1>Create Your Boat Listing</h1>
				<?php if ( $is_dealer ) : ?>
					<p>Add clear details and strong photos. Your listing is published as soon as you submit it.</p>
				<?php else : ?>
					<p>Add clear details and strong photos. Your listing will stay in Draft until payment is completed.</p>
				<?php endif; ?>
			</div>
			<div class="ringo-native-required-note"><strong>*</strong> Required fields</div>
		</div>
		<form class="ringo-native-boat-form" data-form-id="1204" <?php echo $is_dealer ? 'data-ringo-dealer="1"' : ''; ?> <?php echo current_user_can( 'administrator' ) ? 'data-ringo-admin="1"' : ''; ?> <?php echo $has_addons ? 'data-ringo-addons-pending="1"' : ''; ?> method="post" enctype="multipart/form-data" novalidate>
			<input type="hidden" name="action" value="ringo_native_submit_boat">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ringo_native_form_nonce' ) ); ?>">
			<input type="hidden" name="package_name" value="<?php echo esc_attr( $package ); ?>">
			<input type="hidden" name="package_price" value="<?php echo esc_attr( isset( $prices[ $package ] ) ? (float) $prices[ $package ] : 0 ); ?>">
			<input type="hidden" name="addon_ids" value="<?php echo esc_attr( implode( ',', $preselected_addons['ids'] ) ); ?>">
			<input type="hidden" name="addons_reviewed" value="<?php echo $has_addons ? '0' : '1'; ?>">
			<input type="hidden" name="user_id" value="<?php echo esc_attr( $user->ID ); ?>">
			<div class="ringo-native-honeypot" aria-hidden="true"><label>Website<input type="text" name="company_website" value="" tabindex="-1" autocomplete="off"></label></div>

			<div class="ringo-native-form-body" data-ringo-form-body>
			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">1</span>
					<div class="ringo-native-section-copy"><h2>Listing Basics</h2><p><?php echo $is_dealer ? 'Add a clear title and description.' : 'Choose the package and add a clear title and description.'; ?></p></div>
				</div>
				<label class="ringo-native-field ringo-native-package-picker"<?php echo $is_dealer ? ' hidden style="display:none"' : ''; ?>>
					<span>Listing Package <strong>*</strong></span>
					<select name="package" required data-ringo-package-select>
						<?php foreach ( $package_keys as $key ) : ?>
							<option
								value="<?php echo esc_attr( $key ); ?>"
								data-price="<?php echo esc_attr( isset( $prices[ $key ] ) ? (float) $prices[ $key ] : 0 ); ?>"
								data-base-max-images="<?php echo esc_attr( ringo_native_max_gallery_images( $user->ID, $key, [] ) ); ?>"
								data-base-max-videos="<?php echo esc_attr( ringo_native_max_video_fields( $key, [] ) ); ?>"
								data-description="<?php echo esc_attr( (string) ( $descriptions[ $key ] ?? '' ) ); ?>"
								<?php selected( $package, $key ); ?>
							><?php echo esc_html( strtoupper( $key ) . ' - $' . number_format_i18n( (float) ( $prices[ $key ] ?? 0 ), 2 ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<small data-ringo-package-description></small>
				</label>

				<label class="ringo-native-field">
					<span>Boat Title <strong>*</strong></span>
					<input type="text" name="title" required placeholder="Example: 2025 Ranger Z520C">
				</label>

				<div class="ringo-native-field ringo-native-editor-field">
					<span>Boat Description <strong>*</strong></span>
					<?php
					wp_editor(
						'',
						'ringo_native_boat_description',
						[
							'textarea_name' => 'content',
							'textarea_rows' => 9,
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => false,
							'editor_height' => 235,
							'tinymce'       => [
								'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
							],
						]
					);
					?>
					<small>Include electronics, anchors, batteries, deck, jack plate, prop, and other selling points.</small>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">2</span>
					<div class="ringo-native-section-copy"><h2>Seller Contact Information</h2><p>Buyers will use these details to contact you about the boat.</p></div>
				</div>
				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>First Name <strong>*</strong></span><input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>" required></label>
					<label class="ringo-native-field"><span>Last Name <strong>*</strong></span><input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>" required></label>
					<label class="ringo-native-field"><span>Email <strong>*</strong></span><input type="email" name="email" value="<?php echo esc_attr( $user->user_email ); ?>" required></label>
					<label class="ringo-native-field"><span>Phone <strong>*</strong></span><input type="tel" name="phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="123-456-7890" inputmode="numeric" autocomplete="tel" maxlength="12" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}" title="Use xxx-xxx-xxxx format" data-ringo-phone required><small>Format: xxx-xxx-xxxx</small></label>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">3</span>
					<div class="ringo-native-section-copy"><h2>Location</h2><p>Tell buyers where the boat is currently located.</p></div>
				</div>
				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>State <strong>*</strong></span><select name="state" required><?php echo ringo_native_term_options( 'state', 'Select a state' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>City <strong>*</strong></span><input type="text" name="city" required></label>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">4</span>
					<div class="ringo-native-section-copy"><h2>Boat Information</h2><p>Upload photos and add the specifications buyers need.</p></div>
				</div>
				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field ringo-native-upload-field"><span>Cover Photo <strong>*</strong></span><input type="file" name="post_thumbnail" accept="image/jpeg,image/png,image/webp" required></label>
					<label class="ringo-native-field ringo-native-upload-field"><span>Gallery <strong>*</strong></span><input type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple required data-ringo-gallery><small data-ringo-gallery-help>Maximum <?php echo esc_html( $max_image ); ?> gallery images for this package.</small></label>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2 ringo-native-price-row">
					<label class="ringo-native-field ringo-price-field"><span>Price <strong>*</strong></span><input type="number" name="price" min="0" step="0.01" required></label>
					<div class="ringo-native-choice-box"><label class="ringo-native-check"><input type="checkbox" name="price_bracket" value="Contact For Pricing" data-ringo-contact-price> <span>Contact For Pricing</span></label></div>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>Boat Year <strong>*</strong></span><select name="boat_year" required><?php echo ringo_native_term_options( 'boat-year', 'Select boat year' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Boat Make <strong>*</strong></span><select name="boat_make" required><?php echo ringo_native_term_options( 'boat-make', 'Select boat make' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Boat Model <strong>*</strong></span><input type="text" name="boat_model" required></label>
					<label class="ringo-native-field"><span>Boat Length <strong>*</strong></span><select name="boat_length" required><?php echo ringo_native_term_options( 'boatlength', 'Select boat length' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Year <strong>*</strong></span><select name="motor_year" required><?php echo ringo_native_term_options( $motor_year_taxonomy, 'Select motor year' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Make <strong>*</strong></span><select name="motor_make" required><?php echo ringo_native_term_options( 'motor-make', 'Select motor make' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Model And HP <strong>*</strong></span><input type="text" name="motor_model" required></label>
					<label class="ringo-native-field"><span>Motor Hours</span><input type="text" name="motor_hours"><small>If motor hours are unknown or not available, leave blank.</small></label>
					<label class="ringo-native-field"><span>Stock Number <span class="ringo-native-info" tabindex="0" aria-label="Dealer Only">i<span>Dealer Only</span></span></span><input type="text" name="stock_number"></label>
					<label class="ringo-native-field"><span>HIN <span class="ringo-native-info" tabindex="0" aria-label="Dealer Only">i<span>Dealer Only</span></span></span><input type="text" name="hin"></label>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2" data-ringo-video-grid>
					<?php for ( $video_index = 1; $video_index <= $render_video_fields; $video_index++ ) : ?>
						<?php $video_name = 1 === $video_index ? 'boat_video' : 'boat_video_' . $video_index; ?>
						<label class="ringo-native-field" data-ringo-video="<?php echo esc_attr( $video_index ); ?>" <?php echo $video_index > $max_videos ? 'hidden' : ''; ?>>
							<span><?php echo esc_html( 1 === $video_index ? 'Boat Video' : 'Boat Video ' . $video_index ); ?></span>
							<input type="url" name="<?php echo esc_attr( $video_name ); ?>" placeholder="YouTube or Vimeo URL" <?php disabled( $video_index > $max_videos ); ?>>
						</label>
					<?php endfor; ?>
				</div>
			</div>

			<div class="ringo-native-section ringo-native-submit-section">
				<div class="ringo-native-submit-copy">
					<label class="ringo-native-check"><input type="checkbox" name="agreements" value="1" required> <span>I agree to the Terms &amp; Conditions and Privacy Policy <strong>*</strong></span></label>
					<p>After submission, choose Stripe or PayPal to publish the listing.</p>
					<div class="ringo-native-form-message" role="alert" aria-live="polite"></div>
				</div>
				<button type="submit" class="ringo-native-submit">Continue to Payment</button>
			</div>
			</div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Return the first term ID assigned to a boat taxonomy.
 *
 * @param int    $post_id  Boat post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return int
 */
function ringo_native_post_term_id( $post_id, $taxonomy ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return 0;
	}

	$ids = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
	if ( is_wp_error( $ids ) || empty( $ids ) ) {
		return 0;
	}

	return absint( reset( $ids ) );
}

/**
 * Normalize gallery meta into attachment IDs.
 *
 * Supports the comma-separated format required by the child theme, arrays
 * created by earlier native versions, and serialized legacy values.
 *
 * @param mixed $value Gallery meta value.
 * @return array<int,int>
 */
function ringo_native_gallery_ids_from_value( $value ) {
	$value = maybe_unserialize( $value );
	$ids   = [];

	if ( is_array( $value ) ) {
		array_walk_recursive(
			$value,
			static function ( $item ) use ( &$ids ) {
				$id = absint( $item );
				if ( $id ) {
					$ids[] = $id;
				}
			}
		);
	} elseif ( is_string( $value ) ) {
		foreach ( preg_split( '/[\s,|]+/', trim( $value ) ) as $item ) {
			$id = absint( $item );
			if ( $id ) {
				$ids[] = $id;
			}
		}
	} else {
		$id = absint( $value );
		if ( $id ) {
			$ids[] = $id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Return the gallery attachment IDs for a boat.
 *
 * @param int $post_id Boat post ID.
 * @return array<int,int>
 */
function ringo_native_get_boat_gallery_ids( $post_id ) {
	return ringo_native_gallery_ids_from_value( get_post_meta( $post_id, 'gallery', true ) );
}

/**
 * Determine whether a boat has completed payment.
 *
 * Published boats are treated as paid for backward compatibility with older
 * listings that predate the current checkout metadata.
 *
 * @param int $post_id Boat post ID.
 * @return bool
 */
function ringo_native_boat_is_paid( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return false;
	}

	$checkout_status = strtolower( trim( (string) get_post_meta( $post_id, '_ringo_checkout_status', true ) ) );
	$payment_status  = strtolower( trim( (string) get_post_meta( $post_id, 'payment_status', true ) ) );
	$paid_flag       = (int) get_post_meta( $post_id, '_ringo_paid', true );

	return 1 === $paid_flag || 'paid' === $checkout_status || 'paid' === $payment_status || 'publish' === $post->post_status;
}

/**
 * Resolve the package assigned to an existing boat.
 *
 * @param int $post_id Boat post ID.
 * @param int $user_id Seller user ID.
 * @return string
 */
function ringo_native_existing_boat_package( $post_id, $user_id ) {
	$package = ringo_normalize_package_key( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$valid   = [ 'standard', 'featured', 'vip', 'pro' ];

	if ( in_array( $package, $valid, true ) || ringo_checkout_base_waived( $package, $post_id ) ) {
		return $package;
	}

	// Dealer-owned listings carry their dealer package even if the stored label is missing.
	$dealer_package = ringo_user_dealer_package( (int) get_post_field( 'post_author', $post_id ) );
	if ( $dealer_package ) {
		return $dealer_package;
	}

	$terms = wp_get_object_terms( $post_id, 'boatcategories' );
	if ( ! is_wp_error( $terms ) ) {
		foreach ( $terms as $term ) {
			$candidate = ringo_normalize_package_key( $term->slug ?: $term->name );
			if ( in_array( $candidate, $valid, true ) ) {
				return $candidate;
			}
		}
	}

	$package = ringo_normalize_package_key( (string) get_user_meta( $user_id, 'package_name', true ) );
	return in_array( $package, $valid, true ) ? $package : 'standard';
}

/**
 * Check whether a file input contains an uploaded file.
 *
 * @param string $field File field name.
 * @return bool
 */
function ringo_native_has_uploaded_file( $field ) {
	return isset( $_FILES[ $field ] ) && is_array( $_FILES[ $field ] ) && ! empty( $_FILES[ $field ]['name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $_FILES[ $field ]['error'] ?? UPLOAD_ERR_NO_FILE );
}

/**
 * Count non-empty files in a multiple upload field.
 *
 * @param string $field File field name.
 * @return int
 */
function ringo_native_multiple_upload_count( $field ) {
	if ( empty( $_FILES[ $field ]['name'] ) || ! is_array( $_FILES[ $field ]['name'] ) ) {
		return 0;
	}

	return count( array_filter( $_FILES[ $field ]['name'], static fn( $name ) => '' !== trim( (string) $name ) ) );
}

/**
 * Render the native edit form for an existing boat.
 *
 * Unpaid boats save the edits and then open checkout from the button at the end
 * of the form. Paid boats save normally with an Update Listing button.
 *
 * @return string
 */
function ringo_native_edit_shortcode() {
	$GLOBALS['ringo_native_edit_form_rendered'] = true;

	if ( ! is_user_logged_in() ) {
		return ringo_native_login_required_message();
	}

	$post_id = isset( $_GET['_post_id'] ) ? absint( wp_unslash( $_GET['_post_id'] ) ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return '<div class="ringo-native-notice ringo-native-error">Boat listing not found.</div>';
	}
	if ( ! ringo_native_user_can_access_boat( $post_id ) ) {
		return '<div class="ringo-native-notice ringo-native-error">You do not have permission to edit this boat listing.</div>';
	}

	$user                = wp_get_current_user();
	$paid                = ringo_native_boat_is_paid( $post_id );
	$package             = ringo_native_existing_boat_package( $post_id, (int) $post->post_author );
	$package_price       = ringo_get_package_price( $package );
	if ( $package_price <= 0 ) {
		$package_price = (float) get_post_meta( $post_id, '_ringo_base_package_amount', true );
	}
	if ( $package_price <= 0 ) {
		$package_price = (float) get_post_meta( $post_id, '_ringo_amount', true );
	}
	$saved_addon_ids     = ringo_get_boat_addon_ids( $post_id );
	$phone               = ringo_native_format_phone( (string) get_post_meta( $post_id, 'phone', true ) );
	$motor_year_taxonomy = taxonomy_exists( 'motor-year' ) ? 'motor-year' : 'boat-year';
	$cover_id            = get_post_thumbnail_id( $post_id );
	$gallery_ids         = ringo_native_get_boat_gallery_ids( $post_id );
	$max_gallery         = ringo_native_max_gallery_images( (int) $post->post_author, $package, $saved_addon_ids );
	$max_videos          = ringo_native_max_video_fields( $package, $saved_addon_ids );
	$render_video_fields = max( ringo_native_renderable_video_fields(), $max_videos );
	$contact_price       = '' !== trim( (string) get_post_meta( $post_id, 'price_bracket', true ) ) || '' !== trim( (string) get_post_meta( $post_id, 'contact_for_pricing', true ) );
	$email               = sanitize_email( (string) get_post_meta( $post_id, 'email', true ) );
	$email               = $email ?: $user->user_email;
	$button_label        = $paid ? 'Update Listing' : 'Pay Now';
	$form_id             = $paid ? 'ringo-edit-update' : '37231';
	$addons_updated      = isset( $_GET['ringo_addons_updated'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['ringo_addons_updated'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	ob_start();
	?>
	<div class="ringo-native-form-shell ringo-native-edit-shell" data-ringo-native-wrapper="edit-boat" data-ringo-native-edit-form>
		<?php if ( $addons_updated ) : ?>
			<div class="ringo-native-notice ringo-native-success">Your add-ons are active. The photo and video limits in this form have been updated.</div>
		<?php endif; ?>
		<div class="ringo-native-form-intro">
			<div>
				<span class="ringo-native-form-eyebrow">Manage Your Listing</span>
				<h1>Edit Your Boat</h1>
				<p>Update the listing details and photos below. <?php echo $paid ? 'Your changes will be saved to the existing listing.' : 'Your changes will be saved before the payment options open.'; ?></p>
			</div>
			<div class="ringo-native-required-note"><strong>*</strong> Required fields</div>
		</div>

		<form class="ringo-native-boat-form ringo-native-edit-form" data-form-id="<?php echo esc_attr( $form_id ); ?>" data-ringo-edit-form data-ringo-defer-payment="<?php echo $paid ? '0' : '1'; ?>" method="post" enctype="multipart/form-data" novalidate>
			<input type="hidden" name="action" value="ringo_native_update_boat">
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ringo_native_form_nonce' ) ); ?>">
			<input type="hidden" name="_post_id" value="<?php echo esc_attr( $post_id ); ?>">
			<input type="hidden" name="package_name" value="<?php echo esc_attr( $package ); ?>">
			<input type="hidden" name="package_price" value="<?php echo esc_attr( $package_price ); ?>">
			<input type="hidden" name="addon_ids" value="<?php echo esc_attr( implode( ',', $saved_addon_ids ) ); ?>">
			<input type="hidden" name="user_email" value="<?php echo esc_attr( $email ); ?>">
			<input type="hidden" name="payment_required" value="<?php echo $paid ? '0' : '1'; ?>">
			<div class="ringo-native-honeypot" aria-hidden="true"><label>Website<input type="text" name="company_website" value="" tabindex="-1" autocomplete="off"></label></div>

			<?php if ( current_user_can( 'administrator' ) ) : ?>
				<div class="ringo-native-section ringo-native-admin-section">
					<div class="ringo-native-section-head">
						<span class="ringo-native-section-number">A</span>
						<div class="ringo-native-section-copy"><h2>Administrator Controls</h2><p>Change the WordPress listing status when needed.</p></div>
					</div>
					<label class="ringo-native-field">
						<span>Update Listing Status</span>
						<select name="post_status">
							<option value="publish" <?php selected( $post->post_status, 'publish' ); ?>>Published</option>
							<option value="draft" <?php selected( $post->post_status, 'draft' ); ?>>Draft</option>
							<option value="pending" <?php selected( $post->post_status, 'pending' ); ?>>Pending Review</option>
							<option value="trash" <?php selected( $post->post_status, 'trash' ); ?>>Trash</option>
						</select>
					</label>
				</div>
			<?php endif; ?>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">1</span>
					<div class="ringo-native-section-copy"><h2>Listing Basics</h2><p>Edit the title and description buyers see.</p></div>
				</div>
				<label class="ringo-native-field">
					<span>Boat Title <strong>*</strong></span>
					<input type="text" name="title" value="<?php echo esc_attr( $post->post_title ); ?>" required>
				</label>
				<div class="ringo-native-field ringo-native-editor-field">
					<span>Boat Description <strong>*</strong></span>
					<?php
					wp_editor(
						$post->post_content,
						'ringo_native_boat_edit_description_' . $post_id,
						[
							'textarea_name' => 'content',
							'textarea_rows' => 9,
							'media_buttons' => false,
							'teeny'         => true,
							'quicktags'     => false,
							'editor_height' => 235,
							'tinymce'       => [
								'toolbar1' => 'bold,italic,bullist,numlist,link,unlink,undo,redo',
							],
						]
					);
					?>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">2</span>
					<div class="ringo-native-section-copy"><h2>Seller Contact Information</h2><p>Keep the contact details accurate for interested buyers.</p></div>
				</div>
				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>First Name <strong>*</strong></span><input type="text" name="first_name" value="<?php echo esc_attr( get_post_meta( $post_id, 'first_name', true ) ); ?>" required></label>
					<label class="ringo-native-field"><span>Last Name <strong>*</strong></span><input type="text" name="last_name" value="<?php echo esc_attr( get_post_meta( $post_id, 'last_name', true ) ); ?>" required></label>
					<label class="ringo-native-field"><span>Email <strong>*</strong></span><input type="email" name="email" value="<?php echo esc_attr( $email ); ?>" required></label>
					<label class="ringo-native-field"><span>Phone <strong>*</strong></span><input type="tel" name="phone" value="<?php echo esc_attr( $phone ); ?>" placeholder="123-456-7890" inputmode="numeric" autocomplete="tel" maxlength="12" pattern="[0-9]{3}-[0-9]{3}-[0-9]{4}" title="Use xxx-xxx-xxxx format" data-ringo-phone required><small>Format: xxx-xxx-xxxx</small></label>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">3</span>
					<div class="ringo-native-section-copy"><h2>Location</h2><p>Update where the boat is located.</p></div>
				</div>
				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>State <strong>*</strong></span><select name="state" required><?php echo ringo_native_term_options( 'state', 'Select a state', ringo_native_post_term_id( $post_id, 'state' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>City <strong>*</strong></span><input type="text" name="city" value="<?php echo esc_attr( get_post_meta( $post_id, 'city', true ) ); ?>" required></label>
				</div>
			</div>

			<div class="ringo-native-section">
				<div class="ringo-native-section-head">
					<span class="ringo-native-section-number">4</span>
					<div class="ringo-native-section-copy"><h2>Photos and Boat Information</h2><p>Keep current photos or upload replacements and new gallery images.</p></div>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2 ringo-native-current-media-row">
					<div class="ringo-native-field ringo-native-upload-field">
						<span>Featured Image <strong>*</strong></span>
						<?php if ( $cover_id ) : ?>
							<div class="ringo-native-current-cover"><?php echo wp_get_attachment_image( $cover_id, 'medium', false, [ 'loading' => 'lazy' ] ); ?><small>Current featured image</small></div>
						<?php endif; ?>
						<input type="file" name="post_thumbnail" accept="image/jpeg,image/png,image/webp" <?php echo $cover_id ? '' : 'required'; ?>>
						<small><?php echo $cover_id ? 'Choose a file only when replacing the current image.' : 'Upload a featured image.'; ?></small>
					</div>

					<div class="ringo-native-field ringo-native-upload-field">
						<span>Add Gallery Images</span>
						<input type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple data-ringo-gallery data-max-files="<?php echo esc_attr( $max_gallery ); ?>" data-current-files="<?php echo esc_attr( count( $gallery_ids ) ); ?>">
						<small data-ringo-gallery-help>Maximum <?php echo esc_html( $max_gallery ); ?> gallery images in total for this package.</small>
					</div>
				</div>

				<?php if ( $gallery_ids ) : ?>
					<div class="ringo-native-current-gallery">
						<h3>Current Gallery</h3>
						<div class="ringo-native-gallery-grid">
							<?php foreach ( $gallery_ids as $gallery_id ) : ?>
								<label class="ringo-native-gallery-card">
									<?php echo wp_get_attachment_image( $gallery_id, 'thumbnail', false, [ 'loading' => 'lazy' ] ); ?>
									<span><input type="checkbox" name="remove_gallery[]" value="<?php echo esc_attr( $gallery_id ); ?>"> Remove</span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="ringo-native-grid ringo-native-grid-2 ringo-native-price-row">
					<label class="ringo-native-field ringo-price-field"><span>Price <strong>*</strong></span><input type="number" name="price" min="0" step="0.01" value="<?php echo esc_attr( get_post_meta( $post_id, 'price', true ) ); ?>" <?php echo $contact_price ? '' : 'required'; ?>></label>
					<div class="ringo-native-choice-box"><label class="ringo-native-check"><input type="checkbox" name="price_bracket" value="Contact For Pricing" data-ringo-contact-price <?php checked( $contact_price ); ?>> <span>Contact For Pricing</span></label></div>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2">
					<label class="ringo-native-field"><span>Boat Year <strong>*</strong></span><select name="boat_year" required><?php echo ringo_native_term_options( 'boat-year', 'Select boat year', ringo_native_post_term_id( $post_id, 'boat-year' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Boat Make <strong>*</strong></span><select name="boat_make" required><?php echo ringo_native_term_options( 'boat-make', 'Select boat make', ringo_native_post_term_id( $post_id, 'boat-make' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Boat Model <strong>*</strong></span><input type="text" name="boat_model" value="<?php echo esc_attr( get_post_meta( $post_id, 'boat_model', true ) ); ?>" required></label>
					<label class="ringo-native-field"><span>Boat Length <strong>*</strong></span><select name="boat_length" required><?php echo ringo_native_term_options( 'boatlength', 'Select boat length', ringo_native_post_term_id( $post_id, 'boatlength' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Year <strong>*</strong></span><select name="motor_year" required><?php echo ringo_native_term_options( $motor_year_taxonomy, 'Select motor year', ringo_native_post_term_id( $post_id, $motor_year_taxonomy ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Make <strong>*</strong></span><select name="motor_make" required><?php echo ringo_native_term_options( 'motor-make', 'Select motor make', ringo_native_post_term_id( $post_id, 'motor-make' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
					<label class="ringo-native-field"><span>Motor Model And HP <strong>*</strong></span><input type="text" name="motor_model" value="<?php echo esc_attr( get_post_meta( $post_id, 'motor_model', true ) ); ?>" required></label>
					<label class="ringo-native-field"><span>Motor Hours</span><input type="text" name="motor_hours" value="<?php echo esc_attr( get_post_meta( $post_id, 'engine_hours', true ) ?: get_post_meta( $post_id, 'motor_hours', true ) ); ?>"><small>If motor hours are unknown or not available, leave blank.</small></label>
					<label class="ringo-native-field"><span>Stock Number <span class="ringo-native-info" tabindex="0" aria-label="Dealer Only">i<span>Dealer Only</span></span></span><input type="text" name="stock_number" value="<?php echo esc_attr( get_post_meta( $post_id, 'stock_number', true ) ); ?>"></label>
					<label class="ringo-native-field"><span>HIN <span class="ringo-native-info" tabindex="0" aria-label="Dealer Only">i<span>Dealer Only</span></span></span><input type="text" name="hin" value="<?php echo esc_attr( get_post_meta( $post_id, 'hin', true ) ); ?>"></label>
					<label class="ringo-native-field"><span>Boat Status <strong>*</strong></span><select name="boat_status" required><?php echo ringo_native_term_options( 'boat-status', 'Select boat status', ringo_native_post_term_id( $post_id, 'boat-status' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
				</div>

				<div class="ringo-native-grid ringo-native-grid-2" data-ringo-video-grid>
					<?php for ( $video_index = 1; $video_index <= $render_video_fields; $video_index++ ) : ?>
						<?php $video_name = 1 === $video_index ? 'boat_video' : 'boat_video_' . $video_index; ?>
						<label class="ringo-native-field" data-ringo-video="<?php echo esc_attr( $video_index ); ?>" <?php echo $video_index > $max_videos ? 'hidden' : ''; ?>>
							<span><?php echo esc_html( 1 === $video_index ? 'Boat Video' : 'Boat Video ' . $video_index ); ?></span>
							<input type="url" name="<?php echo esc_attr( $video_name ); ?>" value="<?php echo esc_attr( get_post_meta( $post_id, $video_name, true ) ); ?>" placeholder="YouTube or Vimeo URL" <?php disabled( $video_index > $max_videos ); ?>>
						</label>
					<?php endfor; ?>
				</div>
			</div>

			<div class="ringo-native-section ringo-native-submit-section ringo-native-edit-submit-section">
				<div class="ringo-native-submit-copy">
					<p><?php echo $paid ? 'Save the changes to this listing.' : 'Save these changes and continue to payment.'; ?></p>
					<div class="ringo-native-form-message" role="alert" aria-live="polite"></div>
				</div>
				<button type="submit" class="ringo-native-submit"><?php echo esc_html( $button_label ); ?></button>
			</div>
		</form>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Resolve the boat ID for the Pay Now shortcode.
 *
 * The edit page supplies `_post_id` in the URL. JetEngine listing cards on the
 * account page do not, so the shortcode must also read the current listing
 * object. Optional `post_id` and `id` attributes remain available for manual
 * placement.
 *
 * @param array<string,mixed> $atts Shortcode attributes.
 * @return int
 */
function ringo_native_resolve_pay_now_post_id( $atts = [] ) {
	$atts = shortcode_atts(
		[
			'post_id' => 0,
			'id'      => 0,
		],
		is_array( $atts ) ? $atts : [],
		'ringo_boat_pay_now'
	);

	$post_id = absint( $atts['post_id'] );
	if ( ! $post_id ) {
		$post_id = absint( $atts['id'] );
	}
	if ( ! $post_id && isset( $_GET['_post_id'] ) ) {
		$post_id = absint( wp_unslash( $_GET['_post_id'] ) );
	}

	// JetEngine listing context used by the boat cards on /account/.
	if ( ! $post_id && function_exists( 'jet_engine' ) ) {
		try {
			$engine = jet_engine();
			$data   = isset( $engine->listings->data ) ? $engine->listings->data : null;

			if ( $data && is_callable( [ $data, 'get_current_object_id' ] ) ) {
				$post_id = absint( $data->get_current_object_id() );
			}

			if ( ! $post_id && $data && is_callable( [ $data, 'get_current_object' ] ) ) {
				$current_object = $data->get_current_object();
				if ( $current_object instanceof WP_Post ) {
					$post_id = (int) $current_object->ID;
				} elseif ( is_object( $current_object ) && isset( $current_object->ID ) ) {
					$post_id = absint( $current_object->ID );
				} elseif ( is_array( $current_object ) && isset( $current_object['ID'] ) ) {
					$post_id = absint( $current_object['ID'] );
				}
			}
		} catch ( Throwable $error ) {
			// Fall through to the normal WordPress loop context.
		}
	}

	if ( ! $post_id ) {
		$loop_id = get_the_ID();
		$loop    = $loop_id ? get_post( $loop_id ) : null;
		if ( $loop && ringo_is_boat_post_type( $loop->post_type ) ) {
			$post_id = (int) $loop->ID;
		}
	}

	if ( ! $post_id ) {
		global $post;
		if ( $post instanceof WP_Post && ringo_is_boat_post_type( $post->post_type ) ) {
			$post_id = (int) $post->ID;
		}
	}

	return $post_id;
}

/**
 * Render the native Pay Now form for an existing Draft boat.
 *
 * @param array<string,mixed> $atts Shortcode attributes.
 * @return string
 */
function ringo_native_pay_now_shortcode( $atts = [] ) {
	$GLOBALS['ringo_native_pay_now_rendered'] = true;

	if ( ! is_user_logged_in() ) {
		return ringo_native_login_required_message();
	}

	$atts = is_array( $atts ) ? $atts : [];
	if ( false !== strpos( ringo_native_request_path(), '/account/edit-post' ) && empty( $atts['standalone'] ) ) {
		return '';
	}

	$post_id = ringo_native_resolve_pay_now_post_id( $atts );
	$post    = $post_id ? get_post( $post_id ) : null;

	// Listing templates may render the shortcode without a valid boat context.
	// Stay silent so account cards never show an error notice.
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return '';
	}

	if ( ! ringo_native_user_can_access_boat( $post_id ) ) {
		return '';
	}

	$checkout_status = strtolower( trim( (string) get_post_meta( $post_id, '_ringo_checkout_status', true ) ) );
	$payment_status  = strtolower( trim( (string) get_post_meta( $post_id, 'payment_status', true ) ) );

	if ( 'paid' === $checkout_status || 'paid' === $payment_status || 'publish' === $post->post_status ) {
		return '';
	}

	$user         = wp_get_current_user();
	$package_name = ringo_normalize_package_key( (string) get_post_meta( $post_id, '_ringo_package', true ) );

	if ( ! in_array( $package_name, [ 'standard', 'featured', 'vip', 'pro' ], true ) && ! ringo_checkout_base_waived( $package_name, $post_id ) ) {
		$package_name = ringo_normalize_package_key( (string) get_user_meta( $user->ID, 'package_name', true ) );
	}
	if ( ! in_array( $package_name, [ 'standard', 'featured', 'vip', 'pro' ], true ) && ! ringo_checkout_base_waived( $package_name, $post_id ) ) {
		$package_name = 'standard';
	}

	$package_price = ringo_get_package_price( $package_name );
	if ( $package_price <= 0 ) {
		$package_price = (float) get_post_meta( $post_id, '_ringo_base_package_amount', true );
	}
	if ( $package_price <= 0 ) {
		$package_price = (float) get_post_meta( $post_id, '_ringo_amount', true );
	}
	$saved_addon_ids = ringo_get_boat_addon_ids( $post_id );

	$email = ringo_get_form_email( $post_id );
	if ( ! $email ) {
		$email = $user->user_email;
	}

	ob_start();
	?>
	<form class="ringo-native-boat-form ringo-native-pay-form ringo-native-pay-button-only" data-form-id="37231" data-ringo-native-wrapper="37231" method="post">
		<input type="hidden" name="action" value="ringo_native_prepare_payment">
		<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'ringo_native_form_nonce' ) ); ?>">
		<input type="hidden" name="_post_id" value="<?php echo esc_attr( $post_id ); ?>">
		<input type="hidden" name="package_name" value="<?php echo esc_attr( $package_name ); ?>">
		<input type="hidden" name="package_price" value="<?php echo esc_attr( $package_price ); ?>">
		<input type="hidden" name="addon_ids" value="<?php echo esc_attr( implode( ',', $saved_addon_ids ) ); ?>">
		<input type="hidden" name="boat_name" value="<?php echo esc_attr( get_the_title( $post_id ) ); ?>">
		<input type="hidden" name="payment_status" value="paid">
		<input type="hidden" name="user_email" value="<?php echo esc_attr( $email ); ?>">
		<button type="submit" class="ringo-native-submit">Pay Now</button>
		<div class="ringo-native-form-message" role="alert" aria-live="polite"></div>
	</form>
	<?php
	return ob_get_clean();
}

/**
 * Verify the current user may modify/pay for a boat.
 *
 * @param int $post_id Boat post ID.
 * @return bool
 */
function ringo_native_user_can_access_boat( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) || ! is_user_logged_in() ) {
		return false;
	}

	return current_user_can( 'administrator' ) || (int) $post->post_author === get_current_user_id();
}

/**
 * Return and sanitise a POST text value.
 *
 * @param string $key Field name.
 * @return string
 */
function ringo_native_post_text( $key ) {
	return isset( $_POST[ $key ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ) : '';
}

/**
 * Return and sanitise a POST textarea value.
 *
 * @param string $key Field name.
 * @return string
 */
function ringo_native_post_content( $key ) {
	return isset( $_POST[ $key ] ) ? wp_kses_post( (string) wp_unslash( $_POST[ $key ] ) ) : '';
}

/**
 * Validate a term ID and assign it to a post.
 *
 * @param int    $post_id  Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @param int    $term_id  Term ID.
 * @param bool   $required Whether a valid term is required.
 * @return true|WP_Error
 */
function ringo_native_assign_term( $post_id, $taxonomy, $term_id, $required = true ) {
	$term_id = absint( $term_id );

	if ( ! taxonomy_exists( $taxonomy ) ) {
		return $required ? new WP_Error( 'missing_taxonomy', sprintf( 'Required taxonomy is unavailable: %s', $taxonomy ) ) : true;
	}

	$term = $term_id ? get_term( $term_id, $taxonomy ) : null;
	if ( ! $term || is_wp_error( $term ) ) {
		return $required ? new WP_Error( 'invalid_term', sprintf( 'Please select a valid %s.', $taxonomy ) ) : true;
	}

	$result = wp_set_object_terms( $post_id, [ $term_id ], $taxonomy, false );
	return is_wp_error( $result ) ? $result : true;
}

/**
 * Resolve the package's boat-category term.
 *
 * The IDs preserve the former JetForm conditional hidden-field mapping.
 * A slug/name lookup is used as a fallback if IDs differ between environments.
 *
 * @param string $package Package key.
 * @return int
 */
function ringo_native_package_category_term_id( $package ) {
	$map = apply_filters(
		'ringo_native_package_category_map',
		[
			'standard'    => 415,
			'featured'    => 416,
			'vip'         => 417,
			'pro'         => 418,
			'dealer'      => 419,
			'dealer_plus' => 419,
		]
	);

	$package = ringo_normalize_package_key( $package );
	$term_id = isset( $map[ $package ] ) ? absint( $map[ $package ] ) : 0;

	if ( $term_id && term_exists( $term_id, 'boatcategories' ) ) {
		return $term_id;
	}

	foreach ( [ $package, sanitize_title( $package ), strtoupper( $package ), ucfirst( $package ) ] as $candidate ) {
		$term = get_term_by( 'slug', sanitize_title( $candidate ), 'boatcategories' );
		if ( ! $term ) {
			$term = get_term_by( 'name', $candidate, 'boatcategories' );
		}
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
	}

	return 0;
}

/**
 * Build a boat-ID based filename for an uploaded image, keeping its original extension.
 *
 * @param string $original_name Original uploaded filename.
 * @param string $slug          Filename base, e.g. "42-cover" or "42-gallery-1".
 * @return string
 */
function ringo_native_build_upload_filename( $original_name, $slug ) {
	$ext = strtolower( pathinfo( (string) $original_name, PATHINFO_EXTENSION ) );
	if ( 'jpeg' === $ext ) {
		$ext = 'jpg';
	}
	if ( ! in_array( $ext, [ 'jpg', 'png', 'webp' ], true ) ) {
		$ext = 'jpg';
	}

	return $slug . '.' . $ext;
}

/**
 * Validate that an uploaded file is an allowed image before WordPress handles it.
 *
 * @param array<string,mixed> $file File array.
 * @return true|WP_Error
 */
function ringo_native_validate_image_file( $file ) {
	if ( empty( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
		return new WP_Error( 'upload_error', 'An image upload did not complete.' );
	}

	$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
	$allowed = [ 'image/jpeg', 'image/png', 'image/webp' ];
	$type    = isset( $check['type'] ) ? (string) $check['type'] : '';

	if ( ! in_array( $type, $allowed, true ) ) {
		return new WP_Error( 'invalid_image', 'Only JPG, PNG, and WebP images are allowed.' );
	}

	return true;
}

/**
 * Handle a single media upload.
 *
 * @param string $field   File field name.
 * @param int    $post_id Parent post ID.
 * @return int|WP_Error Attachment ID or error.
 */
function ringo_native_handle_single_upload( $field, $post_id ) {
	if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) {
		return new WP_Error( 'missing_upload', 'A required image is missing.' );
	}

	$valid = ringo_native_validate_image_file( $_FILES[ $field ] );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$_FILES[ $field ]['name'] = ringo_native_build_upload_filename( $_FILES[ $field ]['name'], $post_id . '-cover' );

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	return media_handle_upload( $field, $post_id );
}

/**
 * Handle a multiple gallery upload.
 *
 * @param string $field        File field name.
 * @param int    $post_id      Parent post ID.
 * @param int    $max_files    Maximum accepted images.
 * @param int    $start_index  Sequence number to start naming gallery images from (0-based offset).
 * @return array<int,int>|WP_Error
 */
function ringo_native_handle_gallery_upload( $field, $post_id, $max_files, $start_index = 0 ) {
	if ( empty( $_FILES[ $field ]['name'] ) || ! is_array( $_FILES[ $field ]['name'] ) ) {
		return new WP_Error( 'missing_gallery', 'Please upload at least one gallery image.' );
	}

	$count = count( array_filter( $_FILES[ $field ]['name'] ) );
	if ( $count < 1 ) {
		return new WP_Error( 'missing_gallery', 'Please upload at least one gallery image.' );
	}
	if ( $count > $max_files ) {
		return new WP_Error( 'gallery_limit', sprintf( 'This package allows up to %d gallery images.', $max_files ) );
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$original = $_FILES;
	$ids      = [];
	$seq      = $start_index;

	for ( $i = 0; $i < count( $_FILES[ $field ]['name'] ); $i++ ) {
		if ( empty( $_FILES[ $field ]['name'][ $i ] ) ) {
			continue;
		}

		$file = [
			'name'     => $_FILES[ $field ]['name'][ $i ],
			'type'     => $_FILES[ $field ]['type'][ $i ],
			'tmp_name' => $_FILES[ $field ]['tmp_name'][ $i ],
			'error'    => $_FILES[ $field ]['error'][ $i ],
			'size'     => $_FILES[ $field ]['size'][ $i ],
		];

		$valid = ringo_native_validate_image_file( $file );
		if ( is_wp_error( $valid ) ) {
			foreach ( $ids as $created_id ) {
				wp_delete_attachment( $created_id, true );
			}
			$_FILES = $original;
			return $valid;
		}

		$seq++;
		$file['name'] = ringo_native_build_upload_filename( $file['name'], $post_id . '-gallery-' . $seq );

		$_FILES['ringo_gallery_upload'] = $file;
		$attachment_id                  = media_handle_upload( 'ringo_gallery_upload', $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			foreach ( $ids as $created_id ) {
				wp_delete_attachment( $created_id, true );
			}
			$_FILES = $original;
			return $attachment_id;
		}

		$ids[] = (int) $attachment_id;
	}

	$_FILES = $original;
	return $ids;
}

/**
 * Delete a failed native submission and any attachments created for it.
 *
 * @param int        $post_id        Post ID.
 * @param array<int> $attachment_ids Attachment IDs.
 * @return void
 */
function ringo_native_cleanup_failed_submission( $post_id, $attachment_ids = [] ) {
	foreach ( array_unique( array_map( 'absint', $attachment_ids ) ) as $attachment_id ) {
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}

	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
}

/**
 * AJAX: create the Draft boat from the native form.
 *
 * @return void
 */
function ringo_ajax_native_submit_boat() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in before submitting a listing.' ], 401 );
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ringo_native_form_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}

	if ( ! empty( $_POST['company_website'] ) ) {
		wp_send_json_error( [ 'message' => 'Submission could not be processed.' ], 400 );
	}

	$user_id = get_current_user_id();
	$lock    = 'ringo_native_submit_lock_' . $user_id;
	if ( get_transient( $lock ) ) {
		wp_send_json_error( [ 'message' => 'Your listing is already being processed.' ], 429 );
	}
	set_transient( $lock, 1, 20 );

	$fast_phase = ! empty( $_POST['ringo_fast_phase'] );

	$package   = ringo_normalize_package_key( ringo_native_post_text( 'package' ) );
	// Dealer / Dealer Plus accounts always submit under their own plan, whatever the browser sent.
	$dealer_package = ringo_user_dealer_package( $user_id );
	$is_dealer      = '' !== $dealer_package;
	if ( $is_dealer ) {
		$package = $dealer_package;
	}
	$addon_ids = ringo_calculate_addons( $_POST['addon_ids'] ?? [] )['ids']; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$title     = ringo_native_post_text( 'title' );
	$content   = ringo_native_post_content( 'content' );
	$first     = ringo_native_post_text( 'first_name' );
	$last      = ringo_native_post_text( 'last_name' );
	$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone_raw    = ringo_native_post_text( 'phone' );
	$phone_digits = preg_replace( '/\D+/', '', $phone_raw );
	$phone        = ringo_native_format_phone( $phone_raw );
	$city      = ringo_native_post_text( 'city' );
	$agreement = ! empty( $_POST['agreements'] );
	$contact   = ! empty( $_POST['price_bracket'] );
	$price     = isset( $_POST['price'] ) ? (float) wp_unslash( $_POST['price'] ) : 0;

	$required_text = [
		'Boat title'  => $title,
		'Description' => trim( wp_strip_all_tags( $content ) ),
		'First name'  => $first,
		'Last name'   => $last,
		'Phone'       => $phone,
		'City'        => $city,
	];

	foreach ( $required_text as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $label . ' is required.' ], 422 );
		}
	}

	if ( ! in_array( $package, ringo_native_valid_packages( $user_id ), true ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Please select a valid package.' ], 422 );
	}
	if ( ! $email || ! is_email( $email ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ], 422 );
	}
	if ( ! is_string( $phone_digits ) || 10 !== strlen( $phone_digits ) || ! preg_match( '/^[0-9]{3}-[0-9]{3}-[0-9]{4}$/', $phone ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Enter the phone number in xxx-xxx-xxxx format.' ], 422 );
	}
	if ( ! $agreement ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Please accept the Terms & Conditions and Privacy Policy.' ], 422 );
	}
	if ( ! $contact && $price <= 0 ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Enter a price or select Contact For Pricing.' ], 422 );
	}

	$max_gallery = ringo_native_max_gallery_images( $user_id, $package, $addon_ids );
	$max_videos  = ringo_native_max_video_fields( $package, $addon_ids );

	if ( $fast_phase ) {
		$cover_selected = ! empty( $_POST['ringo_cover_selected'] );
		$gallery_count  = isset( $_POST['ringo_gallery_count'] ) ? absint( $_POST['ringo_gallery_count'] ) : 0;
		if ( ! $cover_selected ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => 'Please select a cover photo.' ], 422 );
		}
		if ( $gallery_count < 1 ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => 'Please select at least one gallery image.' ], 422 );
		}
		if ( $gallery_count > $max_gallery ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => sprintf( 'Your package and add-ons allow up to %d gallery images.', $max_gallery ) ], 422 );
		}
	}

	$taxonomy_fields = [
		'state'       => [ 'taxonomy' => 'state', 'term' => absint( $_POST['state'] ?? 0 ) ],
		'boat_make'   => [ 'taxonomy' => 'boat-make', 'term' => absint( $_POST['boat_make'] ?? 0 ) ],
		'boat_length' => [ 'taxonomy' => 'boatlength', 'term' => absint( $_POST['boat_length'] ?? 0 ) ],
		'boat_year'   => [ 'taxonomy' => 'boat-year', 'term' => absint( $_POST['boat_year'] ?? 0 ) ],
		'motor_make'  => [ 'taxonomy' => 'motor-make', 'term' => absint( $_POST['motor_make'] ?? 0 ) ],
		'motor_year'  => [ 'taxonomy' => taxonomy_exists( 'motor-year' ) ? 'motor-year' : 'boat-year', 'term' => absint( $_POST['motor_year'] ?? 0 ) ],
	];

	foreach ( $taxonomy_fields as $field ) {
		if ( empty( $field['term'] ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => 'Please complete every required boat selection.' ], 422 );
		}
	}

	$post_id = wp_insert_post(
		[
			'post_type'    => $is_dealer ? 'dlr_boats' : 'boats',
			'post_status'  => 'draft',
			'post_title'   => $title,
			'post_content' => $content,
			'post_author'  => $user_id,
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'The boat listing could not be created.' ], 500 );
	}

	// Preserve the post-ID fields previously supplied by JetFormBuilder. The
	// WordPress post ID already exists; these meta values keep legacy JetEngine
	// admin columns and templates displaying it correctly.
	ringo_native_sync_legacy_post_id_meta( $post_id, get_post( $post_id ), false );

	$attachments = [];

	foreach ( $taxonomy_fields as $field ) {
		$result = ringo_native_assign_term( $post_id, $field['taxonomy'], $field['term'], true );
		if ( is_wp_error( $result ) ) {
			ringo_native_cleanup_failed_submission( $post_id, $attachments );
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
		}
	}

	$category_id = ringo_native_package_category_term_id( $package );
	if ( ! $category_id ) {
		ringo_native_cleanup_failed_submission( $post_id, $attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'The package category is not configured. Please contact support.' ], 500 );
	}
	$category_result = ringo_native_assign_term( $post_id, 'boatcategories', $category_id, true );
	if ( is_wp_error( $category_result ) ) {
		ringo_native_cleanup_failed_submission( $post_id, $attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => $category_result->get_error_message() ], 500 );
	}

	$ownership_result = ringo_native_assign_term( $post_id, 'boat-ownership', $is_dealer ? 5 : 4, true );
	if ( is_wp_error( $ownership_result ) ) {
		ringo_native_cleanup_failed_submission( $post_id, $attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'The boat ownership term is not configured. Please contact support.' ], 500 );
	}

	$meta = [
		'city'                => $city,
		'first_name'          => $first,
		'last_name'           => $last,
		'email'               => $email,
		'phone'               => $phone,
		'price_bracket'       => $contact ? 'Contact For Pricing' : '',
		'contact_for_pricing' => $contact ? 'Contact For Pricing' : '',
		'price'               => $contact ? '0' : number_format( $price, 2, '.', '' ),
		'boat_model'          => ringo_native_post_text( 'boat_model' ),
		'motor_model'         => ringo_native_post_text( 'motor_model' ),
		'motor_hours'         => ringo_native_post_text( 'motor_hours' ),
		'engine_hours'        => ringo_native_post_text( 'motor_hours' ),
		'stock_number'        => ringo_native_post_text( 'stock_number' ),
		'hin'                 => ringo_native_post_text( 'hin' ),
		'payment_status'      => 'unpaid',
		'_ringo_native_form'  => '1204',
	];

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	for ( $video_index = 1; $video_index <= ringo_native_renderable_video_fields(); $video_index++ ) {
		$video_key = 1 === $video_index ? 'boat_video' : 'boat_video_' . $video_index;
		$video     = $video_index <= $max_videos && isset( $_POST[ $video_key ] ) ? esc_url_raw( wp_unslash( $_POST[ $video_key ] ) ) : '';
		update_post_meta( $post_id, $video_key, $video );
	}

	// Start failure monitoring as soon as the Draft exists. This also covers a
	// browser closing while large images are still uploading.
	$package_price = ringo_get_package_price( $package );
	ringo_save_boat_addons( $post_id, $addon_ids, $package_price );
	$checkout_url  = ringo_get_draft_edit_url( $post_id );
	ringo_set_payment_meta( $post_id, 'pending', 'unpaid', $package, $package_price, '', $checkout_url, 'native-1204' );
	$attempt_id = ringo_begin_payment_attempt( $post_id, 'draft_created' );
	$now        = time();
	update_post_meta( $post_id, '_ringo_unpaid_time', $now );
	update_post_meta( $post_id, '_ringo_checkout_created_at', $now );
	ringo_save_customer_email_if_provided( $post_id, $email );

	if ( $fast_phase ) {
		update_post_meta( $post_id, '_ringo_native_assets_status', 'uploading' );
		update_post_meta( $post_id, '_ringo_native_assets_started_at', time() );
		update_post_meta( $post_id, '_ringo_native_expected_gallery_count', isset( $_POST['ringo_gallery_count'] ) ? absint( $_POST['ringo_gallery_count'] ) : 0 );

		update_user_meta( $user_id, 'first_name', $first );
		update_user_meta( $user_id, 'last_name', $last );
		update_user_meta( $user_id, 'phone', $phone );
		if ( ! $is_dealer ) {
			update_user_meta( $user_id, 'package_name', $package );
		}

		$role_map = [
			'standard' => 'standard',
			'featured' => 'featured',
			'vip'      => 'vip',
			'pro'      => 'pro',
		];
		$user_obj = get_user_by( 'id', $user_id );
		if ( ! current_user_can( 'administrator' ) && $user_obj && isset( $role_map[ $package ] ) && get_role( $role_map[ $package ] ) ) {
			$user_obj->set_role( $role_map[ $package ] );
		}

		ringo_log(
			'Native boat Draft created - fast checkout phase',
			[
				'post_id'    => $post_id,
				'user_id'    => $user_id,
				'package'    => $package,
				'amount'     => $package_price,
				'attempt_id' => $attempt_id,
			]
		);

		// Dealers with no paid add-ons are published straight away: no payment popup.
		$dealer_redirect = $is_dealer ? ( ringo_native_addons_pending_review() ? '' : ringo_native_publish_dealer_listing( $post_id, $package ) ) : ringo_native_publish_admin_listing( $post_id, $package );

		delete_transient( $lock );
		wp_send_json_success(
			[
				'inserted_post_id' => (string) $post_id,
				'post_id'           => (string) $post_id,
				'package_name'      => $package,
				'package_price'     => $package_price,
				'uploaded_file_ids' => [],
				'assets_background' => true,
				'payment_started'   => true,
				'dealer_free'       => '' !== $dealer_redirect,
				'redirect'          => $dealer_redirect,
				'ringo_native'      => true,
			]
		);
	}

	$cover_id = ringo_native_handle_single_upload( 'post_thumbnail', $post_id );
	if ( is_wp_error( $cover_id ) ) {
		ringo_native_cleanup_failed_submission( $post_id, $attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => $cover_id->get_error_message() ], 422 );
	}
	$attachments[] = (int) $cover_id;
	set_post_thumbnail( $post_id, (int) $cover_id );

	$gallery_ids = ringo_native_handle_gallery_upload( 'gallery', $post_id, $max_gallery );
	if ( is_wp_error( $gallery_ids ) ) {
		ringo_native_cleanup_failed_submission( $post_id, $attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => $gallery_ids->get_error_message() ], 422 );
	}
	$attachments = array_merge( $attachments, $gallery_ids );
	// The child theme expects gallery attachment IDs as a comma-separated string.
	// Saving an array causes its save_post handler to call explode() on an array
	// when the listing is published, which produces a fatal TypeError.
	update_post_meta( $post_id, 'gallery', implode( ',', array_values( array_filter( array_map( 'absint', $gallery_ids ) ) ) ) );
	update_post_meta( $post_id, '_ringo_uploaded_file_ids', array_values( array_map( 'absint', $attachments ) ) );
	update_post_meta( $post_id, '_ringo_native_assets_status', 'complete' );
	update_post_meta( $post_id, '_ringo_native_assets_completed_at', time() );

	update_user_meta( $user_id, 'first_name', $first );
	update_user_meta( $user_id, 'last_name', $last );
	update_user_meta( $user_id, 'phone', $phone );
	if ( ! $is_dealer ) {
		update_user_meta( $user_id, 'package_name', $package );
	}

	$role_map = [
		'standard' => 'standard',
		'featured' => 'featured',
		'vip'      => 'vip',
		'pro'      => 'pro',
	];
	$user_obj = get_user_by( 'id', $user_id );
	if ( ! current_user_can( 'administrator' ) && $user_obj && isset( $role_map[ $package ] ) && get_role( $role_map[ $package ] ) ) {
		$user_obj->set_role( $role_map[ $package ] );
	}

	ringo_log(
		'Native boat Draft created',
		[
			'post_id'    => $post_id,
			'user_id'    => $user_id,
			'package'    => $package,
			'amount'     => $package_price,
			'attempt_id' => $attempt_id,
			'images'     => count( $attachments ),
		]
	);

	// Dealers with no paid add-ons are published straight away: no payment popup.
	$dealer_redirect = $is_dealer ? ( ringo_native_addons_pending_review() ? '' : ringo_native_publish_dealer_listing( $post_id, $package ) ) : ringo_native_publish_admin_listing( $post_id, $package );

	delete_transient( $lock );
	wp_send_json_success(
		[
			'inserted_post_id' => (string) $post_id,
			'post_id'           => (string) $post_id,
			'package_name'      => $package,
			'package_price'     => $package_price,
			'uploaded_file_ids' => array_values( array_map( 'absint', $attachments ) ),
			'payment_started'   => true,
			'dealer_free'       => '' !== $dealer_redirect,
			'redirect'          => $dealer_redirect,
			'ringo_native'      => true,
		]
	);
}


/**
 * AJAX: upload the cover and gallery after the Draft/payment chooser is ready.
 *
 * The lightweight first request creates the Draft and returns its ID. This
 * second request carries only media files, so image processing can continue
 * while the buyer chooses Stripe or PayPal.
 *
 * @return void
 */
function ringo_ajax_native_finalize_boat_assets() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in before uploading listing images.' ], 401 );
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ringo_native_form_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}

	$post_id = isset( $_POST['boat_post_id'] ) ? absint( $_POST['boat_post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat listing not found.' ], 404 );
	}
	if ( ! ringo_native_user_can_access_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to upload images for this listing.' ], 403 );
	}

	$lock = 'ringo_native_asset_lock_' . $post_id;
	if ( get_transient( $lock ) ) {
		wp_send_json_error( [ 'message' => 'Listing images are already being uploaded.' ], 429 );
	}
	set_transient( $lock, 1, 300 );
	update_post_meta( $post_id, '_ringo_native_assets_status', 'uploading' );

	$attachments = [];
	$cover_id    = ringo_native_handle_single_upload( 'post_thumbnail', $post_id );
	if ( is_wp_error( $cover_id ) ) {
		update_post_meta( $post_id, '_ringo_native_assets_status', 'failed' );
		update_post_meta( $post_id, '_ringo_native_assets_error', $cover_id->get_error_message() );
		delete_transient( $lock );
		ringo_record_draft_failure( $post_id, 'background_image_upload_failed', $cover_id->get_error_message(), [ 'source' => 'native_background_assets', 'field' => 'cover' ] );
		wp_send_json_error( [ 'message' => $cover_id->get_error_message() ], 422 );
	}
	$attachments[] = (int) $cover_id;
	set_post_thumbnail( $post_id, (int) $cover_id );

	$package     = ringo_normalize_package_key( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	$max_gallery = ringo_native_max_gallery_images( (int) $post->post_author, $package, ringo_get_boat_addon_ids( $post_id ) );
	$gallery_ids = ringo_native_handle_gallery_upload( 'gallery', $post_id, $max_gallery );
	if ( is_wp_error( $gallery_ids ) ) {
		ringo_native_cleanup_edit_attachments( $attachments );
		update_post_meta( $post_id, '_ringo_native_assets_status', 'failed' );
		update_post_meta( $post_id, '_ringo_native_assets_error', $gallery_ids->get_error_message() );
		delete_transient( $lock );
		ringo_record_draft_failure( $post_id, 'background_image_upload_failed', $gallery_ids->get_error_message(), [ 'source' => 'native_background_assets', 'field' => 'gallery' ] );
		wp_send_json_error( [ 'message' => $gallery_ids->get_error_message() ], 422 );
	}

	$attachments = array_values( array_unique( array_merge( $attachments, array_map( 'absint', $gallery_ids ) ) ) );
	update_post_meta( $post_id, 'gallery', implode( ',', array_values( array_filter( array_map( 'absint', $gallery_ids ) ) ) ) );
	update_post_meta( $post_id, '_ringo_uploaded_file_ids', $attachments );
	update_post_meta( $post_id, '_ringo_native_assets_status', 'complete' );
	update_post_meta( $post_id, '_ringo_native_assets_completed_at', time() );
	delete_post_meta( $post_id, '_ringo_native_assets_error' );
	delete_transient( $lock );

	ringo_log( 'Native boat images completed in background', [ 'post_id' => $post_id, 'images' => count( $attachments ) ] );

	// A very fast buyer can finish payment before image processing ends. If the
	// paid handler deferred publishing, complete it now that media is ready.
	if ( get_post_meta( $post_id, '_ringo_publish_pending_assets', true ) ) {
		ringo_finalize_paid_boat_after_assets( $post_id );
	}

	wp_send_json_success( [ 'post_id' => $post_id, 'file_count' => count( $attachments ), 'status' => 'complete' ] );
}

/**
 * Remove attachments created during a failed edit request.
 *
 * @param array<int> $attachment_ids Attachment IDs.
 * @return void
 */
function ringo_native_cleanup_edit_attachments( $attachment_ids ) {
	foreach ( array_unique( array_map( 'absint', (array) $attachment_ids ) ) as $attachment_id ) {
		if ( $attachment_id ) {
			wp_delete_attachment( $attachment_id, true );
		}
	}
}

/**
 * Send plain-language edit notifications for a paid listing.
 *
 * Unpaid edits do not send these messages because checkout success/failure has
 * its own email flow.
 *
 * @param int $post_id Boat post ID.
 * @return void
 */
function ringo_native_send_edit_notifications( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		return;
	}

	$seller_email = sanitize_email( (string) get_post_meta( $post_id, 'email', true ) );
	$admin_email  = sanitize_email( (string) get_option( 'admin_email' ) );
	$title        = get_the_title( $post_id );
	$edit_url     = add_query_arg( '_post_id', $post_id, home_url( '/account/edit-post/' ) );
	$view_url     = get_permalink( $post_id );
	$headers      = [ 'Content-Type: text/html; charset=UTF-8' ];

	if ( $seller_email && is_email( $seller_email ) ) {
		$seller_body = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:20px auto;padding:24px;border:1px solid #dfe6ed;border-radius:8px;color:#263648">'
			. '<h2 style="margin-top:0;color:#0876b9">Your boat listing was updated</h2>'
			. '<p>Your changes to <strong>' . esc_html( $title ) . '</strong> were saved.</p>'
			. '<p><a href="' . esc_url( $view_url ) . '" style="display:inline-block;padding:11px 18px;background:#0876b9;color:#fff;text-decoration:none;border-radius:5px">View Boat</a></p>'
			. '<p style="margin-top:24px;color:#667486;font-size:13px">BassBoat4Sale</p></div>';
		$seller_sent = wp_mail( $seller_email, 'Your boat listing was updated', $seller_body, $headers );
		ringo_log( 'Native edit seller email', [ 'post_id' => $post_id, 'sent' => (bool) $seller_sent, 'recipient' => $seller_email ] );
	}

	if ( $admin_email && is_email( $admin_email ) ) {
		$admin_body = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:20px auto;padding:24px;border:1px solid #dfe6ed;border-radius:8px;color:#263648">'
			. '<h2 style="margin-top:0;color:#0876b9">Boat listing updated</h2>'
			. '<p>The seller updated <strong>' . esc_html( $title ) . '</strong>.</p>'
			. '<p><strong>Seller:</strong> ' . esc_html( trim( (string) get_post_meta( $post_id, 'first_name', true ) . ' ' . (string) get_post_meta( $post_id, 'last_name', true ) ) ) . '<br>'
			. '<strong>Email:</strong> ' . esc_html( (string) get_post_meta( $post_id, 'email', true ) ) . '<br>'
			. '<strong>Phone:</strong> ' . esc_html( (string) get_post_meta( $post_id, 'phone', true ) ) . '</p>'
			. '<p><a href="' . esc_url( $edit_url ) . '" style="display:inline-block;padding:11px 18px;background:#0876b9;color:#fff;text-decoration:none;border-radius:5px">Review Listing</a></p>'
			. '<p style="margin-top:24px;color:#667486;font-size:13px">BassBoat4Sale</p></div>';
		$admin_sent = wp_mail( $admin_email, 'Boat listing updated: ' . $title, $admin_body, $headers );
		ringo_log( 'Native edit admin email', [ 'post_id' => $post_id, 'sent' => (bool) $admin_sent, 'recipient' => $admin_email ] );
	}
}

/**
 * AJAX: save changes from the native boat edit form.
 *
 * Paid boats finish with a normal update response. Unpaid boats save the same
 * fields, start a new payment attempt, and return the data used by the existing
 * Stripe/PayPal chooser.
 *
 * @return void
 */
function ringo_ajax_native_update_boat() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in before editing a listing.' ], 401 );
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ringo_native_form_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}

	if ( ! empty( $_POST['company_website'] ) ) {
		wp_send_json_error( [ 'message' => 'The update could not be processed.' ], 400 );
	}

	$post_id = isset( $_POST['_post_id'] ) ? absint( $_POST['_post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat listing not found.' ], 404 );
	}
	if ( ! ringo_native_user_can_access_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to edit this listing.' ], 403 );
	}

	$lock = 'ringo_native_edit_lock_' . $post_id . '_' . get_current_user_id();
	if ( get_transient( $lock ) ) {
		wp_send_json_error( [ 'message' => 'This listing update is already being processed.' ], 429 );
	}
	set_transient( $lock, 1, 30 );

	$title        = ringo_native_post_text( 'title' );
	$content      = ringo_native_post_content( 'content' );
	$first        = ringo_native_post_text( 'first_name' );
	$last         = ringo_native_post_text( 'last_name' );
	$email        = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone_raw    = ringo_native_post_text( 'phone' );
	$phone_digits = preg_replace( '/\D+/', '', $phone_raw );
	$phone        = ringo_native_format_phone( $phone_raw );
	$city         = ringo_native_post_text( 'city' );
	$contact      = ! empty( $_POST['price_bracket'] );
	$price        = isset( $_POST['price'] ) ? (float) wp_unslash( $_POST['price'] ) : 0;
	$paid         = ringo_native_boat_is_paid( $post_id );
	$package      = ringo_native_existing_boat_package( $post_id, (int) $post->post_author );
	$addon_ids    = ringo_get_boat_addon_ids( $post_id );
	$max_videos   = ringo_native_max_video_fields( $package, $addon_ids );
	$amount       = ringo_get_package_price( $package );
	if ( $amount <= 0 ) {
		$amount = (float) get_post_meta( $post_id, '_ringo_base_package_amount', true );
	}
	if ( $amount <= 0 ) {
		$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
	}

	$required_text = [
		'Boat title'  => $title,
		'Description' => trim( wp_strip_all_tags( $content ) ),
		'First name'  => $first,
		'Last name'   => $last,
		'Phone'       => $phone,
		'City'        => $city,
	];
	foreach ( $required_text as $label => $value ) {
		if ( '' === trim( (string) $value ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $label . ' is required.' ], 422 );
		}
	}
	if ( ! $email || ! is_email( $email ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Please enter a valid email address.' ], 422 );
	}
	if ( ! is_string( $phone_digits ) || 10 !== strlen( $phone_digits ) || ! preg_match( '/^[0-9]{3}-[0-9]{3}-[0-9]{4}$/', $phone ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Enter the phone number in xxx-xxx-xxxx format.' ], 422 );
	}
	if ( ! $contact && $price <= 0 ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Enter a price or select Contact For Pricing.' ], 422 );
	}

	$motor_year_taxonomy = taxonomy_exists( 'motor-year' ) ? 'motor-year' : 'boat-year';
	$taxonomy_fields      = [
		[ 'taxonomy' => 'state', 'term' => absint( $_POST['state'] ?? 0 ) ],
		[ 'taxonomy' => 'boat-make', 'term' => absint( $_POST['boat_make'] ?? 0 ) ],
		[ 'taxonomy' => 'boatlength', 'term' => absint( $_POST['boat_length'] ?? 0 ) ],
		[ 'taxonomy' => 'boat-year', 'term' => absint( $_POST['boat_year'] ?? 0 ) ],
		[ 'taxonomy' => 'motor-make', 'term' => absint( $_POST['motor_make'] ?? 0 ) ],
		[ 'taxonomy' => $motor_year_taxonomy, 'term' => absint( $_POST['motor_year'] ?? 0 ) ],
		[ 'taxonomy' => 'boat-status', 'term' => absint( $_POST['boat_status'] ?? 0 ) ],
	];
	foreach ( $taxonomy_fields as $field ) {
		if ( empty( $field['term'] ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => 'Please complete every required boat selection.' ], 422 );
		}
	}

	$cover_id = get_post_thumbnail_id( $post_id );
	if ( ! $cover_id && ! ringo_native_has_uploaded_file( 'post_thumbnail' ) ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'A featured image is required.' ], 422 );
	}

	$current_gallery = ringo_native_get_boat_gallery_ids( $post_id );
	$remove_gallery  = isset( $_POST['remove_gallery'] ) && is_array( $_POST['remove_gallery'] ) ? array_map( 'absint', wp_unslash( $_POST['remove_gallery'] ) ) : [];
	$kept_gallery    = array_values( array_diff( $current_gallery, $remove_gallery ) );
	$new_count       = ringo_native_multiple_upload_count( 'gallery' );
	$max_gallery     = ringo_native_max_gallery_images( (int) $post->post_author, $package, $addon_ids );

	if ( count( $kept_gallery ) + $new_count > $max_gallery ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => sprintf( 'This package allows up to %d gallery images in total.', $max_gallery ) ], 422 );
	}
	if ( 0 === count( $kept_gallery ) + $new_count ) {
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => 'Keep or upload at least one gallery image.' ], 422 );
	}

	$new_attachments = [];
	$new_cover_id    = 0;
	if ( ringo_native_has_uploaded_file( 'post_thumbnail' ) ) {
		$new_cover_id = ringo_native_handle_single_upload( 'post_thumbnail', $post_id );
		if ( is_wp_error( $new_cover_id ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $new_cover_id->get_error_message() ], 422 );
		}
		$new_attachments[] = (int) $new_cover_id;
	}

	$new_gallery_ids = [];
	if ( $new_count > 0 ) {
		$remaining_slots = max( 1, $max_gallery - count( $kept_gallery ) );
		$new_gallery_ids = ringo_native_handle_gallery_upload( 'gallery', $post_id, $remaining_slots, count( $kept_gallery ) );
		if ( is_wp_error( $new_gallery_ids ) ) {
			ringo_native_cleanup_edit_attachments( $new_attachments );
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $new_gallery_ids->get_error_message() ], 422 );
		}
		$new_attachments = array_merge( $new_attachments, $new_gallery_ids );
	}

	$final_gallery = array_values( array_unique( array_merge( $kept_gallery, array_map( 'absint', $new_gallery_ids ) ) ) );
	update_post_meta( $post_id, 'gallery', implode( ',', $final_gallery ) );
	if ( $new_cover_id ) {
		set_post_thumbnail( $post_id, (int) $new_cover_id );
	}

	$post_status = $post->post_status;
	if ( current_user_can( 'administrator' ) && isset( $_POST['post_status'] ) ) {
		$requested_status = sanitize_key( (string) wp_unslash( $_POST['post_status'] ) );
		if ( in_array( $requested_status, [ 'publish', 'draft', 'pending', 'trash' ], true ) ) {
			$post_status = $requested_status;
		}
	}

	try {
		$updated = wp_update_post(
			[
				'ID'           => $post_id,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $post_status,
			],
			true
		);
	} catch ( Throwable $error ) {
		ringo_native_cleanup_edit_attachments( $new_attachments );
		delete_transient( $lock );
		ringo_log( 'Native boat edit threw an error', [ 'post_id' => $post_id, 'error' => $error->getMessage() ] );
		wp_send_json_error( [ 'message' => 'The listing could not be updated. Please try again.' ], 500 );
	}
	if ( is_wp_error( $updated ) ) {
		ringo_native_cleanup_edit_attachments( $new_attachments );
		delete_transient( $lock );
		wp_send_json_error( [ 'message' => $updated->get_error_message() ], 500 );
	}

	// Photos the seller removed are deleted from the media library (files and thumbnails), not just
	// unlinked from the gallery. Only images that were in this listing's gallery, belong to this listing
	// and are neither the featured image nor still in the final gallery are touched.
	$current_cover_id = (int) get_post_thumbnail_id( $post_id );
	foreach ( array_unique( array_intersect( $remove_gallery, $current_gallery ) ) as $removed_id ) {
		$removed_id = absint( $removed_id );
		if ( ! $removed_id || $removed_id === $current_cover_id || in_array( $removed_id, $final_gallery, true ) ) {
			continue;
		}
		$removed_post = get_post( $removed_id );
		if ( $removed_post && 'attachment' === $removed_post->post_type && (int) $removed_post->post_parent === (int) $post_id ) {
			wp_delete_attachment( $removed_id, true );
		}
	}

	foreach ( $taxonomy_fields as $field ) {
		$result = ringo_native_assign_term( $post_id, $field['taxonomy'], $field['term'], true );
		if ( is_wp_error( $result ) ) {
			delete_transient( $lock );
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 422 );
		}
	}

	$motor_hours = ringo_native_post_text( 'motor_hours' );
	$meta        = [
		'city'                => $city,
		'first_name'          => $first,
		'last_name'           => $last,
		'email'               => $email,
		'phone'               => $phone,
		'price_bracket'       => $contact ? 'Contact For Pricing' : '',
		'contact_for_pricing' => $contact ? 'Contact For Pricing' : '',
		'price'               => $contact ? '0' : number_format( $price, 2, '.', '' ),
		'boat_model'          => ringo_native_post_text( 'boat_model' ),
		'motor_model'         => ringo_native_post_text( 'motor_model' ),
		'motor_hours'         => $motor_hours,
		'engine_hours'        => $motor_hours,
		'stock_number'        => ringo_native_post_text( 'stock_number' ),
		'hin'                 => ringo_native_post_text( 'hin' ),
		'_ringo_native_form'  => 'edit-boat',
		'_ringo_last_edit_at' => time(),
		'_ringo_last_editor'  => get_current_user_id(),
	];
	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	for ( $video_index = 1; $video_index <= max( ringo_native_renderable_video_fields(), $max_videos ); $video_index++ ) {
		$video_key = 1 === $video_index ? 'boat_video' : 'boat_video_' . $video_index;
		$video     = $video_index <= $max_videos && isset( $_POST[ $video_key ] ) ? esc_url_raw( wp_unslash( $_POST[ $video_key ] ) ) : '';
		update_post_meta( $post_id, $video_key, $video );
	}

	$tracked_uploads = ringo_native_gallery_ids_from_value( get_post_meta( $post_id, '_ringo_uploaded_file_ids', true ) );
	$tracked_uploads = array_values( array_unique( array_merge( $tracked_uploads, $new_attachments ) ) );
	if ( $tracked_uploads ) {
		update_post_meta( $post_id, '_ringo_uploaded_file_ids', $tracked_uploads );
	}

	update_user_meta( (int) $post->post_author, 'first_name', $first );
	update_user_meta( (int) $post->post_author, 'last_name', $last );
	update_user_meta( (int) $post->post_author, 'phone', $phone );
	ringo_save_customer_email_if_provided( $post_id, $email );
	ringo_native_sync_legacy_post_id_meta( $post_id, get_post( $post_id ), true );

	if ( ! $paid ) {
		ringo_set_payment_meta( $post_id, 'pending', 'unpaid', $package, $amount, '', ringo_get_draft_edit_url( $post_id ), 'native-edit-pay' );
		$attempt_id = ringo_begin_payment_attempt( $post_id, 'draft_created' );
		$now        = time();
		update_post_meta( $post_id, 'payment_status', 'unpaid' );
		update_post_meta( $post_id, '_ringo_unpaid_time', $now );
		update_post_meta( $post_id, '_ringo_checkout_created_at', $now );
		ringo_log( 'Native unpaid boat edited before payment', [ 'post_id' => $post_id, 'package' => $package, 'amount' => $amount, 'attempt_id' => $attempt_id ] );
		delete_transient( $lock );
		wp_send_json_success(
			[
				'inserted_post_id' => (string) $post_id,
				'post_id'           => (string) $post_id,
				'package_name'      => $package,
				'package_price'     => $amount,
				'user_email'        => $email,
				'payment_started'   => true,
				'ringo_native'      => true,
			]
		);
	}

	ringo_native_send_edit_notifications( $post_id );
	ringo_log( 'Native paid boat listing updated', [ 'post_id' => $post_id, 'user_id' => get_current_user_id() ] );
	delete_transient( $lock );
	wp_send_json_success(
		[
			'post_id'      => (string) $post_id,
			'update_only'  => true,
			'message'      => 'Listing updated successfully.',
			'ringo_native' => true,
		]
	);
}

/**
 * AJAX: prepare an existing Draft boat for another payment attempt.
 *
 * @return void
 */
function ringo_ajax_native_prepare_payment() {
	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'Please sign in before paying for a listing.' ], 401 );
	}

	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ringo_native_form_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed. Refresh the page and try again.' ], 403 );
	}

	$post_id = isset( $_POST['_post_id'] ) ? absint( $_POST['_post_id'] ) : 0;
	$post    = $post_id ? get_post( $post_id ) : null;

	if ( ! $post || ! ringo_is_boat_post_type( $post->post_type ) ) {
		wp_send_json_error( [ 'message' => 'Boat listing not found.' ], 404 );
	}
	if ( ! ringo_native_user_can_access_boat( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'You do not have permission to pay for this listing.' ], 403 );
	}
	if ( 'paid' === get_post_meta( $post_id, '_ringo_checkout_status', true ) || ringo_native_boat_is_paid( $post_id ) ) {
		wp_send_json_error( [ 'message' => 'This listing is already paid.' ], 409 );
	}

	$package = ringo_normalize_package_key( (string) get_post_meta( $post_id, '_ringo_package', true ) );
	if ( ! in_array( $package, [ 'standard', 'featured', 'vip', 'pro' ], true ) && ! ringo_checkout_base_waived( $package, $post_id ) ) {
		$package = ringo_normalize_package_key( ringo_native_post_text( 'package_name' ) );
	}
	if ( ! in_array( $package, [ 'standard', 'featured', 'vip', 'pro' ], true ) && ! ringo_checkout_base_waived( $package, $post_id ) ) {
		$package = 'standard';
	}

	// Administrators publish directly, with no payment.
	if ( current_user_can( 'administrator' ) ) {
		$free_url = ringo_native_publish_admin_listing( $post_id, $package );
		if ( $free_url ) {
			wp_send_json_success( [ 'inserted_post_id' => (string) $post_id, 'post_id' => (string) $post_id, 'package_name' => $package, 'package_price' => 0, 'payment_started' => true, 'dealer_free' => true, 'redirect' => $free_url, 'ringo_native' => true ] );
		}
	}

	$amount = ringo_get_package_price( $package );
	if ( $amount <= 0 ) {
		$amount = (float) get_post_meta( $post_id, '_ringo_base_package_amount', true );
	}
	if ( $amount <= 0 ) {
		$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
	}

	$email = ringo_get_form_email( $post_id );
	if ( ! $email ) {
		$user  = wp_get_current_user();
		$email = $user->user_email;
		ringo_save_customer_email_if_provided( $post_id, $email );
	}

	ringo_set_payment_meta( $post_id, 'pending', 'unpaid', $package, $amount, '', ringo_get_draft_edit_url( $post_id ), 'native-37231' );
	$attempt_id = ringo_begin_payment_attempt( $post_id, 'draft_created' );
	$now        = time();
	update_post_meta( $post_id, 'payment_status', 'unpaid' );
	update_post_meta( $post_id, '_ringo_unpaid_time', $now );
	update_post_meta( $post_id, '_ringo_checkout_created_at', $now );

	ringo_log(
		'Native pay-later attempt prepared',
		[
			'post_id'    => $post_id,
			'package'    => $package,
			'amount'     => $amount,
			'attempt_id' => $attempt_id,
		]
	);

	wp_send_json_success(
		[
			'inserted_post_id' => (string) $post_id,
			'post_id'           => (string) $post_id,
			'package_name'      => $package,
			'package_price'     => $amount,
			'user_email'        => $email,
			'payment_started'   => true,
			'ringo_native'      => true,
		]
	);
}


/**
 * Enqueue native form styling and behaviour after the checkout script.
 *
 * @return void
 */
function ringo_native_enqueue_assets() {
	$path            = ringo_native_request_path();
	$is_account_root = (bool) preg_match( '#/account$#', $path );
	if ( ! is_page( 'add-boat' ) && false === strpos( $path, '/account/edit-post' ) && ! $is_account_root ) {
		return;
	}

	wp_enqueue_style(
		'ringo-native-forms',
		RINGO_CHECKOUT_URL . 'frontend/assets/native-forms.css',
		[],
		RINGO_CHECKOUT_VERSION
	);

	if ( ! wp_script_is( 'ringo-checkout-frontend', 'enqueued' ) ) {
		return;
	}

	$handle = 'ringo-native-forms';
	wp_register_script( $handle, '', [ 'jquery', 'ringo-checkout-frontend' ], RINGO_CHECKOUT_VERSION, true );
	wp_enqueue_script( $handle );
	wp_add_inline_script(
		$handle,
		<<<'JS'
jQuery(function($){
  function setMessage($form, message, type) {
    var $box = $form.find('.ringo-native-form-message').first();
    $box.removeClass('is-error is-working');
    if (!message) { $box.hide().text(''); return; }
    $box.addClass(type === 'error' ? 'is-error' : 'is-working').text(message).show();
  }

  function syncEditors($form) {
    if (window.tinyMCE && typeof window.tinyMCE.triggerSave === 'function') {
      window.tinyMCE.triggerSave();
    }
    var value = ($form.find('[name="content"]').val() || '').toString();
    return value.replace(/<[^>]*>/g, '').replace(/&nbsp;/gi, ' ').trim();
  }

  function formatMoney(value) {
    var amount = parseFloat(value || 0);
    if (isNaN(amount)) amount = 0;
    return '$' + amount.toFixed(2);
  }

  function selectedAddonState($form) {
    var ids = [];
    var total = 0;
    var extraImages = 0;
    var extraVideos = 0;
    var names = [];

    $form.find('[data-ringo-addon-choice]:checked').each(function(){
      var $choice = $(this);
      var id = ($choice.val() || '').toString();
      var price = parseFloat($choice.attr('data-price') || 0);
      var effect = ($choice.attr('data-field-effect') || 'none').toString();
      var effectValue = parseInt($choice.attr('data-effect-value') || 0, 10);
      var name = $.trim($choice.closest('.ringo-native-addon-card').find('.ringo-native-addon-card-copy strong').first().text());
      var identity = (id + ' ' + name).toLowerCase().replace(/[^a-z0-9]+/g, '-');
      var looksExtra = /(^|-)(extra|additional|add)(-|$)/.test(identity);
      if ((effect === 'none' || isNaN(effectValue) || effectValue < 1) && looksExtra && identity.indexOf('video') !== -1) {
        effect = 'video_fields';
        effectValue = 1;
      }
      if ((effect === 'none' || isNaN(effectValue) || effectValue < 1) && looksExtra && (identity.indexOf('photo') !== -1 || identity.indexOf('image') !== -1 || identity.indexOf('gallery') !== -1)) {
        effect = 'gallery_images';
        effectValue = 10;
      }
      if (id) ids.push(id);
      if (!isNaN(price)) total += price;
      if (isNaN(effectValue) || effectValue < 0) effectValue = 0;
      if (effect === 'gallery_images') extraImages += effectValue;
      if (effect === 'video_fields') extraVideos += effectValue;
      if (name) names.push(name);
    });

    return {
      ids: ids,
      total: total,
      extraImages: extraImages,
      extraVideos: extraVideos,
      names: names
    };
  }

  // The new-listing form has no add-on cards any more (add-ons are offered in the pre-payment popup).
  // Add-ons preselected from the services page (?ringo_addons[]=...) arrive in the hidden addon_ids field;
  // keep them, and read their price / photo-video effect from the catalog instead of from checkboxes.
  function presetAddonState($form) {
    var ids = [];
    var total = 0;
    var extraImages = 0;
    var extraVideos = 0;
    var names = [];
    var wanted = (($form.find('[name="addon_ids"]').val() || '').toString()).split(',').map(function(s){ return $.trim(s); }).filter(Boolean);
    var catalog = (window.ringoPay && $.isArray(window.ringoPay.addons)) ? window.ringoPay.addons : [];
    $.each(catalog, function(_, addon){
      var id = (addon.id || '').toString();
      if (wanted.indexOf(id) === -1) return;
      var effectValue = parseInt(addon.effect_value || 0, 10);
      if (isNaN(effectValue) || effectValue < 0) effectValue = 0;
      ids.push(id);
      total += parseFloat(addon.price || 0) || 0;
      if (addon.field_effect === 'gallery_images') extraImages += effectValue;
      if (addon.field_effect === 'video_fields') extraVideos += effectValue;
      if (addon.name) names.push(addon.name);
    });
    return { ids: ids, total: total, extraImages: extraImages, extraVideos: extraVideos, names: names };
  }

  function syncPackage($form) {
    var $select = $form.find('select[name="package"]');
    if (!$select.length) return;
    var $option = $select.find('option:selected');
    var key = ($select.val() || '').toString();
    var price = parseFloat($option.attr('data-price') || 0);
    var baseImages = parseInt($option.attr('data-base-max-images') || 4, 10);
    var baseVideos = parseInt($option.attr('data-base-max-videos') || 0, 10);
    var description = ($option.attr('data-description') || '').toString();
    var addons = $form.find('[data-ringo-addon-choice]').length ? selectedAddonState($form) : presetAddonState($form);

    if (isNaN(price)) price = 0;
    if (isNaN(baseImages) || baseImages < 1) baseImages = 4;
    if (isNaN(baseVideos) || baseVideos < 0) baseVideos = 0;

    var isDealer = $form.is('[data-ringo-dealer]');
    var maxImages = Math.min(100, baseImages + addons.extraImages);
    var maxVideos = Math.min(10, baseVideos + addons.extraVideos);
    var grandTotal = price + addons.total;

    $form.find('[name="package_name"]').val(key);
    $form.find('[name="package_price"]').val(price.toFixed(2));
    $form.find('[name="addon_ids"]').val(addons.ids.join(','));
    // New-listing form with active add-ons: they are reviewed in the pre-payment popup, not here.
    var addonsPending = $form.is('[data-ringo-addons-pending]');
    $form.find('[name="addons_reviewed"]').val(addonsPending ? '0' : '1');
    $form.find('[data-ringo-gallery]').attr('data-max-files', maxImages);
    $form.find('[data-ringo-gallery-help]').text(isDealer
      ? 'Maximum ' + maxImages + ' gallery images' + (addons.ids.length ? ' with your selected add-ons.' : '.')
      : 'Maximum ' + maxImages + ' gallery images with this package and selected add-ons.');
    $form.find('[data-ringo-gallery]').each(function(){ refreshGalleryLimit($(this)); });
    $form.find('[data-ringo-package-description]').text(description);
    $form.find('[data-ringo-package-total]').text(formatMoney(price));
    $form.find('[data-ringo-addon-total]').text(formatMoney(addons.total));
    $form.find('[data-ringo-grand-total]').text(formatMoney(grandTotal));

    var capabilityParts = [maxImages + ' gallery photos'];
    capabilityParts.push(maxVideos === 0 ? 'No video URL fields' : maxVideos + ' video URL field' + (maxVideos === 1 ? '' : 's'));
    $form.find('[data-ringo-capability-summary]').text(capabilityParts.join(' | '));

    var selectedSummary;
    if (isDealer) {
      selectedSummary = addons.names.length ? addons.names.join(', ') + ' | ' + formatMoney(addons.total) : 'No paid add-ons';
    } else {
      selectedSummary = key.toUpperCase() + ' | ' + formatMoney(grandTotal);
      if (addons.names.length) selectedSummary += ' | ' + addons.names.join(', ');
    }
    $form.find('[data-ringo-selected-options-summary]').text(selectedSummary);

    // Nothing to pay for administrators, or for dealers with no paid add-on: the button publishes.
    var publishesFree = $form.is('[data-ringo-admin]') || (isDealer && !addons.ids.length && !addonsPending);
    var submitLabel = publishesFree ? 'Publish Boat' : (addonsPending && !$form.is('[data-ringo-admin]') ? 'Continue' : 'Continue to Payment');
    $form.find('button.ringo-native-submit').not('[data-ringo-start-form]').text(submitLabel);

    $form.find('[data-ringo-video]').each(function(){
      var $field = $(this);
      var index = parseInt($field.attr('data-ringo-video') || 0, 10);
      var show = index > 0 && index <= maxVideos;
      $field.prop('hidden', !show).toggle(show);
      $field.find('input').prop('disabled', !show);
      if (!show) $field.find('input').val('');
    });
  }

  function syncContactPrice($form) {
    var checked = $form.find('[data-ringo-contact-price]').is(':checked');
    var $price = $form.find('[name="price"]');
    $price.prop('disabled', checked).prop('required', !checked);
    $form.find('.ringo-price-field').toggle(!checked);
  }


  function formatPhone(value) {
    var digits = (value || '').toString().replace(/\D/g, '').slice(0, 10);
    if (digits.length > 6) return digits.slice(0, 3) + '-' + digits.slice(3, 6) + '-' + digits.slice(6);
    if (digits.length > 3) return digits.slice(0, 3) + '-' + digits.slice(3);
    return digits;
  }

  function validatePhone(input) {
    if (!input) return true;
    var valid = /^[0-9]{3}-[0-9]{3}-[0-9]{4}$/.test(input.value);
    input.setCustomValidity(valid ? '' : 'Enter the phone number as xxx-xxx-xxxx.');
    return valid;
  }

  $('.ringo-native-boat-form').each(function(){
    var $form = $(this);
    syncPackage($form);
    syncContactPrice($form);
    $form.find('[data-ringo-phone]').each(function(){
      this.value = formatPhone(this.value);
      validatePhone(this);
    });

    var formId = ($form.attr('data-form-id') || '').toString();
    $('form[data-form-id="' + formId + '"]').not('.ringo-native-boat-form').each(function(){
      var $legacy = $(this);
      $legacy.attr('aria-hidden', 'true').hide();
      var $widget = $legacy.closest('.elementor-widget-jet-form-builder, .jet-form-builder-wrapper');
      if ($widget.length) $widget.hide();
    });
  });

  if ($('[data-ringo-native-edit-form]').length) {
    var $editScope = $('.jet-profile-builder-content, .jet-profile-builder__content, .profile-page-content, .entry-content').first();
    if (!$editScope.length) $editScope = $('body');
    $editScope.find('form.jet-form-builder, .jet-form-builder form').not('.ringo-native-boat-form').each(function(){
      var $legacy = $(this);
      $legacy.attr('aria-hidden', 'true').hide();
      var $widget = $legacy.closest('.elementor-widget-jet-form-builder, .jet-form-builder-wrapper, .elementor-widget');
      if ($widget.length) $widget.hide();
    });
  }

  $(document).on('change', '.ringo-native-boat-form select[name="package"], .ringo-native-boat-form [data-ringo-addon-choice]', function(){
    syncPackage($(this).closest('form'));
  });

  $(document).on('click', '.ringo-native-boat-form [data-ringo-start-form]', function(){
    var $form = $(this).closest('form');
    syncPackage($form);
    $form.find('[data-ringo-addon-gate]').prop('hidden', true).hide();
    $form.find('[data-ringo-form-body]').prop('hidden', false).show();
    var shell = $form.closest('.ringo-native-form-shell').get(0);
    if (shell && typeof shell.scrollIntoView === 'function') shell.scrollIntoView({behavior:'smooth', block:'start'});
  });

  $(document).on('click', '.ringo-native-boat-form [data-ringo-edit-options]', function(){
    var $form = $(this).closest('form');
    $form.find('[data-ringo-form-body]').prop('hidden', true).hide();
    $form.find('[data-ringo-addon-gate]').prop('hidden', false).show();
    var gate = $form.find('[data-ringo-addon-gate]').get(0);
    if (gate && typeof gate.scrollIntoView === 'function') gate.scrollIntoView({behavior:'smooth', block:'start'});
  });

  $(document).on('change', '.ringo-native-boat-form [data-ringo-contact-price]', function(){
    syncContactPrice($(this).closest('form'));
  });

  $(document).on('input', '.ringo-native-boat-form [data-ringo-phone]', function(){
    this.value = formatPhone(this.value);
    this.setCustomValidity('');
  });

  $(document).on('blur change', '.ringo-native-boat-form [data-ringo-phone]', function(){
    validatePhone(this);
  });

  // Gallery limit: keep what the user picked, say how many are too many, and let them remove files
  // one by one. The form cannot be submitted while the selection is over the limit.
  function refreshGalleryLimit($input) {
    var input = $input.get(0);
    if (!input) return;
    var $form = $input.closest('form');
    var maxFiles = parseInt($input.attr('data-max-files') || 0, 10);
    var currentFiles = parseInt($input.attr('data-current-files') || 0, 10);
    var removedFiles = $form.find('[name="remove_gallery[]"]:checked').length;
    var availableFiles = Math.max(0, maxFiles - Math.max(0, currentFiles - removedFiles));
    var files = input.files ? Array.prototype.slice.call(input.files) : [];
    var over = maxFiles > 0 && files.length > availableFiles;

    var $box = $input.parent().find('[data-ringo-gallery-selection]');
    if (!$box.length) {
      var $help = $input.parent().find('[data-ringo-gallery-help]');
      $box = $('<div data-ringo-gallery-selection></div>');
      if ($help.length) $box.insertAfter($help.last()); else $box.insertAfter($input);
    }
    $box.empty();
    input.setCustomValidity(over ? 'Too many gallery images selected. Remove some to continue.' : '');
    if (!files.length) return;

    if (over) {
      var extra = files.length - availableFiles;
      $('<div role="alert" style="margin:8px 0;padding:9px 11px;border:1px solid #e0a3a3;border-radius:7px;background:#fdf1f1;color:#9b1c1c;font-size:13px;line-height:1.4;"></div>')
        .text('You selected ' + files.length + ' image' + (files.length === 1 ? '' : 's') + ', but you can add only ' + availableFiles + ' more (maximum ' + maxFiles + ' in total). Remove ' + extra + ' image' + (extra === 1 ? '' : 's') + ' from the list below to continue.')
        .appendTo($box);
    }
    var $list = $('<ul style="list-style:none;margin:8px 0 0;padding:0;display:grid;gap:5px;"></ul>').appendTo($box);
    $.each(files, function(i, file){
      var $row = $('<li style="display:flex;justify-content:space-between;align-items:center;gap:10px;padding:6px 10px;border:1px solid #dce6ee;border-radius:6px;background:#fff;font-size:13px;"></li>');
      $('<span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>').text(file.name).appendTo($row);
      $('<button type="button" data-ringo-gallery-remove="' + i + '" style="border:0;background:transparent;color:#9b1c1c;cursor:pointer;font-weight:700;font-size:13px;">Remove</button>').appendTo($row);
      $list.append($row);
    });
  }

  $(document).on('change', '.ringo-native-boat-form [data-ringo-gallery]', function(){
    refreshGalleryLimit($(this));
  });

  $(document).on('change', '.ringo-native-boat-form [name="remove_gallery[]"]', function(){
    $(this).closest('form').find('[data-ringo-gallery]').each(function(){ refreshGalleryLimit($(this)); });
  });

  $(document).on('click', '.ringo-native-boat-form [data-ringo-gallery-remove]', function(){
    var $input = $(this).closest('form').find('[data-ringo-gallery]').first();
    var input = $input.get(0);
    var drop = parseInt($(this).attr('data-ringo-gallery-remove'), 10);
    if (!input || !input.files || typeof DataTransfer === 'undefined') return;
    var transfer = new DataTransfer();
    Array.prototype.forEach.call(input.files, function(file, i){ if (i !== drop) transfer.items.add(file); });
    input.files = transfer.files;
    refreshGalleryLimit($input);
  });

  $(document).on('submit', 'form.ringo-native-boat-form', function(event){
    event.preventDefault();

    var form = this;
    var $form = $(form);
    if ($form.data('ringoSubmitting')) return;

    if (($form.attr('data-form-id') === '1204' || $form.is('[data-ringo-edit-form]')) && !syncEditors($form)) {
      setMessage($form, 'Boat Description is required.', 'error');
      $(document).trigger('ringo/native-submit-failed');
      return;
    }

    var phoneInput = $form.find('[data-ringo-phone]').get(0);
    if (phoneInput && !validatePhone(phoneInput)) {
      if (typeof phoneInput.reportValidity === 'function') phoneInput.reportValidity();
      $(document).trigger('ringo/native-submit-failed');
      return;
    }

    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      if (typeof form.reportValidity === 'function') form.reportValidity();
      $(document).trigger('ringo/native-submit-failed');
      return;
    }

    var $button = $form.find('button[type="submit"]').first();
    var originalText = $button.text();
    $form.data('ringoSubmitting', true);
    $button.prop('disabled', true).text('Processing...');
    if ($form.attr('data-form-id') === '1204') {
      setMessage($form, '', '');
    } else {
      setMessage($form, '', '');
    }

    var requestData = new FormData(form);
    var isFastNewBoat = $form.attr('data-form-id') === '1204';
    if (isFastNewBoat) {
      var coverInput = $form.find('input[name="post_thumbnail"]').get(0);
      var galleryInput = $form.find('[data-ringo-gallery]').get(0);
      requestData.delete('post_thumbnail');
      requestData.delete('gallery[]');
      requestData.append('ringo_fast_phase', '1');
      requestData.append('ringo_cover_selected', coverInput && coverInput.files && coverInput.files.length ? '1' : '0');
      requestData.append('ringo_gallery_count', galleryInput && galleryInput.files ? String(galleryInput.files.length) : '0');
    }

    function uploadAssetsInBackground(postId) {
      var coverInput = $form.find('input[name="post_thumbnail"]').get(0);
      var galleryInput = $form.find('[data-ringo-gallery]').get(0);
      var assetData = new FormData();
      assetData.append('action', 'ringo_native_finalize_boat_assets');
      assetData.append('nonce', $form.find('[name="nonce"]').val() || '');
      assetData.append('boat_post_id', postId);
      if (coverInput && coverInput.files && coverInput.files[0]) {
        assetData.append('post_thumbnail', coverInput.files[0]);
      }
      if (galleryInput && galleryInput.files) {
        for (var i = 0; i < galleryInput.files.length; i++) {
          assetData.append('gallery[]', galleryInput.files[i]);
        }
      }

      window.ringoNativeAssetUploadStatus = 'uploading';
      window.ringoNativeAssetUploadPostId = String(postId);
      window.ringoNativeAssetUploadPromise = $.ajax({
        url: window.ringoPay.ajaxUrl,
        method: 'POST',
        dataType: 'json',
        data: assetData,
        processData: false,
        contentType: false,
        timeout: 300000
      }).done(function(assetResponse){
        if (!assetResponse || !assetResponse.success) {
          window.ringoNativeAssetUploadStatus = 'failed';
          console.error('[RINGO] Background image upload failed', assetResponse);
          $(document).trigger('ringo/native-assets-failed', [assetResponse, form]);
          return;
        }
        window.ringoNativeAssetUploadStatus = 'complete';
        $(document).trigger('ringo/native-assets-complete', [assetResponse, form]);
      }).fail(function(xhr, status){
        window.ringoNativeAssetUploadStatus = 'failed';
        console.error('[RINGO] Background image upload request failed', status);
        $(document).trigger('ringo/native-assets-failed', [xhr, form]);
      });
    }

    $.ajax({
      url: window.ringoPay.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: requestData,
      processData: false,
      contentType: false,
      timeout: isFastNewBoat ? 60000 : 300000
    }).done(function(response){
      if (!response || !response.success) {
        var message = response && response.data && response.data.message ? response.data.message : 'Submission failed. Please try again.';
        setMessage($form, message, 'error');
        $form.data('ringoSubmitting', false);
        $button.prop('disabled', false).text(originalText);
        $(document).trigger('ringo/native-submit-failed');
        return;
      }

      var responseData = response && response.data ? response.data : {};
      if (responseData.update_only) {
        setMessage($form, responseData.message || 'Listing updated successfully.', 'working');
        $form.data('ringoSubmitting', false);
        $button.prop('disabled', false).text(originalText);
        $(document).trigger('ringo/native-update-success', [response, form]);
        return;
      }

      setMessage($form, '', '');
      $(document).trigger('jet-form-builder/ajax/on-success', [response, form]);
      if (isFastNewBoat && responseData.assets_background && responseData.post_id) {
        uploadAssetsInBackground(responseData.post_id);
      }
    }).fail(function(xhr, status){
      var message = 'Submission failed. Please try again.';
      if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
        message = xhr.responseJSON.data.message;
      } else if (status === 'timeout') {
        message = 'The upload took too long. Check your Draft listings before submitting again.';
      }
      setMessage($form, message, 'error');
      $form.data('ringoSubmitting', false);
      $button.prop('disabled', false).text(originalText);
      $(document).trigger('ringo/native-submit-failed');
    });
  });
});
JS
	);
}
