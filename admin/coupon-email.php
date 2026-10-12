<?php
/**
 * Coupon Email Sender
 *
 * Allows admin to email a coupon code to selected customers or custom addresses.
 * Email template is editable before sending, and saved edits become the new default.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Default template ─────────────────────────────────────────────────────────

/**
 * Return the coupon email template (custom saved one, or built-in default).
 */
function ringo_get_coupon_email_template() {
	$saved = get_option( 'ringo_coupon_email_template', '' );
	if ( $saved ) {
		return $saved;
	}
	return ringo_coupon_email_default_template();
}

function ringo_coupon_email_default_template() {
	return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;background:#f6f6f6;">
  <div style="max-width:600px;margin:20px auto;background:#fff;border:1px solid #e0e0e0;padding:30px;border-radius:5px;">
    <h2 style="color:#0a6ebd;">🎁 Special Offer Just for You!</h2>
    <p>Hi there,</p>
    <p>We have a special discount coupon for you to use on your next boat listing on <strong>BassBoat4Sale</strong>.</p>

    <div style="text-align:center;margin:24px 0;">
      <div style="display:inline-block;background:#f0f7ff;border:2px dashed #0a6ebd;border-radius:8px;padding:16px 32px;">
        <div style="font-size:13px;color:#555;margin-bottom:6px;">Your Coupon Code</div>
        <div style="font-size:28px;font-weight:bold;color:#0a6ebd;letter-spacing:3px;">{{COUPON_CODE}}</div>
        <div style="font-size:14px;color:#333;margin-top:6px;">{{COUPON_DISCOUNT}}</div>
      </div>
    </div>

    <p>To use your coupon, simply enter the code above at checkout when listing your boat.</p>

    <div style="text-align:center;margin:20px 0;">
      <a href="{{SITE_URL}}/add-boat/"
         style="display:inline-block;padding:12px 28px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:4px;font-size:15px;">
        List My Boat Now
      </a>
    </div>

    <p style="font-size:13px;color:#777;">If you have any questions, just reply to this email — we are happy to help!</p>

    <div style="margin-top:20px;padding:12px 16px;background:#fff8e1;border-left:4px solid #f9a825;border-radius:3px;">
      <p style="margin:0;font-size:13px;color:#555;">
        <strong style="color:#e65100;">&#9888;&#65039; FYI &mdash; BEWARE OF SCAMS!</strong>
        Bass Boat Reports do not exist, do not fall for any scams via Email / Call / Text!
      </p>
    </div>

    <div style="text-align:center;font-size:12px;color:#888;margin-top:20px;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>';
}

// ─── AJAX: save template ──────────────────────────────────────────────────────

add_action( 'wp_ajax_ringo_save_coupon_email_template', 'ringo_ajax_save_coupon_email_template' );

function ringo_ajax_save_coupon_email_template() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
	}
	check_ajax_referer( 'ringo_coupon_email_nonce', 'nonce' );

	$template = isset( $_POST['template'] ) ? wp_kses_post( wp_unslash( $_POST['template'] ) ) : '';
	update_option( 'ringo_coupon_email_template', $template );
	wp_send_json_success( [ 'message' => 'Template saved as default.' ] );
}

// ─── AJAX: send coupon emails ─────────────────────────────────────────────────

add_action( 'wp_ajax_ringo_send_coupon_emails', 'ringo_ajax_send_coupon_emails' );

