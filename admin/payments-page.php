<?php
/**
 * Admin payments tracker page.
 *
 * Lists all boat posts that have been touched by the checkout flow, with
 * status filters and paginated results.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Payments admin page.
 */
function ringo_render_payments_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// ── Filters and pagination ────────────────────────────────────────────────

	$allowed_statuses = [ 'all', 'paid', 'unpaid', 'payment_failed' ];
	$status_filter    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'all';
	if ( ! in_array( $status_filter, $allowed_statuses, true ) ) {
		$status_filter = 'all';
	}

	$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
	$per_page = 25;

	$meta_query = ( $status_filter !== 'all' )
		? [ [ 'key' => '_ringo_checkout_status', 'value' => $status_filter ] ]
		: [ [ 'key' => '_ringo_checkout_status', 'compare' => 'EXISTS' ] ];

	$q = new WP_Query( [
		'post_type'      => ringo_boat_post_types(),
		'post_status'    => [ 'draft', 'publish', 'pending', 'private', 'trash' ],
		'posts_per_page' => $per_page,
		'paged'          => $paged,
		'fields'         => 'ids',
		'meta_query'     => $meta_query,
		'orderby'        => 'date',
		'order'          => 'DESC',
	] );

	$base_url = admin_url( 'admin.php?page=ringo-stripe-payments' );
	?>
	<div class="wrap">
		<h1>Ringo Custom Checkout — Payments</h1>

		<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>Deleted listing ID: <strong><?php echo esc_html( (int) $_GET['deleted'] ); ?></strong></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $_GET['marked_paid'] ) ) : ?>
			<div class="notice notice-success is-dismissible">
				<p>Marked listing ID <strong><?php echo esc_html( (int) $_GET['marked_paid'] ); ?></strong> as paid.</p>
			</div>
		<?php endif; ?>

		<!-- Status filter tabs -->
		<div style="margin:12px 0;">
			<?php
			$tabs = [
				'all'             => 'All',
				'paid'            => 'Paid',
				'unpaid'          => 'Unpaid',
				'payment_failed'  => 'Payment Failed',
			];
			foreach ( $tabs as $value => $label ) {
				$url    = ( $value === 'all' ) ? $base_url : add_query_arg( 'status', $value, $base_url );
				$active = ( $status_filter === $value ) ? 'button-primary' : '';
				printf(
					'<a class="button %s" href="%s">%s</a> ',
					esc_attr( $active ),
					esc_url( $url ),
					esc_html( $label )
				);
			}
			?>
		</div>

		<!-- Payments table -->
		<table class="widefat fixed striped">
			<thead>
			<tr>
				<th style="width:80px;">Post ID</th>
				<th style="width:90px;">Post Status</th>
				<th style="width:90px;">Pay Status</th>
				<th style="width:80px;">Provider</th>
				<th style="width:60px;">Form</th>
				<th style="width:130px;">Package</th>
				<th style="width:180px;">Add-ons</th>
				<th style="width:90px;">Amount</th>
				<th style="width:220px;">Email</th>
				<th style="width:160px;">Created</th>
				<th style="width:200px;">Actions</th>
			</tr>
			</thead>
			<tbody>
			<?php
			if ( empty( $q->posts ) ) {
				echo '<tr><td colspan="11">No records found.</td></tr>';
			} else {
				foreach ( $q->posts as $post_id ) {
					ringo_render_payment_row( $post_id );
				}
			}
			?>
			</tbody>
		</table>

		<!-- Pagination -->
		<?php
		$total_pages = (int) $q->max_num_pages;
		if ( $total_pages > 1 ) {
			$paginate_base = ( $status_filter !== 'all' )
				? add_query_arg( 'status', $status_filter, $base_url )
				: $base_url;

			echo '<div style="margin-top:15px;">';
			echo paginate_links( [
				'base'      => add_query_arg( 'paged', '%#%', $paginate_base ),
				'format'    => '',
				'prev_text' => '« Prev',
				'next_text' => 'Next »',
				'total'     => $total_pages,
				'current'   => $paged,
			] );
			echo '</div>';
		}
		?>
	</div>
	<?php
}

/**
 * Output a single `<tr>` for a boat post in the payments tracker.
 *
 * @param int $post_id
 */
