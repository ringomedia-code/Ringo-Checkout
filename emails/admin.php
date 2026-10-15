<?php
/**
 * Admin new-listing notification email.
 *
 * Sent to the site administrator when a boat listing is paid and published.
 *
 * @package RingoCheckout
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send the site administrator a detailed notification of the new paid listing.
 *
 * @param  int    $post_id
 * @param  string $package_name
 * @param  float  $package_price
 * @return bool
 */
function ringo_send_admin_listing_email( $post_id, $package_name, $package_price ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		ringo_log( 'Admin email: post not found', $post_id );
		return false;
	}

	if ( get_post_meta( $post_id, '_ringo_admin_email_sent', true ) ) {
		ringo_log( 'Admin email: already sent, skipping', $post_id );
		return true;
	}

	$to      = get_option( 'admin_email' );
	$subject = 'A new listing has been submitted on your site';

	// ── Meta helpers ──────────────────────────────────────────────────────────

	$get_meta = function ( $key ) use ( $post_id ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( is_array( $v ) ) {
			$v = implode( ', ', array_map( 'sanitize_text_field', $v ) );
		}
		return sanitize_text_field( trim( (string) $v ) );
	};

	/**
	 * Find a taxonomy slug by matching its singular or plural label (case-insensitive).
	 *
	 * @param  string $post_type
	 * @param  string $label
	 * @return string Taxonomy slug, or empty string if not found.
	 */
	$find_tax = function ( $post_type, $label ) {
		$taxes = get_object_taxonomies( $post_type, 'objects' );
		$label = strtolower( trim( $label ) );

		foreach ( $taxes as $slug => $obj ) {
			if (
				strtolower( $obj->labels->singular_name ?? '' ) === $label ||
				strtolower( $obj->labels->name ?? '' )          === $label ||
				strtolower( $slug )                             === $label
			) {
				return $slug;
			}
		}
		return '';
	};

	$terms_by_slug = function ( $slug ) use ( $post_id ) {
		if ( ! $slug ) {
			return '';
		}
		$terms = wp_get_post_terms( $post_id, $slug, [ 'fields' => 'names' ] );
		return ( ! is_wp_error( $terms ) && $terms ) ? implode( ', ', $terms ) : '';
	};

	// ── Taxonomy slugs ────────────────────────────────────────────────────────

	$pt = $post->post_type;

	$tax = [
		'state'      => $find_tax( $pt, 'State' ),
		'boat_make'  => $find_tax( $pt, 'Boat Make' ),
		'boat_year'  => $find_tax( $pt, 'Boat Year' ),
		'motor_make' => $find_tax( $pt, 'Motor Make' ),
		'motor_year' => 'motor-year',
		'category'   => 'boatcategories',
		'status'     => 'boat-status',
		'ownership'  => 'boat-ownership',
	];

	// ── Reply-To ──────────────────────────────────────────────────────────────

	$reply_to = ringo_get_form_email( $post_id );

	// ── Data map ─────────────────────────────────────────────────────────────

	$provider = (string) get_post_meta( $post_id, '_ringo_payment_provider', true );
	$view_url = get_permalink( $post_id ) ?: home_url( '/' );

	$data = [
		'title'         => get_the_title( $post_id ),
		'boat_category' => $terms_by_slug( $tax['category'] ),
		'boat_status'   => $terms_by_slug( $tax['status'] ),
		'boat_ownership'=> $terms_by_slug( $tax['ownership'] ),
		'first_name'    => $get_meta( 'first_name' ),
		'last_name'     => $get_meta( 'last_name' ),
		'email'         => $reply_to ?: $get_meta( 'email' ),
		'phone'         => $get_meta( 'phone' ),
		'city'          => $get_meta( 'city' ),
		'state'         => $terms_by_slug( $tax['state'] ),
		'price'         => $get_meta( 'price' ),
		'boat_make'     => $terms_by_slug( $tax['boat_make'] ),
		'boat_model'    => $get_meta( 'boat_model' ),
		'boat_year'     => $terms_by_slug( $tax['boat_year'] ),
		'motor_make'    => $terms_by_slug( $tax['motor_make'] ),
		'motor_model'   => $get_meta( 'motor_model' ),
		'motor_year'    => $terms_by_slug( $tax['motor_year'] ),
		'motor_hours'   => $get_meta( 'motor_hours' ),
		'package_name'  => (string) $package_name,
		'package_price' => number_format( (float) $package_price, 2 ),
		'addons'        => ringo_get_boat_addons_summary( $post_id ),
		'user_id'       => (string) ( $post->post_author ?? '' ),
		'post_id'       => (string) $post_id,
		'payment_method'=> $provider ? ucfirst( $provider ) : 'Stripe',
		'view_url'      => $view_url,
	];

	// ── Build HTML ────────────────────────────────────────────────────────────

	$d  = function ( $key ) use ( $data ) {
		return esc_html( $data[ $key ] ?? '' );
	};

	ob_start();
	?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>New Boat Listing Submission</title></head>
