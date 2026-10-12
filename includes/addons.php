<?php
/**
 * Optional checkout add-ons and form capability rules.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Return sample add-ons installed with the module.
 *
 * Prices are intentionally editable sample values.
 *
 * @return array<string,array<string,mixed>>
 */
function ringo_default_addons() {
	return [
		'extra-photos' => [
			'id'           => 'extra-photos',
			'name'         => 'Extra Photos',
			'description'  => 'Add space for up to 10 additional listing photos.',
			'price'        => 19.99,
			'enabled'      => 1,
			'sort'         => 10,
			'addon_type'   => 'form',
			'field_effect' => 'gallery_images',
			'effect_value' => 10,
		],
		'extra-video' => [
			'id'           => 'extra-video',
			'name'         => 'Extra Video',
			'description'  => 'Add one additional boat video field to the listing.',
			'price'        => 29.99,
			'enabled'      => 1,
			'sort'         => 20,
			'addon_type'   => 'form',
			'field_effect' => 'video_fields',
			'effect_value' => 1,
		],
		'boosted-post' => [
			'id'           => 'boosted-post',
			'name'         => 'Boosted Post',
			'description'  => 'Give the listing extra promotional placement.',
			'price'        => 49.99,
			'enabled'      => 1,
			'sort'         => 30,
			'addon_type'   => 'system',
			'field_effect' => 'none',
			'effect_value' => 0,
		],
		'onsite-listing' => [
			'id'           => 'onsite-listing',
			'name'         => 'On-site Listing Service',
			'description'  => 'Assisted listing setup and content preparation.',
			'price'        => 79.99,
			'enabled'      => 1,
			'sort'         => 40,
			'addon_type'   => 'system',
			'field_effect' => 'none',
			'effect_value' => 0,
		],
	];
}

/**
 * Seed defaults only when no add-on option exists yet.
 *
 * @return void
 */
function ringo_seed_default_addons() {
	if ( false === get_option( 'ringo_checkout_addons', false ) ) {
		add_option( 'ringo_checkout_addons', ringo_default_addons(), '', false );
	}
}
add_action( 'init', 'ringo_seed_default_addons', 5 );

/**
 * Sanitize an add-on identifier.
 *
 * @param mixed $value Raw ID.
 * @return string
 */
function ringo_normalize_addon_id( $value ) {
	return sanitize_title( (string) $value );
}

/**
 * Infer smart-form settings for add-ons saved by older plugin versions.
 *
 * @param string $id Add-on ID.
 * @return array{addon_type:string,field_effect:string,effect_value:int}
 */
function ringo_infer_legacy_addon_rules( $id ) {
	$id = ringo_normalize_addon_id( $id );

	if ( 'extra-photos' === $id ) {
		return [
			'addon_type'   => 'form',
			'field_effect' => 'gallery_images',
			'effect_value' => 10,
		];
	}

	if ( 'extra-video' === $id ) {
		return [
			'addon_type'   => 'form',
			'field_effect' => 'video_fields',
			'effect_value' => 1,
		];
	}

	return [
		'addon_type'   => 'system',
		'field_effect' => 'none',
		'effect_value' => 0,
	];
}


/**
 * Resolve a saved add-on into a usable form rule.
 *
 * Older add-on rows may already contain `system` / `none` values even when
 * their name clearly represents the built-in Extra Video or Extra Photos
 * capability. This fallback keeps those legacy rows working in both the Add
 * Boat and Edit Boat forms without requiring the administrator to recreate
 * the add-on.
 *
 * @param array<string,mixed> $addon Add-on row or calculated item.
 * @return array{addon_type:string,field_effect:string,effect_value:int}
 */
