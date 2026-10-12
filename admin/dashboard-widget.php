<?php
/**
 * Dashboard widgets for Ringo checkout - Same design as Latest Transactions.
 *
 * Two widgets:
 * 1. Paid Boats - Shows only paid boats
 * 2. Action Required - Shows unpaid + payment_failed boats
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Register widgets
add_action( 'wp_dashboard_setup', 'ringo_register_dashboard_widgets' );

/**
 * Register the two dashboard widgets.
 */
function ringo_register_dashboard_widgets() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'ringo-paid-boats',
		'Paid Boats',
		'ringo_render_paid_boats_widget'
	);

	wp_add_dashboard_widget(
		'ringo-action-required',
		'Action Required (Unpaid + Failed)',
		'ringo_render_action_required_widget'
	);
}

// ─────────────────────────────────────────────────────────────────────────────
// Widget 1: Paid Boats
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Render the Paid Boats widget.
 */
function ringo_render_paid_boats_widget() {
	$args = [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => [ 'draft', 'publish', 'pending', 'private' ],
		'posts_per_page' => 10,
		'meta_query'     => [
			[
				'key'   => '_ringo_checkout_status',
				'value' => 'paid',
			]
		],
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		echo '<p style="color:#666;padding:20px;text-align:center;">No paid boats yet.</p>';
		return;
	}

	?>
	<table style="width:100%; border-collapse:collapse;">
		<thead>
			<tr style="border-bottom:1px solid #eee;">
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">ID</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Title</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Package</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Amount</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Date</th>
			</tr>
		</thead>
		<tbody>
			<?php
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$title = get_the_title( $post_id );
				if ( strlen( $title ) > 30 ) {
					$title = substr( $title, 0, 27 ) . '...';
				}

				$package = (string) get_post_meta( $post_id, '_ringo_package', true );
				$package_display = $package ? ucfirst( $package ) : '—';

				$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
				if ( $amount <= 0 && $package ) {
					$amount = ringo_get_package_price( $package );
				}
				$amount_display = $amount > 0 ? '$' . number_format( $amount, 2 ) : '';

				$date = get_the_date( 'Y-m-d H:i', $post_id );

				$edit_url = get_edit_post_link( $post_id );

				?>
				<tr style="border-bottom:1px solid #f0f0f0;">
					<td style="padding:10px; font-size:13px;"><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $post_id ); ?></a></td>
					<td style="padding:10px; font-size:13px;"><a href="<?php echo esc_url( $edit_url ); ?>" style="color:#0073aa;text-decoration:none;"><?php echo esc_html( $title ); ?></a></td>
					<td style="padding:10px; font-size:13px;"><?php echo esc_html( $package_display ); ?></td>
					<td style="padding:10px; font-size:13px;"><?php echo esc_html( $amount_display ); ?></td>
					<td style="padding:10px; font-size:13px;"><?php echo esc_html( $date ); ?></td>
				</tr>
				<?php
			}
			?>
		</tbody>
	</table>

	<div style="padding:12px 10px; text-align:right; border-top:1px solid #eee;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ringo-stripe-payments&status=paid' ) ); ?>" style="color:#0073aa;text-decoration:none;font-size:13px;">View all payments →</a>
	</div>

	<?php
	wp_reset_postdata();
}

// ─────────────────────────────────────────────────────────────────────────────
// Widget 2: Action Required (Unpaid + Payment Failed)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Render the Action Required widget (Unpaid + Payment Failed boats).
 */
function ringo_render_action_required_widget() {
	$args = [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => [ 'draft', 'publish', 'pending', 'private' ],
		'posts_per_page' => 10,
		'meta_query'     => [
			[
				'key'     => '_ringo_checkout_status',
				'value'   => [ 'unpaid', 'payment_failed' ],
				'compare' => 'IN',
			]
		],
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	$query = new WP_Query( $args );

	if ( ! $query->have_posts() ) {
		echo '<p style="color:#0a0; font-weight:600; padding:20px; text-align:center;">✓ All boats are paid or being processed!</p>';
		return;
	}

	?>
	<table style="width:100%; border-collapse:collapse;">
		<thead>
			<tr style="border-bottom:1px solid #eee;">
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">ID</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Title</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Status</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Amount</th>
				<th style="text-align:left; padding:10px; font-weight:600; font-size:13px; color:#333;">Date</th>
			</tr>
		</thead>
		<tbody>
			<?php
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id = get_the_ID();

				$status = (string) get_post_meta( $post_id, '_ringo_checkout_status', true );
				$status_display = ( $status === 'unpaid' ) ? 'unpaid' : 'payment_failed';
				$status_color = ( $status === 'unpaid' ) ? '#ff9800' : '#d32f2f';

				$title = get_the_title( $post_id );
				if ( strlen( $title ) > 30 ) {
					$title = substr( $title, 0, 27 ) . '...';
				}

				$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
				$package = (string) get_post_meta( $post_id, '_ringo_package', true );
				if ( $amount <= 0 && $package ) {
					$amount = ringo_get_package_price( $package );
				}
				$amount_display = $amount > 0 ? '$' . number_format( $amount, 2 ) : '';

				$date = get_the_date( 'Y-m-d H:i', $post_id );

				$edit_url = get_edit_post_link( $post_id );

				?>
				<tr style="border-bottom:1px solid #f0f0f0;">
					<td style="padding:10px; font-size:13px;"><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $post_id ); ?></a></td>
					<td style="padding:10px; font-size:13px;"><a href="<?php echo esc_url( $edit_url ); ?>" style="color:#0073aa;text-decoration:none;"><?php echo esc_html( $title ); ?></a></td>
					<td style="padding:10px; font-size:13px; color:<?php echo esc_attr( $status_color ); ?>; font-weight:600;"><?php echo esc_html( $status_display ); ?></td>
					<td style="padding:10px; font-size:13px;"><?php echo esc_html( $amount_display ); ?></td>
					<td style="padding:10px; font-size:13px;"><?php echo esc_html( $date ); ?></td>
				</tr>
				<?php
			}
			?>
		</tbody>
	</table>

	<div style="padding:12px 10px; text-align:right; border-top:1px solid #eee;">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=ringo-stripe-payments' ) ); ?>" style="color:#0073aa;text-decoration:none;font-size:13px;">View all payments →</a>
	</div>

	<?php
	wp_reset_postdata();
}