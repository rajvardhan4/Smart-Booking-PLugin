<?php
/**
 * Template: cart-item.php
 *
 * Renders a single cart item inside the sidebar list (Complete Your Order section).
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	return;
}

$image_id  = $product->get_image_id();
$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
$name      = $product->get_name();
$qty       = $cart_item['quantity'];
$price_html = wc_price( $cart_item['line_subtotal'] );
?>

<div class="sob-cart-item" data-product-id="<?php echo esc_attr( $product_id ); ?>">
	
	<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" class="sob-cart-thumb" />
	
	<div class="sob-cart-info">
		<span class="sob-cart-name"><?php echo esc_html( $name ); ?><?php echo $qty > 1 ? ' &times; ' . $qty : ''; ?></span>
		<span class="sob-cart-price"><?php echo wp_kses_post( $price_html ); ?></span>
	</div>

</div>
