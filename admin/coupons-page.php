<?php
/**
 * Admin coupon management page.
 *
 * Coupons are stored in the WP option 'ringo_coupons' as an associative array:
 *   [ 'COUPONCODE' => [
 *       'type'               => 'percent'|'fixed',
 *       'value'              => float,
 *       'uses'               => int,           // total uses across all users
 *       'max_uses'           => int|0,          // 0 = unlimited total
 *       'max_uses_per_user'  => int|0,          // 0 = unlimited per user
 *       'user_uses'          => [ 'email@x' => int, ... ],  // per-email use counts
 *       'active'             => bool,
 *   ] ]
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── AJAX: validate coupon (frontend) ────────────────────────────────────────

add_action( 'wp_ajax_ringo_validate_coupon',        'ringo_ajax_validate_coupon' );
add_action( 'wp_ajax_nopriv_ringo_validate_coupon', 'ringo_ajax_validate_coupon' );

// Alias: the frontend JS posts action=ringo_apply_coupon — map it to the same handler.
add_action( 'wp_ajax_ringo_apply_coupon',        'ringo_ajax_validate_coupon' );
add_action( 'wp_ajax_nopriv_ringo_apply_coupon', 'ringo_ajax_validate_coupon' );

function ringo_ajax_validate_coupon() {
	if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'ringo_coupon_nonce' ) ) {
		wp_send_json_error( [ 'message' => 'Security check failed.' ] );
	}

	$code  = strtoupper( sanitize_text_field( trim( (string) ( $_POST['coupon_code'] ?? '' ) ) ) );
	$price = isset( $_POST['package_price'] ) ? (float) $_POST['package_price'] : 0;
	$email = isset( $_POST['user_email'] ) ? sanitize_email( (string) $_POST['user_email'] ) : '';

	if ( ! $code ) {
		wp_send_json_error( [ 'message' => 'Please enter a coupon code.' ] );
	}

	$result = ringo_apply_coupon( $code, $price, $email );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ] );
	}

	wp_send_json_success( $result );
}

/**
 * Validate a coupon code and calculate the discounted price.
 *
 * @param  string $code  Uppercase coupon code.
 * @param  float  $price Original price.
 * @param  string $email Customer email (used for per-user limit check).
 * @return array|WP_Error  On success: [ 'discount' => float, 'final_price' => float, 'label' => string ]
 */
function ringo_apply_coupon( $code, $price, $email = '' ) {
	$coupons = ringo_get_coupons();

	if ( ! isset( $coupons[ $code ] ) ) {
		return new WP_Error( 'invalid', 'Invalid coupon code.' );
	}

	$c = $coupons[ $code ];

	if ( empty( $c['active'] ) ) {
		return new WP_Error( 'inactive', 'This coupon is no longer active.' );
	}

	// Check total usage limit.
	if ( ! empty( $c['max_uses'] ) && (int) $c['uses'] >= (int) $c['max_uses'] ) {
		return new WP_Error( 'expired', 'This coupon has reached its usage limit.' );
	}

	// Check per-user usage limit. SECURITY: the limit used to be skipped whenever the
	// request left the email blank. Fall back to the signed-in user's email, and if
	// we still cannot tell who is redeeming, refuse rather than skip the check.
	if ( ! empty( $c['max_uses_per_user'] ) && ! $email && is_user_logged_in() ) {
		$email = (string) wp_get_current_user()->user_email;
	}
	if ( ! empty( $c['max_uses_per_user'] ) && ! $email ) {
		return new WP_Error( 'email_required', 'Please enter your email address to use this coupon.' );
	}
	if ( ! empty( $c['max_uses_per_user'] ) && $email ) {
		$email_key  = strtolower( $email );
		$user_uses  = isset( $c['user_uses'][ $email_key ] ) ? (int) $c['user_uses'][ $email_key ] : 0;
		if ( $user_uses >= (int) $c['max_uses_per_user'] ) {
			return new WP_Error( 'per_user_limit', 'You have already used this coupon the maximum number of times.' );
		}
	}

	$discount = 0;
	if ( $c['type'] === 'percent' ) {
		$discount = round( $price * ( (float) $c['value'] / 100 ), 2 );
		$label    = (float) $c['value'] . '% off';
	} else {
		$discount = min( (float) $c['value'], $price );
		$label    = '$' . number_format( (float) $c['value'], 2 ) . ' off';
	}

	$final = max( 0, round( $price - $discount, 2 ) );

	return [
		'discount'    => $discount,
		'final_price' => $final,
		'label'       => $label,
		'code'        => $code,
	];
}

