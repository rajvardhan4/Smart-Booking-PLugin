<?php
/**
 * Template: upgrade-banner.php
 *
 * Renders the smart bundle upgrade banner.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( empty( $data ) ) {
	return;
}

$bundle_id           = $data['bundle_id'];
$bundle_name         = $data['bundle_name'];
$savings             = $data['savings'];
$missing_products    = $data['missing_products']; // Array of product names
$missing_product_ids = $data['missing_product_ids']; // Array of product IDs
$missing_names_str   = implode( ' & ', $missing_products );
?>

<div class="sob-upgrade-banner" data-bundle-id="<?php echo esc_attr( $bundle_id ); ?>" data-missing-ids="<?php echo esc_attr( wp_json_encode( $missing_product_ids ) ); ?>">
	
	<div class="sob-banner-icon-wrap">
		<span class="dashicons dashicons-tag sob-banner-dashicon" style="font-size: 22px; width: 22px; height: 22px; display: inline-block; color: currentColor;"></span>
	</div>

	<div class="sob-banner-content">
		<h4 class="sob-banner-title"><?php esc_html_e( 'Unlock Bundle Discount!', 'smart-order-builder' ); ?></h4>
		<p class="sob-banner-text">
			<?php
			printf(
				/* translators: 1: Bundle name, 2: Missing products, 3: Savings amount */
				__( 'You are one step away from the %1$s. Add %2$s to your order and save %3$s!', 'smart-order-builder' ),
				'<strong>' . esc_html( $bundle_name ) . '</strong>',
				'<strong>' . esc_html( $missing_names_str ) . '</strong>',
				'<span class="sob-banner-savings-hl">' . wc_price( $savings ) . '</span>'
			);
			?>
		</p>
	</div>

	<div class="sob-banner-actions">
		<button type="button" class="sob-btn-upgrade sob-banner-upgrade-btn">
			<?php esc_html_e( 'Upgrade to Bundle', 'smart-order-builder' ); ?>
		</button>
		<button type="button" class="sob-btn-dismiss sob-banner-dismiss-btn" aria-label="<?php esc_attr_e( 'Dismiss banner', 'smart-order-builder' ); ?>">
			<?php esc_html_e( 'Dismiss', 'smart-order-builder' ); ?>
		</button>
	</div>

</div>