function ringo_resolve_addon_form_rule( $addon ) {
	$addon = is_array( $addon ) ? $addon : [];
	$id    = ringo_normalize_addon_id( $addon['id'] ?? '' );
	$name  = sanitize_text_field( (string) ( $addon['name'] ?? '' ) );
	$type  = sanitize_key( (string) ( $addon['addon_type'] ?? 'system' ) );
	$effect = sanitize_key( (string) ( $addon['field_effect'] ?? 'none' ) );
	$value = max( 0, (int) ( $addon['effect_value'] ?? 0 ) );

	if ( 'form' === $type && in_array( $effect, [ 'gallery_images', 'video_fields' ], true ) && $value > 0 ) {
		return [
			'addon_type'   => $type,
			'field_effect' => $effect,
			'effect_value' => $value,
		];
	}

	$identity   = sanitize_title( trim( $id . '-' . $name ) );
	$is_extra   = (bool) preg_match( '/(^|-)(extra|additional|add)(-|$)/', $identity );
	$is_video   = false !== strpos( $identity, 'video' );
	$is_gallery = false !== strpos( $identity, 'photo' ) || false !== strpos( $identity, 'image' ) || false !== strpos( $identity, 'gallery' );

	if ( $is_extra && $is_video ) {
		return [
			'addon_type'   => 'form',
			'field_effect' => 'video_fields',
			'effect_value' => max( 1, $value ),
		];
	}

	if ( $is_extra && $is_gallery ) {
		return [
			'addon_type'   => 'form',
			'field_effect' => 'gallery_images',
			'effect_value' => max( 1, $value ?: 10 ),
		];
	}

	return [
		'addon_type'   => in_array( $type, [ 'form', 'system' ], true ) ? $type : 'system',
		'field_effect' => in_array( $effect, [ 'none', 'gallery_images', 'video_fields' ], true ) ? $effect : 'none',
		'effect_value' => $value,
	];
}

/**
 * Sanitize add-on rows from the admin screen.
 *
 * @param mixed $rows Raw rows.
 * @return array<string,array<string,mixed>>
 */
function ringo_sanitize_addon_rows( $rows ) {
	$clean = [];
	if ( ! is_array( $rows ) ) {
		return $clean;
	}

	foreach ( $rows as $index => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		if ( '' === $name ) {
			continue;
		}

		$id = ringo_normalize_addon_id( $row['id'] ?? $name );
		if ( '' === $id ) {
			$id = 'addon-' . absint( $index );
		}

		// Prevent a newly added row from replacing an existing row with the same ID.
		$base_id = $id;
		$suffix  = 2;
		while ( isset( $clean[ $id ] ) ) {
			$id = $base_id . '-' . $suffix;
			$suffix++;
		}

		$legacy       = ringo_infer_legacy_addon_rules( $id );
		$addon_type   = sanitize_key( (string) ( $row['addon_type'] ?? $legacy['addon_type'] ) );
		$field_effect = sanitize_key( (string) ( $row['field_effect'] ?? $legacy['field_effect'] ) );
		$effect_value = isset( $row['effect_value'] ) ? absint( $row['effect_value'] ) : (int) $legacy['effect_value'];

		if ( ! in_array( $addon_type, [ 'form', 'system' ], true ) ) {
			$addon_type = 'system';
		}
		if ( ! in_array( $field_effect, [ 'none', 'gallery_images', 'video_fields' ], true ) ) {
			$field_effect = 'none';
		}
		if ( 'form' !== $addon_type ) {
			$field_effect = 'none';
			$effect_value = 0;
		}
		if ( 'none' === $field_effect ) {
			$effect_value = 0;
		}

		$resolved_rule = ringo_resolve_addon_form_rule(
			[
				'id'           => $id,
				'name'         => $name,
				'addon_type'   => $addon_type,
				'field_effect' => $field_effect,
				'effect_value' => $effect_value,
			]
		);
		$addon_type   = $resolved_rule['addon_type'];
		$field_effect = $resolved_rule['field_effect'];
		$effect_value = $resolved_rule['effect_value'];

		$price = isset( $row['price'] ) ? (float) $row['price'] : 0;
		$clean[ $id ] = [
			'id'           => $id,
			'name'         => $name,
			'description'  => sanitize_textarea_field( (string) ( $row['description'] ?? '' ) ),
			'price'        => max( 0, round( $price, 2 ) ),
			'enabled'      => empty( $row['enabled'] ) ? 0 : 1,
			'sort'         => isset( $row['sort'] ) ? (int) $row['sort'] : 100,
			'addon_type'   => $addon_type,
			'field_effect' => $field_effect,
			'effect_value' => $effect_value,
		];
	}

	uasort(
		$clean,
		static function ( $first, $second ) {
			$sort = (int) $first['sort'] <=> (int) $second['sort'];
			return 0 !== $sort ? $sort : strcasecmp( (string) $first['name'], (string) $second['name'] );
		}
	);

	return $clean;
}

/**
 * Return configured add-ons.
 *
 * @param bool $active_only Exclude disabled add-ons.
 * @return array<string,array<string,mixed>>
 */
