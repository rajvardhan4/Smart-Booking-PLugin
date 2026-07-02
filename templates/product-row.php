<?php
/**
 * Template: product-row.php
 *
 * Renders a single product row inside the order builder table.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_visible() ) {
	return;
}

$image_id  = $product->get_image_id();
$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
$name      = $product->get_name();
$short_desc = $product->get_short_description();

if ( $product->is_type( 'grouped' ) || $product->is_type( 'bundle' ) ) {
	$contents = SOB_Bundles::get_product_bundle_contents( $product_id );
	if ( ! empty( $contents ) ) {
		$desc_lines = [];
		foreach ( $contents as $item ) {
			$desc_lines[] = $item['qty'] . ' &times; ' . esc_html( $item['name'] );
		}
		$short_desc = '<div class="sob-table-bundle-contents" style="font-size: 0.9em; line-height: 1.4; color: #475569;">' . implode( '<br/>', $desc_lines ) . '</div>';
	} else {
		$short_desc = wp_strip_all_tags( $short_desc );
		if ( strlen( $short_desc ) > 80 ) {
			$short_desc = substr( $short_desc, 0, 80 ) . '...';
		}
	}
} else {
	// Clean up description HTML for safe display
	$short_desc = wp_strip_all_tags( $short_desc );
	if ( strlen( $short_desc ) > 80 ) {
		$short_desc = substr( $short_desc, 0, 80 ) . '...';
	}
}

$price_html = $product->get_price_html();
$stock_status = $product->get_stock_status();
$is_in_stock  = $product->is_in_stock();
$sku          = $product->get_sku();

// Get current quantity in WooCommerce cart.
$cart_qty = SOB_Cart::get_product_cart_quantity( $product_id );
?>

<tr class="sob-product-row <?php echo ! $is_in_stock ? 'sob-out-of-stock' : ''; ?>" data-product-id="<?php echo esc_attr( $product_id ); ?>">
	
	<!-- Column 1: Image -->
	<td class="sob-col-img">
		<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" class="sob-prod-thumb" />
	</td>

	<!-- Column 2: Name & SKU -->
	<td class="sob-col-name">
		<div class="sob-prod-title-wrap">
			<span class="sob-prod-title"><?php echo esc_html( $name ); ?></span>
			<?php if ( ! empty( $sku ) ) : ?>
				<span class="sob-prod-sku"><?php echo esc_html( $sku ); ?></span>
			<?php endif; ?>
		</div>
	</td>


	<!-- Column 4: Price -->
	<td class="sob-col-price">
		<span class="sob-prod-price"><?php echo wp_kses_post( $price_html ); ?></span>
	</td>

	<!-- Column 5: Quantity Selector -->
	<td class="sob-col-qty">
		<?php if ( $is_in_stock ) : ?>
			<div class="sob-qty-selector">
				<button type="button" class="sob-qty-btn sob-qty-minus" aria-label="<?php esc_attr_e( 'Decrease quantity', 'smart-order-builder' ); ?>">-</button>
				<input type="number" class="sob-qty-input" value="<?php echo esc_attr( $cart_qty ); ?>" min="0" max="<?php echo $product->managing_stock() ? esc_attr( $product->get_stock_quantity() ) : ''; ?>" />
				<button type="button" class="sob-qty-btn sob-qty-plus" aria-label="<?php esc_attr_e( 'Increase quantity', 'smart-order-builder' ); ?>">+</button>
			</div>
		<?php else : ?>
			<div class="sob-badge sob-badge-out"><?php esc_html_e( 'Out of stock', 'smart-order-builder' ); ?></div>
		<?php endif; ?>
	</td>

	<!-- Column 6: Quick View -->
	<td class="sob-col-view">
		<button type="button" class="sob-quickview-trigger" title="<?php esc_attr_e( 'Quick View', 'smart-order-builder' ); ?>">
			<span class="dashicons dashicons-visibility"></span>
		</button>
	</td>

</tr>
