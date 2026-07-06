<?php
/**
 * Template: suggested-product.php
 *
 * Renders a single product suggestion item in the "Complete Your Order" list.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_visible() || ! $product->is_in_stock() ) {
	return;
}

$image_id  = $product->get_image_id();
$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
$name      = $product->get_name();
$price_html = $product->get_price_html();
?>

<div class="sob-suggested-item" data-product-id="<?php echo esc_attr( $product_id ); ?>">
	
	<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" class="sob-suggested-thumb" />
	
	<div class="sob-suggested-info">
		<span class="sob-suggested-name"><?php echo esc_html( $name ); ?></span>
		<span class="sob-suggested-price"><?php echo wp_kses_post( $price_html ); ?></span>
	</div>

	<button type="button" class="sob-suggested-add-btn" aria-label="<?php esc_attr_e( 'Add suggested product to cart', 'smart-order-builder' ); ?>">
		<span class="dashicons dashicons-plus"></span>
	</button>

</div>