/**
 * Increment the use count of a coupon after a successful payment.
 *
 * @param string $code  Uppercase coupon code.
 * @param string $email Customer email for per-user tracking.
 */
function ringo_increment_coupon_use( $code, $email = '' ) {
	if ( ! $code ) return;
	$coupons = ringo_get_coupons();
	$code    = strtoupper( $code );
	if ( isset( $coupons[ $code ] ) ) {
		// Increment total uses.
		$coupons[ $code ]['uses'] = (int) ( $coupons[ $code ]['uses'] ?? 0 ) + 1;

		// Increment per-user uses if email is known.
		if ( $email ) {
			$email_key = strtolower( sanitize_email( $email ) );
			if ( $email_key ) {
				if ( ! isset( $coupons[ $code ]['user_uses'] ) || ! is_array( $coupons[ $code ]['user_uses'] ) ) {
					$coupons[ $code ]['user_uses'] = [];
				}
				$coupons[ $code ]['user_uses'][ $email_key ] = (int) ( $coupons[ $code ]['user_uses'][ $email_key ] ?? 0 ) + 1;
			}
		}

		update_option( 'ringo_coupons', $coupons );
	}
}

/**
 * Return all stored coupons.
 *
 * @return array
 */
function ringo_get_coupons() {
	$coupons = get_option( 'ringo_coupons', [] );
	return is_array( $coupons ) ? $coupons : [];
}

// ─── Admin page handler ───────────────────────────────────────────────────────

/**
 * Handle coupon create / delete / toggle actions submitted from the admin page.
 */
function ringo_handle_coupon_admin_actions() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// Create coupon.
	if ( isset( $_POST['ringo_coupon_action'] ) && $_POST['ringo_coupon_action'] === 'create' ) {
		check_admin_referer( 'ringo_coupon_create' );

		$code               = strtoupper( preg_replace( '/[^A-Z0-9_\-]/i', '', trim( (string) ( $_POST['coupon_code'] ?? '' ) ) ) );
		$type               = ( ( $_POST['coupon_type'] ?? '' ) === 'fixed' ) ? 'fixed' : 'percent';
		$value              = max( 0, (float) ( $_POST['coupon_value'] ?? 0 ) );
		$max_uses           = max( 0, (int) ( $_POST['coupon_max_uses'] ?? 0 ) );
		$max_uses_per_user  = max( 0, (int) ( $_POST['coupon_max_uses_per_user'] ?? 0 ) );

		if ( $code && $value > 0 ) {
			$coupons          = ringo_get_coupons();
			$coupons[ $code ] = [
				'type'              => $type,
				'value'             => $value,
				'uses'              => 0,
				'max_uses'          => $max_uses,
				'max_uses_per_user' => $max_uses_per_user,
				'user_uses'         => [],
				'active'            => true,
			];
			update_option( 'ringo_coupons', $coupons );
			wp_redirect( admin_url( 'admin.php?page=ringo-coupons&ringo_coupon_saved=' . urlencode( $code ) ) );
			exit;
		} else {
			wp_redirect( admin_url( 'admin.php?page=ringo-coupons&ringo_coupon_error=1' ) );
			exit;
		}
	}

	// Delete coupon.
	if ( isset( $_GET['ringo_delete_coupon'] ) && isset( $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'ringo_delete_coupon' ) ) {
			$code    = strtoupper( sanitize_text_field( $_GET['ringo_delete_coupon'] ) );
			$coupons = ringo_get_coupons();
			unset( $coupons[ $code ] );
			update_option( 'ringo_coupons', $coupons );
		}
	}

	// Toggle active.
	if ( isset( $_GET['ringo_toggle_coupon'] ) && isset( $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( $_GET['_wpnonce'], 'ringo_toggle_coupon' ) ) {
			$code    = strtoupper( sanitize_text_field( $_GET['ringo_toggle_coupon'] ) );
			$coupons = ringo_get_coupons();
			if ( isset( $coupons[ $code ] ) ) {
				$coupons[ $code ]['active'] = ! $coupons[ $code ]['active'];
				update_option( 'ringo_coupons', $coupons );
			}
		}
	}
}
add_action( 'admin_init', 'ringo_handle_coupon_admin_actions' );

/**
 * Render the Coupons admin page.
 */