function ringo_render_payment_row( $post_id ) {
	$post = get_post( $post_id );

	$pay_status  = (string) get_post_meta( $post_id, '_ringo_checkout_status',   true );
	$provider    = (string) get_post_meta( $post_id, '_ringo_payment_provider',  true );
	$form_id     = (string) get_post_meta( $post_id, '_ringo_form_id',           true );
	$package     = (string) get_post_meta( $post_id, '_ringo_package',           true );

	$amount = (float) get_post_meta( $post_id, '_ringo_amount', true );
	if ( $amount <= 0 && $package ) {
		$amount = ringo_get_package_price( $package );
	}
	$amount_display = $amount > 0 ? '$' . number_format( $amount, 2 ) : '';
	$addons_display = ringo_get_boat_addons_summary( $post_id );

	$email = ringo_get_form_email( $post_id );

	$created_at    = (int) get_post_meta( $post_id, '_ringo_checkout_created_at', true );
	$created_human = $created_at ? date_i18n( 'Y-m-d H:i', $created_at ) : '';

	// ✨ NEW: Show "Created at" date for draft boats
	if ( ! $created_human && $post && $post->post_status === 'draft' ) {
		$created_human = date_i18n( 'Y-m-d H:i', strtotime( $post->post_date ) );
	}

	// ✨ NEW: Get boat video URL
	$video_url = (string) get_post_meta( $post_id, '_boat_video_url', true );
	if ( ! $video_url ) {
		$video_url = (string) get_post_meta( $post_id, 'boat_video_url', true );
	}

	$nonce            = wp_create_nonce( 'ringo_delete_payment_' . $post_id );
	$delete_base_args = [
		'page'         => 'ringo-stripe-payments',
		'ringo_action' => 'delete_payment_row',
		'post_id'      => $post_id,
		'_wpnonce'     => $nonce,
	];

	$trash_url = add_query_arg( $delete_base_args, admin_url( 'admin.php' ) );
	$force_url = add_query_arg( array_merge( $delete_base_args, [ 'force' => 1 ] ), admin_url( 'admin.php' ) );

	$mark_paid_url = add_query_arg(
		[
			'page'         => 'ringo-stripe-payments',
			'ringo_action' => 'mark_paid_row',
			'post_id'      => $post_id,
			'_wpnonce'     => wp_create_nonce( 'ringo_mark_paid_' . $post_id ),
		],
		admin_url( 'admin.php' )
	);

	echo '<tr>';
	echo '<td>' . esc_html( $post_id ) . '</td>';
	echo '<td>' . esc_html( $post ? $post->post_status : '—' ) . '</td>';
	echo '<td>' . esc_html( $pay_status ?: '—' ) . '</td>';
	echo '<td>' . esc_html( $provider  ?: '—' ) . '</td>';
	echo '<td>' . esc_html( $form_id   ?: '—' ) . '</td>';
	echo '<td>' . esc_html( $package ) . '</td>';
	echo '<td>' . esc_html( $addons_display ) . '</td>';
	echo '<td>' . esc_html( $amount_display ) . '</td>';
	echo '<td>' . esc_html( $email ) . '</td>';
	echo '<td>' . esc_html( $created_human ) . '</td>';
	echo '<td>' .
		// ✨ View Boat button (no icon)
		'<a class="button button-small" href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" rel="noopener" style="color:#0a6ebd;border-color:#0a6ebd;">View</a> ' .
		( $pay_status !== 'paid'
			? '<a class="button button-small" style="color:#0a8043;border-color:#0a8043;" href="' . esc_url( $mark_paid_url ) . '" ' .
				'onclick="return confirm(\'Mark listing ' . esc_js( $post_id ) . ' as PAID? This will publish it and send the payment confirmation emails.\');">Mark Paid</a> '
			: ''
		) .
		'<a class="button button-small" href="' . esc_url( $trash_url ) . '" ' .
			'onclick="return confirm(\'Trash listing ' . esc_js( $post_id ) . '?\');">Trash</a> ' .
		'<a class="button button-small" style="color:#b32d2e;border-color:#b32d2e;" href="' . esc_url( $force_url ) . '" ' .
			'onclick="return confirm(\'Permanently delete listing ' . esc_js( $post_id ) . '? This cannot be undone.\');">Delete</a>' .
	'</td>';
	echo '</tr>';
}