function ringo_get_addons( $active_only = true ) {
	$addons = get_option( 'ringo_checkout_addons', [] );
	$addons = ringo_sanitize_addon_rows( $addons );

	if ( ! $active_only ) {
		return $addons;
	}

	return array_filter(
		$addons,
		static function ( $addon ) {
			return ! empty( $addon['enabled'] );
		}
	);
}

/**
 * Convert a request value into unique add-on IDs.
 *
 * @param mixed $value Request value. Defaults to addon_ids in POST.
 * @return array<int,string>
 */
function ringo_get_requested_addon_ids( $value = null ) {
	if ( null === $value ) {
		$value = $_POST['addon_ids'] ?? []; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\s,]+/', $value );
	}
	if ( ! is_array( $value ) ) {
		return [];
	}

	$ids = [];
	foreach ( $value as $item ) {
		$id = ringo_normalize_addon_id( wp_unslash( $item ) );
		if ( $id ) {
			$ids[] = $id;
		}
	}

	return array_values( array_unique( $ids ) );
}

/**
 * Calculate selected add-ons using server-side prices.
 *
 * @param mixed $requested_ids Add-on IDs.
 * @return array{ids:array<int,string>,items:array<int,array<string,mixed>>,total:float}
 */
function ringo_calculate_addons( $requested_ids ) {
	$requested = ringo_get_requested_addon_ids( $requested_ids );
	$available = ringo_get_addons( true );
	$items     = [];
	$ids       = [];
	$total     = 0.0;

	foreach ( $requested as $id ) {
		if ( ! isset( $available[ $id ] ) ) {
			continue;
		}
		$addon   = $available[ $id ];
		$price   = max( 0, (float) $addon['price'] );
		$ids[]   = $id;
		$items[] = [
			'id'           => $id,
			'name'         => (string) $addon['name'],
			'description'  => (string) $addon['description'],
			'price'        => $price,
			'addon_type'   => (string) $addon['addon_type'],
			'field_effect' => (string) $addon['field_effect'],
			'effect_value' => (int) $addon['effect_value'],
		];
		$total += $price;
	}

	return [
		'ids'   => $ids,
		'items' => $items,
		'total' => round( $total, 2 ),
	];
}

/**
 * Return the form capabilities created by selected add-ons.
 *
 * @param mixed $requested_ids Add-on IDs.
 * @return array{gallery_images:int,video_fields:int,form_addon_ids:array<int,string>,system_addon_ids:array<int,string>}
 */
function ringo_get_addon_form_effects( $requested_ids ) {
	$calculated = ringo_calculate_addons( $requested_ids );
	$effects    = [
		'gallery_images'  => 0,
		'video_fields'    => 0,
		'form_addon_ids'  => [],
		'system_addon_ids'=> [],
	];

	foreach ( $calculated['items'] as $item ) {
		$id   = (string) ( $item['id'] ?? '' );
		$rule = ringo_resolve_addon_form_rule( $item );
		$type = $rule['addon_type'];

		if ( 'form' !== $type ) {
			$effects['system_addon_ids'][] = $id;
			continue;
		}

		$effects['form_addon_ids'][] = $id;
		$effect = $rule['field_effect'];
		$value  = $rule['effect_value'];
		if ( isset( $effects[ $effect ] ) && is_int( $effects[ $effect ] ) ) {
			$effects[ $effect ] += $value;
		}
	}

	$effects['form_addon_ids']   = array_values( array_filter( array_unique( $effects['form_addon_ids'] ) ) );
	$effects['system_addon_ids'] = array_values( array_filter( array_unique( $effects['system_addon_ids'] ) ) );

	return $effects;
}

/**
 * Calculate the complete pre-coupon checkout total.
 *
 * @param string $package_label Package key.
 * @param mixed  $addon_ids     Selected add-ons.
 * @param float  $fallback_base Optional base package fallback.
 * @return array<string,mixed>
 */
