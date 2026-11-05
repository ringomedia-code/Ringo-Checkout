<?php
/**
 * Admin Boat Logs page.
 *
 * Displays a rolling log of boat listing activity: submissions, edits,
 * background photo uploads, recovery/cron runs, and payment/draft state
 * changes. Kept separate from Email Logs (which is only ever actual
 * wp_mail() send/skip/fail records) so a 10-minute cron heartbeat or a burst
 * of submission rejections never crowds a real email failure out of the
 * 200-entry cap before anyone notices it.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the Boat Logs admin page.
 */
function ringo_render_boat_logs_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle clear logs action.
	if (
		isset( $_POST['ringo_clear_boat_logs'] ) &&
		check_admin_referer( 'ringo_clear_boat_logs_nonce', 'ringo_clear_boat_logs_nonce' )
	) {
		delete_option( 'ringo_boat_logs' );
		echo '<div class="notice notice-success is-dismissible"><p>Logs cleared.</p></div>';
	}

	$logs = get_option( 'ringo_boat_logs', [] );

	// Classify each entry for display.
	$badge = function ( $message ) {
		if ( stripos( $message, 'rejected' ) !== false || stripos( $message, 'REFUSED' ) !== false ) {
			return [ 'color' => '#c0392b', 'bg' => '#fdecea', 'label' => 'REJECTED' ];
		}
		if ( stripos( $message, 'failed' ) !== false || stripos( $message, 'stuck' ) !== false || stripos( $message, 'error' ) !== false ) {
			return [ 'color' => '#c0392b', 'bg' => '#fdecea', 'label' => 'FAILED' ];
		}
		if ( stripos( $message, 'recovered' ) !== false || stripos( $message, 'completed' ) !== false || stripos( $message, 'published' ) !== false ) {
			return [ 'color' => '#1a7f4b', 'bg' => '#eafaf1', 'label' => 'OK' ];
		}
		if ( stripos( $message, 'scheduled' ) !== false || stripos( $message, 'running' ) !== false || stripos( $message, 'no unpaid' ) !== false || stripos( $message, 'skipping' ) !== false || stripos( $message, 'already' ) !== false ) {
			return [ 'color' => '#7f6000', 'bg' => '#fef9e7', 'label' => 'INFO' ];
		}
		return [ 'color' => '#2c3e50', 'bg' => '#eaf0fb', 'label' => 'ACTIVITY' ];
	};

	?>
	<div class="wrap">
		<h1 style="display:flex;align-items:center;gap:10px;">
			Boat Logs
			<span style="font-size:13px;font-weight:400;color:#666;background:#f0f0f0;padding:3px 10px;border-radius:20px;">
				<?php echo count( $logs ); ?> / 200 entries
			</span>
		</h1>

		<p style="color:#555;margin-top:-6px;">
			Boat listing activity recorded by this plugin: new submissions, edits, background photo
			uploads, recovery/cron runs, and payment or draft state changes - everything except actual
			email sends, which are on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=ringo-email-logs' ) ); ?>">Email Logs</a>
			page instead. Check <strong>REJECTED</strong> rows for a submission that never became a
			listing at all. Logs are stored in the database (newest first, capped at 200).
		</p>

		<form method="post" style="margin-bottom:16px;">
			<?php wp_nonce_field( 'ringo_clear_boat_logs_nonce', 'ringo_clear_boat_logs_nonce' ); ?>
			<button type="submit" name="ringo_clear_boat_logs" class="button button-secondary"
				onclick="return confirm('Clear all logs?');">
				Clear all logs
			</button>
		</form>

		<?php if ( empty( $logs ) ) : ?>
			<div style="background:#fff;border:1px solid #e0e0e0;border-radius:4px;padding:40px;text-align:center;color:#888;">
				No logs yet. Logs appear here as soon as a seller submits, edits, or uploads photos for a listing.
			</div>
		<?php else : ?>

		<table class="widefat striped" style="border-radius:4px;overflow:hidden;">
			<thead>
				<tr>
					<th style="width:160px;">Time</th>
					<th style="width:90px;">Status</th>
					<th>Message</th>
					<th>Detail</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $logs as $entry ) :
				$b       = $badge( $entry['message'] ?? '' );
				$time    = isset( $entry['time'] ) ? esc_html( $entry['time'] ) : '—';
				$message = isset( $entry['message'] ) ? esc_html( $entry['message'] ) : '—';
				$data    = $entry['data'] ?? null;

				$detail = '';
				if ( is_array( $data ) ) {
					$parts = [];
					foreach ( $data as $k => $v ) {
						if ( $v === null || $v === '' ) continue;
						$parts[] = '<strong>' . esc_html( $k ) . ':</strong> ' . esc_html( is_array( $v ) ? wp_json_encode( $v ) : (string) $v );
					}
					$detail = implode( ' &nbsp;·&nbsp; ', $parts );
				} elseif ( $data !== null ) {
					$detail = esc_html( (string) $data );
				}
			?>
				<tr>
					<td style="font-size:12px;color:#555;white-space:nowrap;"><?php echo $time; ?></td>
					<td>
						<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;letter-spacing:.04em;background:<?php echo esc_attr( $b['bg'] ); ?>;color:<?php echo esc_attr( $b['color'] ); ?>;">
							<?php echo esc_html( $b['label'] ); ?>
						</span>
					</td>
					<td style="font-size:13px;"><?php echo $message; ?></td>
					<td style="font-size:12px;color:#555;"><?php echo $detail; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php endif; ?>
	</div>
	<?php
}