function ringo_render_coupons_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	$coupons  = ringo_get_coupons();
	$base_url = admin_url( 'admin.php?page=ringo-coupons' );
	?>
	<div class="wrap">
		<h1>Ringo Checkout — Coupon Codes</h1>

		<?php if ( isset( $_GET['ringo_coupon_saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>✔ Coupon <strong><?php echo esc_html( strtoupper( $_GET['ringo_coupon_saved'] ) ); ?></strong> saved successfully.</p></div>
		<?php elseif ( isset( $_GET['ringo_coupon_error'] ) ) : ?>
			<div class="notice notice-error is-dismissible"><p>✘ Could not save coupon. Make sure the code and discount value are filled in.</p></div>
		<?php endif; ?>

		<h2>Create New Coupon</h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'ringo_coupon_create' ); ?>
			<input type="hidden" name="ringo_coupon_action" value="create" />
			<table class="form-table" style="max-width:100%;">
				<tr>
					<th><label for="coupon_code">Coupon Code</label></th>
					<td><input type="text" id="coupon_code" name="coupon_code" class="regular-text" placeholder="e.g. SUMMER25" style="text-transform:uppercase;" required />
					<p class="description">Letters, numbers, hyphens and underscores only. Will be stored uppercase.</p></td>
				</tr>
				<tr>
					<th><label for="coupon_type">Discount Type</label></th>
					<td>
						<select id="coupon_type" name="coupon_type">
							<option value="percent">Percentage (%)</option>
							<option value="fixed">Fixed amount ($)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="coupon_value">Discount Value</label></th>
					<td><input type="number" id="coupon_value" name="coupon_value" step="0.01" min="0.01" class="small-text" required />
					<p class="description">Enter the percentage (e.g. 25 for 25%) or dollar amount (e.g. 20 for $20 off).</p></td>
				</tr>
				<tr>
					<th><label for="coupon_max_uses">Max Uses (Total)</label></th>
					<td><input type="number" id="coupon_max_uses" name="coupon_max_uses" min="0" value="0" class="small-text" />
					<p class="description">0 = unlimited total uses across all customers.</p></td>
				</tr>
				<tr>
					<th><label for="coupon_max_uses_per_user">Max Uses Per Customer</label></th>
					<td><input type="number" id="coupon_max_uses_per_user" name="coupon_max_uses_per_user" min="0" value="1" class="small-text" />
					<p class="description">How many times one customer (by email) can use this coupon. 0 = unlimited per customer.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Create Coupon', 'primary', 'submit', false ); ?>
		</form>

		<hr />
		<h2>Existing Coupons</h2>

		<?php if ( empty( $coupons ) ) : ?>
			<p>No coupons created yet.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:100%;">
				<thead>
					<tr>
						<th>Code</th>
						<th>Type</th>
						<th>Value</th>
						<th>Uses</th>
						<th>Max Uses (Total)</th>
						<th>Max Per Customer</th>
						<th>Status</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $coupons as $code => $c ) :
					$toggle_url      = wp_nonce_url( $base_url . '&ringo_toggle_coupon=' . urlencode( $code ), 'ringo_toggle_coupon' );
					$delete_url      = wp_nonce_url( $base_url . '&ringo_delete_coupon=' . urlencode( $code ), 'ringo_delete_coupon' );
					$active          = ! empty( $c['active'] );
					$value_fmt       = ( $c['type'] === 'percent' ) ? esc_html( $c['value'] ) . '%' : '$' . number_format( (float) $c['value'], 2 );
					$max_fmt         = empty( $c['max_uses'] ) ? '∞' : (int) $c['max_uses'];
					$max_user_fmt    = empty( $c['max_uses_per_user'] ) ? '∞' : (int) $c['max_uses_per_user'];
				?>
					<tr>
						<td><strong><?php echo esc_html( $code ); ?></strong></td>
						<td><?php echo $c['type'] === 'percent' ? 'Percentage' : 'Fixed $'; ?></td>
						<td><?php echo esc_html( $value_fmt ); ?></td>
						<td><?php echo (int) ( $c['uses'] ?? 0 ); ?></td>
						<td><?php echo esc_html( $max_fmt ); ?></td>
						<td><?php echo esc_html( $max_user_fmt ); ?></td>
						<td>
							<span style="color:<?php echo $active ? '#0a0' : '#c00'; ?>;font-weight:bold;">
								<?php echo $active ? '✔ Active' : '✘ Inactive'; ?>
							</span>
						</td>
						<td>
							<a href="<?php echo esc_url( $toggle_url ); ?>"><?php echo $active ? 'Deactivate' : 'Activate'; ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( $delete_url ); ?>" style="color:#c00;" onclick="return confirm('Delete coupon <?php echo esc_js( $code ); ?>?');">Delete</a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=ringo-coupon-email&coupon=' . urlencode( $code ) ) ); ?>" style="color:#0a6ebd;font-weight:600;">📧 Email</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}