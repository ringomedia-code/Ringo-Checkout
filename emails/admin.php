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