function ringo_get_checkout_totals( $package_label, $addon_ids = [], $fallback_base = 0.0 ) {
	$base = ringo_get_package_price( $package_label );
	// SECURITY: the price must come from the plugin settings. A browser-supplied
	// "fallback" price used to be honoured for any unknown package name, which let a
	// buyer publish a listing for any amount. Legacy sites that rely on client-sent
	// prices can opt back in with: add_filter( 'ringo_allow_client_price_fallback', '__return_true' );
	if ( $base <= 0 && $fallback_base > 0 && apply_filters( 'ringo_allow_client_price_fallback', false, $package_label ) ) {
		$base = (float) $fallback_base;
	}
	$addons = ringo_calculate_addons( $addon_ids );

	return [
		'base'         => round( max( 0, $base ), 2 ),
		'addon_ids'    => $addons['ids'],
		'addon_items'  => $addons['items'],
		'addons_total' => $addons['total'],
		'subtotal'     => round( max( 0, $base ) + $addons['total'], 2 ),
	];
}

/**
 * Store the selected add-on snapshot on a boat.
 *
 * @param int   $post_id Boat ID.
 * @param mixed $addon_ids Add-on IDs.
 * @param float $base_amount Base package amount.
 * @return array<string,mixed>
 */
function ringo_save_boat_addons( $post_id, $addon_ids, $base_amount = 0.0 ) {
	$addons  = ringo_calculate_addons( $addon_ids );
	$effects = ringo_get_addon_form_effects( $addons['ids'] );
	update_post_meta( $post_id, '_ringo_addon_ids', $addons['ids'] );
	update_post_meta( $post_id, '_ringo_selected_addons', $addons['items'] );
	update_post_meta( $post_id, '_ringo_addons_total', $addons['total'] );
	update_post_meta( $post_id, '_ringo_addon_form_effects', $effects );
	if ( $base_amount >= 0 ) {
		update_post_meta( $post_id, '_ringo_base_package_amount', round( (float) $base_amount, 2 ) );
		update_post_meta( $post_id, '_ringo_checkout_subtotal', round( (float) $base_amount + $addons['total'], 2 ) );
	}
	return $addons;
}

/**
 * Return saved add-on IDs for a boat.
 *
 * @param int $post_id Boat ID.
 * @return array<int,string>
 */
function ringo_get_boat_addon_ids( $post_id ) {
	return ringo_get_requested_addon_ids( get_post_meta( $post_id, '_ringo_addon_ids', true ) );
}

/**
 * Return a short add-on summary for admin screens and emails.
 *
 * @param int $post_id Boat ID.
 * @return string
 */
function ringo_get_boat_addons_summary( $post_id ) {
	$items = get_post_meta( $post_id, '_ringo_selected_addons', true );
	if ( ! is_array( $items ) || ! $items ) {
		return 'None';
	}
	$parts = [];
	foreach ( $items as $item ) {
		if ( ! is_array( $item ) || empty( $item['name'] ) ) {
			continue;
		}
		$parts[] = sprintf( '%s ($%0.2f)', (string) $item['name'], (float) ( $item['price'] ?? 0 ) );
	}
	return $parts ? implode( ', ', $parts ) : 'None';
}

/**
 * Public services catalog.
 *
 * Selections continue to the new-listing package/add-on step. The add-boat
 * form applies form add-ons to gallery and video capabilities and includes all
 * selected add-ons in checkout.
 *
 * @return string
 */