function ringo_ajax_send_coupon_emails() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
	}
	check_ajax_referer( 'ringo_coupon_email_nonce', 'nonce' );

	$coupon_code = strtoupper( sanitize_text_field( (string) ( $_POST['coupon_code'] ?? '' ) ) );
	$emails_raw  = isset( $_POST['emails'] ) ? (array) $_POST['emails'] : [];
	$template    = isset( $_POST['template'] ) ? wp_kses_post( wp_unslash( $_POST['template'] ) ) : '';
	$subject     = sanitize_text_field( (string) ( $_POST['subject'] ?? 'Your Special Coupon Code from BassBoat4Sale' ) );
	$save_tpl    = ! empty( $_POST['save_template'] );

	if ( ! $coupon_code ) {
		wp_send_json_error( [ 'message' => 'No coupon code specified.' ] );
	}

	// Optionally save the template as the new default.
	if ( $save_tpl && $template ) {
		update_option( 'ringo_coupon_email_template', $template );
	}

	// Build final email list — sanitize and deduplicate.
	$send_to = [];
	foreach ( $emails_raw as $e ) {
		$e = sanitize_email( trim( (string) $e ) );
		if ( $e && is_email( $e ) && ! in_array( $e, $send_to, true ) ) {
			$send_to[] = $e;
		}
	}

	if ( empty( $send_to ) ) {
		wp_send_json_error( [ 'message' => 'No valid email addresses selected.' ] );
	}

	// Get coupon details for template substitution.
	$coupons = ringo_get_coupons();
	$c       = $coupons[ $coupon_code ] ?? null;
	if ( $c ) {
		$discount_label = ( $c['type'] === 'percent' )
			? (float) $c['value'] . '% off your next listing'
			: '$' . number_format( (float) $c['value'], 2 ) . ' off your next listing';
	} else {
		$discount_label = 'Special discount';
	}

	$sent_count   = 0;
	$failed_count = 0;
	$headers      = [
		'Content-Type: text/html; charset=UTF-8',
		'From: Bassboat4sale <ammar@ringomedia.com>',
	];

	foreach ( $send_to as $email ) {
		// Substitute placeholders.
		$html = str_replace(
			[ '{{COUPON_CODE}}', '{{COUPON_DISCOUNT}}', '{{SITE_URL}}', '{{EMAIL}}' ],
			[ esc_html( $coupon_code ), esc_html( $discount_label ), esc_url( home_url() ), esc_html( $email ) ],
			$template
		);

		$sent = wp_mail( $email, $subject, $html, $headers );
		if ( $sent ) {
			$sent_count++;
		} else {
			$failed_count++;
		}
	}

	wp_send_json_success( [
		'sent'   => $sent_count,
		'failed' => $failed_count,
		'total'  => count( $send_to ),
	] );
}

// ─── Helper: get ALL users (WP registered + checkout customers) ───────────────

function ringo_get_all_customer_emails() {
	$map = [];

	// 1. All WordPress registered users.
	$wp_users = get_users( [ 'number' => -1, 'fields' => [ 'ID', 'user_email', 'display_name', 'user_login' ] ] );
	foreach ( $wp_users as $u ) {
		$email = sanitize_email( $u->user_email );
		if ( ! $email ) continue;
		$user_data  = get_userdata( $u->ID );
		$first_name = (string) get_user_meta( $u->ID, 'first_name', true );
		$last_name  = (string) get_user_meta( $u->ID, 'last_name', true );
		$map[ $email ] = [
			'email'      => $email,
			'search'     => strtolower( $email . ' ' . $u->user_login . ' ' . $u->display_name . ' ' . $first_name . ' ' . $last_name ),
			'source'     => 'wp_user',
			'status'     => '',
		];
	}

	// 2. Checkout customers — enrich or add.
	$q = new WP_Query( [
		'post_type'      => 'boats',
		'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [ [ 'key' => '_ringo_checkout_status', 'compare' => 'EXISTS' ] ],
	] );
	foreach ( $q->posts as $id ) {
		$email = ringo_get_form_email( $id );
		if ( ! $email ) continue;
		$status = (string) get_post_meta( $id, '_ringo_checkout_status', true );
		if ( isset( $map[ $email ] ) ) {
			if ( ! $map[ $email ]['status'] ) $map[ $email ]['status'] = $status;
			$map[ $email ]['source'] = 'both';
		} else {
			$map[ $email ] = [
				'email'  => $email,
				'search' => strtolower( $email ),
				'source' => 'checkout',
				'status' => $status,
			];
		}
	}

	// Sort alphabetically by email.
	usort( $map, function( $a, $b ) {
		return strcasecmp( $a['email'], $b['email'] );
	} );

	return array_values( $map );
}

// ─── Page renderer ────────────────────────────────────────────────────────────