<body style="font-family:Arial,sans-serif;color:#333;margin:0;padding:0;background:#f6f6f6;">
  <div style="max-width:600px;margin:20px auto;background:#fff;border:1px solid #e0e0e0;padding:20px;border-radius:5px;">

    <h2 style="color:#0a6ebd;">New Boat Listing Submission</h2>
    <p>Dear BassBoat4Sale,</p>
    <p>A new submission has been made on your site with the details below.</p>

    <p style="margin:18px 0 6px 0;">
      <a href="<?php echo esc_url( $data['view_url'] ); ?>"
         style="display:inline-block;padding:10px 18px;background:#0a6ebd;color:#fff;text-decoration:none;border-radius:4px;">
        View listing
      </a>
    </p>
    <p style="margin:0 0 14px 0;font-size:12px;color:#666;">
      If the button does not work, copy and paste: <?php echo esc_url( $data['view_url'] ); ?>
    </p>

    <?php
	$section = function ( $heading, array $rows ) use ( $d ) {
		echo '<h3 style="color:#0a6ebd;border-bottom:2px solid #0a6ebd;padding-bottom:5px;margin-top:25px;">' . esc_html( $heading ) . '</h3>';
		echo '<table style="width:100%;border-collapse:collapse;margin-top:15px;">';
		foreach ( $rows as $label => $value ) {
			echo '<tr>';
			echo '<td style="padding:8px 10px;border-bottom:1px solid #eee;"><strong>' . esc_html( $label ) . ':</strong></td>';
			echo '<td style="padding:8px 10px;border-bottom:1px solid #eee;">' . $value . '</td>';
			echo '</tr>';
		}
		echo '</table>';
	};

	$section( 'Listing Information', [
		'Boat Title'    => $d( 'title' ),
		'Boat Category' => $d( 'boat_category' ),
		'Boat Status'   => $d( 'boat_status' ),
		'Ownership'     => $d( 'boat_ownership' ),
		'View Listing'  => '<a href="' . esc_url( $data['view_url'] ) . '">' . esc_html( $data['view_url'] ) . '</a>',
	] );

	$section( 'Contact Information', [
		'First Name' => $d( 'first_name' ),
		'Last Name'  => $d( 'last_name' ),
		'Email'      => $d( 'email' ),
		'Phone'      => $d( 'phone' ),
		'City'       => $d( 'city' ),
		'State'      => $d( 'state' ),
	] );

	$section( 'Boat Details', [
		'Price'      => '$' . $d( 'price' ),
		'Boat Make'  => $d( 'boat_make' ),
		'Boat Model' => $d( 'boat_model' ),
		'Boat Year'  => $d( 'boat_year' ),
	] );

	$section( 'Motor Information', [
		'Motor Make'  => $d( 'motor_make' ),
		'Motor Model' => $d( 'motor_model' ),
		'Motor Year'  => $d( 'motor_year' ),
		'Motor Hours' => $d( 'motor_hours' ),
	] );

	$section( 'Package Information', [
		'Package Name' => $d( 'package_name' ),
		'Add-ons'      => $d( 'addons' ),
		'Total Paid'   => '$' . $d( 'package_price' ),
	] );

	$section( 'Additional Information', [
		'User ID'        => $d( 'user_id' ),
		'Post ID'        => $d( 'post_id' ),
		'Payment Method' => $d( 'payment_method' ),
	] );
	?>

    <p style="margin-top:25px;">Thank You,<br><strong>BassBoat4Sale</strong></p>
    <p style="color:#888;font-size:14px;">Submission ID: <?php echo $d( 'post_id' ); ?></p>

    <div style="text-align:center;font-size:12px;color:#888;margin-top:30px;padding-top:20px;border-top:1px solid #e0e0e0;">
      &copy; 2026 BassBoat4Sale. All rights reserved.
    </div>
  </div>