function ringo_addon_services_shortcode() {
	$addons = ringo_get_addons( true );
	if ( ! $addons ) {
		return '';
	}

	wp_enqueue_style(
		'ringo-native-forms',
		RINGO_CHECKOUT_URL . 'frontend/assets/native-forms.css',
		[],
		RINGO_CHECKOUT_VERSION
	);

	$target       = home_url( '/add-boat/' );
	$instance     = 'ringo-addon-shop-' . wp_rand( 1000, 999999 );
	$radio_name   = 'ringo_addon_purchase_type_' . wp_rand( 1000, 999999 );
	$existing_html = function_exists( 'ringo_render_existing_boat_addon_shop' )
		? ringo_render_existing_boat_addon_shop( $addons, true )
		: '';

	ob_start();
	?>
	<div id="<?php echo esc_attr( $instance . '-page' ); ?>" class="ringo-addon-purchase-panel" data-ringo-addon-page>
		<h2>Choose How You Want to Buy Add-ons</h2>
		<p>Select whether these add-ons are for a new listing or one of your published boats.</p>

		<div class="ringo-addon-purchase-type" role="radiogroup" aria-label="Add-on purchase type">
			<label class="ringo-addon-purchase-choice is-selected">
				<input type="radio" name="<?php echo esc_attr( $radio_name ); ?>" value="new" checked>
				<span>
					<strong>New listing</strong>
					<small>Choose add-ons before creating a new boat listing.</small>
				</span>
			</label>
			<label class="ringo-addon-purchase-choice">
				<input type="radio" name="<?php echo esc_attr( $radio_name ); ?>" value="existing">
				<span>
					<strong>Existing published boat</strong>
					<small>Buy add-ons for a boat that is already live.</small>
				</span>
			</label>
		</div>

		<div class="ringo-addon-mode-panel" data-ringo-addon-mode="new">
			<h3>Add-ons for a New Listing</h3>
			<p>Select optional form upgrades and services before you create the listing.</p>
			<form id="<?php echo esc_attr( $instance ); ?>" class="ringo-addon-services-shop" action="<?php echo esc_url( $target ); ?>" method="get">
				<div class="ringo-addon-services-grid">
					<?php foreach ( $addons as $addon ) : ?>
						<label class="ringo-addon-service-card">
							<input class="ringo-addon-service-check" type="checkbox" name="ringo_addons[]" value="<?php echo esc_attr( $addon['id'] ); ?>" data-price="<?php echo esc_attr( number_format( (float) $addon['price'], 2, '.', '' ) ); ?>">
							<span class="ringo-addon-service-select"><?php echo 'form' === $addon['addon_type'] ? 'Form add-on' : 'Service add-on'; ?></span>
							<h3><?php echo esc_html( $addon['name'] ); ?></h3>
							<div class="ringo-addon-service-price"><?php echo esc_html( '$' . number_format_i18n( (float) $addon['price'], 2 ) ); ?></div>
							<p><?php echo esc_html( $addon['description'] ); ?></p>
							<?php if ( 'form' === $addon['addon_type'] && 'none' !== $addon['field_effect'] ) : ?>
								<small class="ringo-addon-service-effect">
									<?php
									if ( 'gallery_images' === $addon['field_effect'] ) {
										echo esc_html( '+' . (int) $addon['effect_value'] . ' gallery photos' );
									} elseif ( 'video_fields' === $addon['field_effect'] ) {
										echo esc_html( '+' . (int) $addon['effect_value'] . ' video field' . ( 1 === (int) $addon['effect_value'] ? '' : 's' ) );
									}
									?>
								</small>
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="ringo-addon-services-actions">
					<div><span>Selected add-ons</span><strong data-ringo-services-total>$0.00</strong></div>
					<button type="submit">Continue to Package and Listing</button>
				</div>
			</form>
		</div>

		<div class="ringo-addon-mode-panel" data-ringo-addon-mode="existing" hidden>
			<?php echo $existing_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>
	</div>
	<script>
	(function(){
		var root = document.getElementById(<?php echo wp_json_encode( $instance . '-page' ); ?>);
		if (!root) return;
		var form = document.getElementById(<?php echo wp_json_encode( $instance ); ?>);
		var total = form ? form.querySelector('[data-ringo-services-total]') : null;
		var radios = root.querySelectorAll('input[type="radio"][name=<?php echo wp_json_encode( $radio_name ); ?>]');
		var panels = root.querySelectorAll('[data-ringo-addon-mode]');

		function updateTotal(){
			if (!form) return;
			var amount = 0;
			var checks = form.querySelectorAll('.ringo-addon-service-check:checked');
			for (var i = 0; i < checks.length; i++) amount += parseFloat(checks[i].getAttribute('data-price') || '0') || 0;
			if (total) total.textContent = '$' + amount.toFixed(2);
		}

		function switchMode(mode){
			for (var i = 0; i < panels.length; i++) {
				panels[i].hidden = panels[i].getAttribute('data-ringo-addon-mode') !== mode;
			}
			var choices = root.querySelectorAll('.ringo-addon-purchase-choice');
			for (var j = 0; j < choices.length; j++) {
				var input = choices[j].querySelector('input[type="radio"]');
				choices[j].classList.toggle('is-selected', !!input && input.checked);
			}
		}

		for (var i = 0; i < radios.length; i++) {
			radios[i].addEventListener('change', function(){ switchMode(this.value); });
		}
		if (form) form.addEventListener('change', updateTotal);
		updateTotal();
		switchMode('new');
	})();
	</script>
	<?php
	return ob_get_clean();
}
add_shortcode( 'ringo_addon_services', 'ringo_addon_services_shortcode' );