function ringo_render_coupon_email_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	// Set proper browser/page title.
	global $title;
	$title = 'Send Coupon Email';

	$coupon_code = isset( $_GET['coupon'] ) ? strtoupper( sanitize_text_field( $_GET['coupon'] ) ) : '';
	$coupons     = ringo_get_coupons();

	if ( ! $coupon_code || ! isset( $coupons[ $coupon_code ] ) ) {
		echo '<div class="wrap"><div class="notice notice-error"><p>Invalid coupon.</p></div></div>';
		return;
	}

	$c = $coupons[ $coupon_code ];
	$discount_label = ( $c['type'] === 'percent' )
		? (float) $c['value'] . '% off'
		: '$' . number_format( (float) $c['value'], 2 ) . ' off';

	$all_customers = ringo_get_all_customer_emails();
	$template      = ringo_get_coupon_email_template();
	$nonce         = wp_create_nonce( 'ringo_coupon_email_nonce' );
	$ajax_url      = admin_url( 'admin-ajax.php' );

	// Pre-fill placeholders in preview.
	$preview_html = str_replace(
		[ '{{COUPON_CODE}}', '{{COUPON_DISCOUNT}}', '{{SITE_URL}}', '{{EMAIL}}' ],
		[ esc_html( $coupon_code ), esc_html( $discount_label . ' your next listing' ), esc_url( home_url() ), 'customer@example.com' ],
		$template
	);
	?>
	<div class="wrap" id="ringoCouponEmailWrap">
		<h1>📧 Send Coupon — <strong><?php echo esc_html( $coupon_code ); ?></strong>
			<span style="font-size:14px;font-weight:normal;color:#555;margin-left:10px;"><?php echo esc_html( $discount_label ); ?></span>
		</h1>

		<div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

			<!-- LEFT: Recipient selection -->
			<div style="flex:0 0 340px;min-width:280px;">
				<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">
					<h3 style="margin:0 0 12px 0;">1. Select Recipients</h3>

					<!-- Search -->
					<div style="margin-bottom:10px;position:relative;">
						<input type="text" id="ringoUserSearch" placeholder="🔍 Search by name, email, role, package…" style="width:100%;padding:8px 10px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;" />
						<span id="ringoUserSearchCount" style="font-size:12px;color:#888;margin-top:4px;display:block;"></span>
					</div>

					<!-- Quick select -->
					<div style="margin-bottom:8px;font-size:12px;">
						<a href="#" id="ringoSelectAll">Select All Visible</a> &nbsp;|&nbsp;
						<a href="#" id="ringoSelectPaid">Paid Only</a> &nbsp;|&nbsp;
						<a href="#" id="ringoDeselectAll">Deselect All</a>
					</div>

					<!-- Customer list -->
					<?php if ( ! empty( $all_customers ) ) : ?>
					<div style="max-height:320px;overflow-y:auto;border:1px solid #eee;border-radius:4px;" id="ringoCustomerList">
						<?php foreach ( $all_customers as $customer ) : ?>
						<label class="ringo-user-row" data-search="<?php echo esc_attr( $customer['search'] ); ?>" data-status="<?php echo esc_attr( $customer['status'] ); ?>"
							   style="display:flex;align-items:center;gap:8px;padding:7px 6px;border-bottom:1px solid #f5f5f5;cursor:pointer;">
							<input type="checkbox" class="ringo-customer-cb" value="<?php echo esc_attr( $customer['email'] ); ?>" data-status="<?php echo esc_attr( $customer['status'] ); ?>" />
							<span style="font-size:13px;color:#111;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html( $customer['email'] ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>
					<p style="font-size:12px;color:#888;margin:4px 0 0 0;"><?php echo count( $all_customers ); ?> total users</p>
					<?php else : ?>
					<p style="color:#888;font-size:13px;">No users found.</p>
					<?php endif; ?>

					<!-- Custom email input -->
					<div style="margin-top:14px;">
						<label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Add Custom Email(s)</label>
						<textarea id="ringoCustomEmails" rows="3" placeholder="one@example.com&#10;two@example.com" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;resize:vertical;"></textarea>
						<p style="font-size:11px;color:#888;margin:3px 0 0 0;">One email per line.</p>
					</div>

					<!-- Subject line -->
					<div style="margin-top:14px;">
						<label style="font-weight:600;font-size:13px;display:block;margin-bottom:4px;">Email Subject</label>
						<input type="text" id="ringoCouponSubject" value="Your Special Coupon Code from BassBoat4Sale" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px;font-size:13px;box-sizing:border-box;" />
					</div>

					<div style="margin-top:16px;">
						<button type="button" id="ringoCouponSendBtn" class="button button-primary" style="width:100%;padding:10px;font-size:14px;">
							📤 Send Email
						</button>
					</div>
					<div id="ringoCouponSendResult" style="display:none;margin-top:10px;padding:10px;border-radius:4px;font-size:13px;"></div>
				</div>
			</div>

			<!-- RIGHT: Template editor + preview -->
			<div style="flex:1;min-width:320px;">
				<div style="background:#fff;border:1px solid #ddd;border-radius:6px;padding:16px;">
					<h3 style="margin:0 0 4px 0;">2. Edit Email Template</h3>
					<p style="font-size:12px;color:#888;margin:0 0 10px 0;">
						Available placeholders: <code>{{COUPON_CODE}}</code> <code>{{COUPON_DISCOUNT}}</code> <code>{{SITE_URL}}</code> <code>{{EMAIL}}</code>
					</p>

					<!-- Tab buttons -->
					<div style="display:flex;gap:0;margin-bottom:-1px;position:relative;z-index:1;">
						<button type="button" id="ringoTabEdit" style="padding:7px 18px;border:1px solid #ddd;border-bottom:1px solid #fff;background:#fff;cursor:pointer;border-radius:4px 4px 0 0;font-size:13px;font-weight:600;">✏️ Edit</button>
						<button type="button" id="ringoTabPreview" style="padding:7px 18px;border:1px solid #ddd;border-bottom:1px solid #ddd;background:#f9f9f9;cursor:pointer;border-radius:4px 4px 0 0;font-size:13px;margin-left:4px;">👁 Preview</button>
					</div>

					<div style="border:1px solid #ddd;border-radius:0 4px 4px 4px;overflow:hidden;">
						<!-- Edit tab -->
						<div id="ringoEditTab">
							<textarea id="ringoCouponTemplate" rows="22" style="width:100%;padding:12px;border:none;outline:none;font-size:12px;font-family:monospace;box-sizing:border-box;resize:vertical;"><?php echo esc_textarea( $template ); ?></textarea>
						</div>
						<!-- Preview tab -->
						<div id="ringoPreviewTab" style="display:none;">
							<iframe id="ringoCouponPreviewFrame" style="width:100%;height:460px;border:none;" srcdoc=""></iframe>
						</div>
					</div>

					<div style="display:flex;gap:10px;margin-top:10px;align-items:center;">
						<button type="button" id="ringoCouponSaveTemplate" class="button">💾 Save as Default Template</button>
						<button type="button" id="ringoCouponResetTemplate" class="button" style="color:#c00;border-color:#c00;">↺ Reset to Default</button>
						<span id="ringoCouponTemplateSaved" style="display:none;color:#0a0;font-size:13px;">✔ Saved!</span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<script>
	(function($){
		var nonce      = <?php echo wp_json_encode( $nonce ); ?>;
		var ajaxUrl    = <?php echo wp_json_encode( $ajax_url ); ?>;
		var couponCode = <?php echo wp_json_encode( $coupon_code ); ?>;
		var defaultTpl = <?php echo wp_json_encode( ringo_coupon_email_default_template() ); ?>;

		// ── Live search ───────────────────────────────────────────────────────
		function updateSearchCount() {
			var visible = $('.ringo-user-row:visible').length;
			var total   = $('.ringo-user-row').length;
			$('#ringoUserSearchCount').text(visible === total ? total + ' users' : visible + ' of ' + total + ' shown');
		}
		updateSearchCount();

		$('#ringoUserSearch').on('input', function(){
			var q = $(this).val().toLowerCase().trim();
			$('.ringo-user-row').each(function(){
				var haystack = $(this).data('search') || '';
				$(this).toggle( !q || haystack.indexOf(q) !== -1 );
			});
			updateSearchCount();
		});

		// ── Tabs ────────────────────────────────────────────────────────────
		$('#ringoTabEdit').on('click', function(){
			$(this).css({'border-bottom-color':'#fff','background':'#fff'});
			$('#ringoTabPreview').css({'border-bottom-color':'#ddd','background':'#f9f9f9'});
			$('#ringoEditTab').show();
			$('#ringoPreviewTab').hide();
		});

		$('#ringoTabPreview').on('click', function(){
			$(this).css({'border-bottom-color':'#fff','background':'#fff'});
			$('#ringoTabEdit').css({'border-bottom-color':'#ddd','background':'#f9f9f9'});
			$('#ringoEditTab').hide();
			$('#ringoPreviewTab').show();
			// Render live preview with current template content
			var tpl = $('#ringoCouponTemplate').val();
			var html = tpl
				.replace(/\{\{COUPON_CODE\}\}/g, couponCode)
				.replace(/\{\{COUPON_DISCOUNT\}\}/g, 'Special discount on your next listing')
				.replace(/\{\{SITE_URL\}\}/g, window.location.origin)
				.replace(/\{\{EMAIL\}\}/g, 'customer@example.com');
			$('#ringoCouponPreviewFrame').attr('srcdoc', html);
		});

		// ── Select All / Paid / Deselect ─────────────────────────────────────
		$('#ringoSelectAll').on('click', function(e){
			e.preventDefault();
			$('.ringo-user-row:visible .ringo-customer-cb').prop('checked', true);
		});
		$('#ringoSelectPaid').on('click', function(e){
			e.preventDefault();
			$('.ringo-customer-cb').prop('checked', false);
			$('.ringo-user-row:visible .ringo-customer-cb[data-status="paid"]').prop('checked', true);
		});
		$('#ringoDeselectAll').on('click', function(e){
			e.preventDefault();
			$('.ringo-customer-cb').prop('checked', false);
		});

		// ── Save template ─────────────────────────────────────────────────────
		$('#ringoCouponSaveTemplate').on('click', function(){
			var tpl = $('#ringoCouponTemplate').val();
			$(this).prop('disabled', true).text('Saving…');
			$.post(ajaxUrl, {
				action:   'ringo_save_coupon_email_template',
				nonce:    nonce,
				template: tpl
			}, function(res){
				$('#ringoCouponSaveTemplate').prop('disabled', false).text('💾 Save as Default Template');
				if (res && res.success){
					$('#ringoCouponTemplateSaved').show().delay(2500).fadeOut();
				} else {
					alert('Save failed.');
				}
			});
		});

		// ── Reset template ────────────────────────────────────────────────────
		$('#ringoCouponResetTemplate').on('click', function(){
			if (!confirm('Reset to the original built-in template? This will clear any saved custom template.')) return;
			$('#ringoCouponTemplate').val(defaultTpl);
			$.post(ajaxUrl, {
				action:   'ringo_save_coupon_email_template',
				nonce:    nonce,
				template: ''   // empty = use built-in default
			});
		});

		// ── Send emails ───────────────────────────────────────────────────────
		$('#ringoCouponSendBtn').on('click', function(){
			// Collect checked customers
			var emails = [];
			$('.ringo-customer-cb:checked').each(function(){
				emails.push($(this).val());
			});

			// Add custom emails
			var custom = ($('#ringoCustomEmails').val() || '').split('\n');
			$.each(custom, function(_, e){
				e = $.trim(e);
				if (e) emails.push(e);
			});

			if (!emails.length){
				alert('Please select at least one recipient or enter a custom email.');
				return;
			}

			var subject  = $('#ringoCouponSubject').val() || 'Your Special Coupon Code from BassBoat4Sale';
			var template = $('#ringoCouponTemplate').val();

			$(this).prop('disabled', true).text('Sending…');
			$('#ringoCouponSendResult').hide();

			$.post(ajaxUrl, {
				action:      'ringo_send_coupon_emails',
				nonce:       nonce,
				coupon_code: couponCode,
				emails:      emails,
				subject:     subject,
				template:    template
			}, function(res){
				$('#ringoCouponSendBtn').prop('disabled', false).text('📤 Send Email');
				var $result = $('#ringoCouponSendResult');
				if (res && res.success){
					var d = res.data;
					var msg = '✔ Done! Sent: ' + d.sent + '  |  Failed: ' + d.failed + '  |  Total: ' + d.total;
					$result.css({'background': d.failed ? '#fff3cd' : '#d4edda', 'color': d.failed ? '#856404' : '#155724', 'border': '1px solid ' + (d.failed ? '#ffeeba' : '#c3e6cb')}).text(msg).show();
				} else {
					var errMsg = (res && res.data && res.data.message) ? res.data.message : 'Send failed.';
					$result.css({'background':'#f8d7da','color':'#721c24','border':'1px solid #f5c6cb'}).text('✘ ' + errMsg).show();
				}
			}).fail(function(){
				$('#ringoCouponSendBtn').prop('disabled', false).text('📤 Send Email');
				$('#ringoCouponSendResult').css({'background':'#f8d7da','color':'#721c24','border':'1px solid #f5c6cb'}).text('✘ Server error. Please try again.').show();
			});
		});

	})(jQuery);
	</script>
	<?php
}
