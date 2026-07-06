<?php
/**
 * Template: cart-bundle-item.php
 *
 * Renders a single collapsible row for a bundle item in the sidebar cart.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$bundle_name = get_the_title( $bundle_id );

// Retrieve bundle definition to get pricing and calculate current bundle quantity
$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
$found_bundle = null;

foreach ( $active_bundles as $b ) {
	if ( $b['id'] == $bundle_id ) {
		$found_bundle = $b;
		break;
	}
}

$bundle_qty = 1;
$bundle_single_price = 0;

if ( $found_bundle ) {
	$bundle_single_price = floatval( $found_bundle['bundle_price'] );
	
	// Get first product inside the cart items to determine bundle quantity multiplier
	$first_cart_item = reset( $cart_items );
	if ( $first_cart_item ) {
		$prod_id = $first_cart_item['product_id'];
		$base_qty = 1;
		foreach ( $found_bundle['product_details'] as $detail ) {
			if ( $detail['id'] == $prod_id ) {
				$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
				break;
			}
		}
		$bundle_qty = max( 1, intval( $first_cart_item['quantity'] / $base_qty ) );
	}
} else {
	// Fallback pricing: sum of cart item subtotals
	$sum = 0;
	foreach ( $cart_items as $cart_item ) {
		$sum += floatval( $cart_item['line_subtotal'] );
	}
	$bundle_single_price = $sum;
}

$bundle_total_price = $bundle_single_price * $bundle_qty;
?>

<div class="sob-cart-bundle-card" data-bundle-group-id="<?php echo esc_attr( $group_id ); ?>">
	<!-- Accordion Header: Clicking this toggles the detail list -->
	<div class="sob-cart-bundle-header">
		<span class="sob-cart-bundle-toggle" style="font-size: 12px; color: #64748b; font-weight: bold; margin-right: 5px; display: inline-block;">▶</span>
		<div class="sob-cart-bundle-title-col">
			<strong class="sob-cart-bundle-title"><?php echo esc_html( $bundle_name ); ?></strong>
			<span class="sob-cart-bundle-badge"><?php esc_html_e( 'Bundle Package', 'smart-order-builder' ); ?></span>
		</div>
		<div class="sob-cart-bundle-price-col">
			<span class="sob-cart-bundle-price"><?php echo wp_kses_post( wc_price( $bundle_total_price ) ); ?></span>
		</div>
	</div>
	
	<!-- Accordion Body: Contains read-only items and action footer -->
	<div class="sob-cart-bundle-details" style="display: none;">
		<div class="sob-cart-bundle-details-inner">
			
			<div class="sob-cart-bundle-details-title"><?php esc_html_e( 'Included Products:', 'smart-order-builder' ); ?></div>
			
			<ul class="sob-cart-bundle-items-list">
				<?php foreach ( $cart_items as $cart_item_key => $cart_item ) : ?>
					<?php
					$product = $cart_item['data'];
					
					// Calculate single product quantity per bundle
					$total_qty = $cart_item['quantity'];
					$item_id = $product->get_id();
					
					$qty_per_bundle = 1;
					if ( $found_bundle ) {
						foreach ( $found_bundle['product_details'] as $detail ) {
							if ( $detail['id'] == $item_id ) {
								$qty_per_bundle = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
								break;
							}
						}
					}
					
					$thumbnail = $product->get_image( [ 24, 24 ] );
					?>
					<li>
						<span class="sob-cart-bundle-item-thumb"><?php echo $thumbnail; ?></span>
						<span class="sob-cart-bundle-item-name">
							<?php echo esc_html( $qty_per_bundle ); ?> &times; <?php echo esc_html( $product->get_name() ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<!-- Footer Action Block -->
			<div class="sob-cart-bundle-footer">
				<div class="sob-cart-bundle-qty-label-wrap">
					<span class="sob-cart-bundle-qty-label"><?php esc_html_e( 'Quantity:', 'smart-order-builder' ); ?></span>
					<div class="sob-cart-qty-selector sob-bundle-qty-selector">
						<button type="button" class="sob-qty-btn sob-bundle-qty-minus">&minus;</button>
						<input type="number" class="sob-qty-input sob-bundle-qty-input" value="<?php echo esc_attr( $bundle_qty ); ?>" min="0" readonly />
						<button type="button" class="sob-qty-btn sob-bundle-qty-plus">&plus;</button>
					</div>
				</div>
				
				<button type="button" class="sob-cart-bundle-remove-btn">
					<span class="dashicons dashicons-trash"></span>
					<span><?php esc_html_e( 'Remove Bundle', 'smart-order-builder' ); ?></span>
				</button>
			</div>

		</div>
	</div>
</div>
