<?php
/**
 * Template: order-totals.php
 *
 * Renders the order totals section of the sidebar summary.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$total_items    = $cart_summary['total_items'];
$subtotal       = $cart_summary['subtotal'];
$bundle_savings = $cart_summary['bundle_savings'];
$discounts      = $cart_summary['discounts'];
$tax            = $cart_summary['tax'];
$total          = $cart_summary['total'];
?>

<div class="sob-totals-rows">
	
	<!-- Total Items Row -->
	<div class="sob-totals-row">
		<span class="sob-label"><?php esc_html_e( 'Total Items', 'smart-order-builder' ); ?></span>
		<span class="sob-val" id="sob-summary-items-count"><?php echo esc_html( $total_items ); ?></span>
	</div>

	<!-- Subtotal Row -->
	<div class="sob-totals-row">
		<span class="sob-label"><?php esc_html_e( 'Subtotal', 'smart-order-builder' ); ?></span>
		<span class="sob-val"><?php echo wp_kses_post( wc_price( $subtotal ) ); ?></span>
	</div>

	<!-- Bundle Savings Row (If applicable) -->
	<?php if ( $bundle_savings > 0 ) : ?>
		<div class="sob-totals-row sob-savings-row">
			<span class="sob-label"><?php esc_html_e( 'Bundle Savings', 'smart-order-builder' ); ?></span>
			<span class="sob-val">-<?php echo wp_kses_post( wc_price( $bundle_savings ) ); ?></span>
		</div>
	<?php endif; ?>

	<!-- General Coupons / Discounts Row (If applicable) -->
	<?php if ( $discounts > 0 ) : ?>
		<div class="sob-totals-row sob-discount-row">
			<span class="sob-label"><?php esc_html_e( 'Coupon Discounts', 'smart-order-builder' ); ?></span>
			<span class="sob-val">-<?php echo wp_kses_post( wc_price( $discounts ) ); ?></span>
		</div>
	<?php endif; ?>

	<!-- Tax Row (If applicable in WooCommerce) -->
	<?php if ( wc_tax_enabled() && $tax > 0 ) : ?>
		<div class="sob-totals-row">
			<span class="sob-label"><?php esc_html_e( 'Tax', 'smart-order-builder' ); ?></span>
			<span class="sob-val"><?php echo wp_kses_post( wc_price( $tax ) ); ?></span>
		</div>
	<?php endif; ?>

	<!-- Suggestions Section: Complete Your Order (Now displaying Cart Items) inside Order Summary (between subtotal and total) -->
	<?php 
	$settings = SOB_Admin::get_settings();
	if ( 'yes' === $settings['enable_complete_order'] ) : ?>
		<div class="sob-suggestions-card" id="sob-suggestions-container" <?php echo ! WC()->cart || WC()->cart->is_empty() ? 'style="display:none;"' : ''; ?> style="border: none; box-shadow: none; padding: 15px 0 0 0; margin-top: 15px; border-top: 1px solid #e2e8f0; width: 100%;">
			<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
				<h4 class="sob-suggestions-title" style="margin: 0; font-size: 1.1em; font-weight: 600; color: #1e293b;"><?php esc_html_e( 'Complete Your Order', 'smart-order-builder' ); ?></h4>
				<button type="button" id="sob-empty-cart-btn" style="background: none !important; border: none !important; color: #ef4444 !important; font-size: 12px !important; font-weight: 600 !important; cursor: pointer !important; padding: 0 !important; outline: none !important; box-shadow: none !important;"><?php esc_html_e( 'Empty Cart', 'smart-order-builder' ); ?></button>
			</div>
			<div class="sob-suggestions-list" id="sob-suggestions-list">
				<?php
				if ( WC()->cart && ! WC()->cart->is_empty() ) {
					$individual_items = [];
					$grouped_bundles = [];

					foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
						if ( isset( $cart_item['sob_bundle_group_id'] ) ) {
							$group_id = $cart_item['sob_bundle_group_id'];
							if ( ! isset( $grouped_bundles[ $group_id ] ) ) {
								$grouped_bundles[ $group_id ] = [
									'bundle_id' => $cart_item['sob_bundle_id'],
									'source'    => isset( $cart_item['sob_bundle_source'] ) ? $cart_item['sob_bundle_source'] : 'manual',
									'items'     => [],
								];
							}
							$grouped_bundles[ $group_id ]['items'][ $cart_item_key ] = $cart_item;
						} else {
							$individual_items[ $cart_item_key ] = $cart_item;
						}
					}

					// First render grouped bundles
					foreach ( $grouped_bundles as $group_id => $bundle_data ) {
						SOB_Shortcode::get_template( 'cart-bundle-item', [
							'group_id'    => $group_id,
							'bundle_id'   => $bundle_data['bundle_id'],
							'source'      => $bundle_data['source'],
							'cart_items'  => $bundle_data['items'],
						] );
					}

					// Then render individual items
					foreach ( $individual_items as $cart_item_key => $cart_item ) {
						$item_id = $cart_item['variation_id'] > 0 ? $cart_item['variation_id'] : $cart_item['product_id'];
						SOB_Shortcode::get_template( 'cart-item', [
							'cart_item_key' => $cart_item_key,
							'cart_item'     => $cart_item,
							'product_id'    => $item_id,
						] );
					}
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<hr class="sob-summary-divider" />

	<!-- Grand Total Row -->
	<div class="sob-totals-row sob-grand-total-row">
		<span class="sob-label"><?php esc_html_e( 'Total', 'smart-order-builder' ); ?></span>
		<span class="sob-val" id="sob-summary-grand-total"><?php echo wp_kses_post( wc_price( $total ) ); ?></span>
	</div>

</div>