</body>
</html>
	<?php
	$html = ob_get_clean();

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: BassBoat4Sale <ammar@ringomedia.com>',
	];
	if ( $reply_to ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	$sent = wp_mail( $to, $subject, $html, $headers );

	ringo_log( 'Admin email: wp_mail result', [
		'sent'     => (bool) $sent,
		'to'       => $to,
		'reply_to' => $reply_to,
		'post_id'  => $post_id,
	] );

	if ( $sent ) {
		update_post_meta( $post_id, '_ringo_admin_email_sent', 1 );
	}

	return (bool) $sent;
}

/**
 * Send the administrator the detailed "listing edited" notification.
 *
 * Mirrors the former JetFormBuilder edit-form admin email (form 1811).
 *
 * @param  int $post_id Boat post ID.
 * @return bool
 */
function ringo_send_admin_edit_email( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return false;
	}

	$to = sanitize_email( (string) get_option( 'admin_email' ) );
	if ( ! $to || ! is_email( $to ) ) {
		return false;
	}

	$get_meta = function ( $key ) use ( $post_id ) {
		$v = get_post_meta( $post_id, $key, true );
		if ( is_array( $v ) ) {
			$v = implode( ', ', array_map( 'sanitize_text_field', $v ) );
		}
		return sanitize_text_field( trim( (string) $v ) );
	};

	$terms = function ( $taxonomy ) use ( $post_id ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}
		$t = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );
		return ( ! is_wp_error( $t ) && $t ) ? implode( ', ', $t ) : '';
	};

	$package   = function_exists( 'ringo_native_existing_boat_package' ) ? (string) ringo_native_existing_boat_package( $post_id, (int) $post->post_author ) : '';
	$author    = get_userdata( (int) $post->post_author );
	$reply_to  = sanitize_email( (string) get_post_meta( $post_id, 'email', true ) );
	$view_url  = get_permalink( $post_id ) ?: home_url( '/' );
	$price_raw = $get_meta( 'price' );
	$price     = ( '' === $price_raw || (float) $price_raw <= 0 ) ? 'Contact For Pricing' : '$' . number_format_i18n( (float) $price_raw, 2 );

	$data = [
		'title'        => get_the_title( $post_id ),
		'package'      => ucfirst( $package ),
		'boat_status'  => $terms( 'boat-status' ),
		'first_name'   => $get_meta( 'first_name' ),
		'last_name'    => $get_meta( 'last_name' ),
		'email'        => $reply_to ?: $get_meta( 'email' ),
		'phone'        => $get_meta( 'phone' ),
		'price'        => $price,
		'boat_make'    => $terms( 'boat-make' ),
		'boat_model'   => $get_meta( 'boat_model' ),
		'boat_year'    => $terms( 'boat-year' ),
		'motor_make'   => $terms( 'motor-make' ),
		'motor_model'  => $get_meta( 'motor_model' ),
		'motor_year'   => $terms( taxonomy_exists( 'motor-year' ) ? 'motor-year' : 'boat-year' ),
		'motor_hours'  => $get_meta( 'motor_hours' ),
		'user'         => $author ? $author->display_name . ' (#' . $author->ID . ')' : (string) $post->post_author,
		'post_id'      => (string) $post_id,
		'edit_date'    => wp_date( 'F j, Y g:i a' ),
	];

	$d = function ( $key ) use ( $data ) {
		return esc_html( $data[ $key ] ?? '' );
	};

	$section = function ( $heading, array $rows ) {
		$out  = '<h3 style="color: #1998D0; border-bottom: 2px solid #1998D0; padding-bottom: 5px; margin-top: 25px;">' . esc_html( $heading ) . '</h3>';
		$out .= '<table style="width: 100%; border-collapse: collapse; margin-top: 15px;">';
		$last = count( $rows ) - 1;
		$i    = 0;
		foreach ( $rows as $label => $value ) {
			$border = ( $i++ === $last ) ? '' : ' border-bottom: 1px solid #eeeeee;';
			$out   .= '<tr><td style="padding: 8px 10px;' . $border . ' width: 45%;"><strong>' . esc_html( $label ) . ':</strong></td>'
				. '<td style="padding: 8px 10px;' . $border . '">' . $value . '</td></tr>';
		}
		return $out . '</table>';
	};

	$body  = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Boat Listing Updated</title></head>';
	$body .= '<body style="font-family: Arial, sans-serif; color: #333333; margin: 0; padding: 0; background-color: #f6f6f6;">';
	$body .= '<div style="max-width: 600px; margin: 20px auto; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 5px; overflow: hidden;">';
	$body .= '<div style="background-color: #1998D0; padding: 20px; text-align: center; color: #ffffff; font-size: 22px; font-weight: bold;">Boat Listing Updated &mdash; Review Required</div>';
	$body .= '<div style="padding: 20px;"><p>Dear BassBoat4Sale,</p><p>A user has edited their boat listing. Please review the updated details below.</p>';
	$body .= $section( 'Listing Information', [
		'Post Title'   => $d( 'title' ),
		'Package Name' => $d( 'package' ),
		'Boat Status'  => $d( 'boat_status' ),
	] );
	$body .= $section( 'Contact Information', [
		'First Name' => $d( 'first_name' ),
		'Last Name'  => $d( 'last_name' ),
		'Email'      => $d( 'email' ),
		'Phone'      => $d( 'phone' ),
	] );
	$body .= $section( 'Boat Details', [
		'Price'      => $d( 'price' ),
		'Boat Make'  => $d( 'boat_make' ),
		'Boat Model' => $d( 'boat_model' ),
		'Boat Year'  => $d( 'boat_year' ),
	] );
	$body .= $section( 'Motor Information', [
		'Motor Make'  => $d( 'motor_make' ),
		'Motor Model' => $d( 'motor_model' ),
		'Motor Year'  => $d( 'motor_year' ),
		'Motor Hours' => $d( 'motor_hours' ),
	] );
	$body .= $section( 'Additional Information', [
		'User'      => $d( 'user' ),
		'Post ID'   => $d( 'post_id' ),
		'Edit Date' => $d( 'edit_date' ),
	] );
	$body .= '<p style="margin-top: 25px; text-align: center;"><a href="' . esc_url( $view_url ) . '" style="background-color: #1998D0; color: #ffffff; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-size: 16px; font-weight: bold; display: inline-block;">View Boat</a></p>';
	$body .= '<p style="margin-top: 25px;">Thank You,<br><strong>BassBoat4Sale</strong></p>';
	$body .= '<p style="color: #888888; font-size: 14px;">Post ID: ' . $d( 'post_id' ) . '</p></div>';
	$body .= '<div style="text-align: center; font-size: 12px; color: #888888; padding: 15px; border-top: 1px solid #e0e0e0;">&copy; ' . esc_html( wp_date( 'Y' ) ) . ' BassBoat4Sale. All rights reserved.</div>';
	$body .= '</div></body></html>';

	$subject = $post_id . ' - ' . html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' ) . ' - Was Updated';

	$headers = [
		'Content-Type: text/html; charset=UTF-8',
		'From: BassBoat4Sale <ammar@ringomedia.com>',
	];
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	$sent = wp_mail( $to, $subject, $body, $headers );
	ringo_log( 'Native edit admin email', [ 'post_id' => $post_id, 'sent' => (bool) $sent, 'recipient' => $to ] );

	return (bool) $sent;
}
