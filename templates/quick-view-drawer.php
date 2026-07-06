<?php
/**
 * Template: quick-view-drawer.php
 *
 * Renders the content of the right-side product quick view drawer.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	echo '<p>' . esc_html__( 'Product not found.', 'smart-order-builder' ) . '</p>';
	return;
}

$name = $product->get_name();
$price_html = $product->get_price_html();
$short_desc = $product->get_short_description();
$full_desc = $product->get_description();
$is_in_stock = $product->is_in_stock();
$sku = $product->get_sku();

// Images & Gallery.
$image_id = $product->get_image_id();
$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : wc_placeholder_img_src( 'large' );
$gallery_ids = $product->get_gallery_image_ids();

// Attributes.
$attributes = $product->get_attributes();

// Variable product data.
$is_variable = $product->is_type( 'variable' );
$available_variations = $is_variable ? $product->get_available_variations() : [];
$variation_attributes = $is_variable ? $product->get_variation_attributes() : [];

// Cart quantity.
$cart_qty = SOB_Cart::get_product_cart_quantity( $product_id );
?>

<div class="sob-drawer-header">
	<h3 class="sob-drawer-title"><?php esc_html_e( 'Product Details', 'smart-order-builder' ); ?></h3>
	<button type="button" class="sob-drawer-close" aria-label="<?php esc_attr_e( 'Close drawer', 'smart-order-builder' ); ?>">&times;</button>
</div>

<div class="sob-drawer-body" data-product-id="<?php echo esc_attr( $product_id ); ?>" <?php echo $is_variable ? 'data-variations="' . esc_attr( wp_json_encode( $available_variations ) ) . '"' : ''; ?>>
	
	<!-- Image Gallery Slider -->
	<div class="sob-drawer-media">
		<div class="sob-drawer-main-image-wrap">
			<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" id="sob-drawer-main-image" class="sob-drawer-main-img" />
		</div>
		
		<?php if ( ! empty( $gallery_ids ) ) : ?>
			<div class="sob-drawer-gallery-thumbs">
				<div class="sob-gallery-thumb active" data-large-url="<?php echo esc_url( $image_url ); ?>">
					<img src="<?php echo esc_url( $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' ) ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
				</div>
				<?php foreach ( $gallery_ids as $gal_id ) : ?>
					<?php $gal_url = wp_get_attachment_image_url( $gal_id, 'large' ); ?>
					<div class="sob-gallery-thumb" data-large-url="<?php echo esc_url( $gal_url ); ?>">
						<img src="<?php echo esc_url( wp_get_attachment_image_url( $gal_id, 'thumbnail' ) ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Info Details -->
	<div class="sob-drawer-info">
		<h2 class="sob-drawer-prod-name"><?php echo esc_html( $name ); ?></h2>
		
		<?php if ( ! empty( $sku ) ) : ?>
			<div class="sob-drawer-prod-sku">
				<strong><?php esc_html_e( 'SKU:', 'smart-order-builder' ); ?></strong>
				<span><?php echo esc_html( $sku ); ?></span>
			</div>
		<?php endif; ?>

		<div class="sob-drawer-price" id="sob-drawer-price-display">
			<?php echo wp_kses_post( $price_html ); ?>
		</div>

		<!-- Stock Badge -->
		<div class="sob-drawer-stock-wrap">
			<?php if ( $is_in_stock ) : ?>
				<?php if ( $product->managing_stock() ) : ?>
					<span class="sob-stock-badge in-stock"><?php printf( esc_html__( '%d In Stock', 'smart-order-builder' ), esc_html( $product->get_stock_quantity() ) ); ?></span>
				<?php else : ?>
					<span class="sob-stock-badge in-stock"><?php esc_html_e( 'In Stock', 'smart-order-builder' ); ?></span>
				<?php endif; ?>
			<?php else : ?>
				<span class="sob-stock-badge out-of-stock"><?php esc_html_e( 'Out of Stock', 'smart-order-builder' ); ?></span>
			<?php endif; ?>
		</div>

		<!-- Short description -->
		<?php 
		if ( $product->is_type( 'grouped' ) || $product->is_type( 'bundle' ) ) {
			$contents = SOB_Bundles::get_product_bundle_contents( $product_id );
			if ( ! empty( $contents ) ) {
				$desc_lines = [];
				foreach ( $contents as $item ) {
					$desc_lines[] = $item['qty'] . ' &times; ' . esc_html( $item['name'] );
				}
				$short_desc = '<div class="sob-drawer-bundle-contents" style="font-size: 0.95em; line-height: 1.5; color: #475569; margin-bottom: 15px; background: #f8fafc; border: 1px solid #e2e8f0; padding: 12px; border-radius: 8px;"><strong>' . esc_html__( 'Includes:', 'smart-order-builder' ) . '</strong><br/>' . implode( '<br/>', $desc_lines ) . '</div>';
			}
		}
		?>
		<?php if ( ! empty( $short_desc ) ) : ?>
			<div class="sob-drawer-short-desc">
				<?php echo wp_kses_post( $short_desc ); ?>
			</div>
		<?php endif; ?>

		<!-- Variable Product Options -->
		<?php if ( $is_variable && ! empty( $variation_attributes ) ) : ?>
			<div class="sob-drawer-variations">
				<?php foreach ( $variation_attributes as $attribute_name => $options ) : ?>
					<?php
					$cleaned_attr_name = wc_attribute_label( $attribute_name );
					$id_attr = 'sob-attr-' . sanitize_title( $attribute_name );
					?>
					<div class="sob-variation-selector-row">
						<label for="<?php echo esc_attr( $id_attr ); ?>"><?php echo esc_html( $cleaned_attr_name ); ?></label>
						<select id="<?php echo esc_attr( $id_attr ); ?>" class="sob-variation-select" data-attribute="<?php echo esc_attr( $attribute_name ); ?>">
							<option value=""><?php printf( esc_html__( 'Choose %s', 'smart-order-builder' ), esc_html( $cleaned_attr_name ) ); ?></option>
							<?php foreach ( $options as $option ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( taxonomy_exists( $attribute_name ) ? get_term_by( 'slug', $option, $attribute_name )->name : $option ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				<?php endforeach; ?>
				
				<!-- Target Variation ID input -->
				<input type="hidden" id="sob-selected-variation-id" value="" />
			</div>
		<?php endif; ?>

		<!-- Action Quantity and Add to Cart -->
		<div class="sob-drawer-actions">
			<?php if ( $is_in_stock ) : ?>
				<div class="sob-qty-selector">
					<button type="button" class="sob-qty-btn sob-drawer-qty-minus">-</button>
					<input type="number" id="sob-drawer-qty-input" class="sob-qty-input" value="<?php echo esc_attr( $cart_qty > 0 ? $cart_qty : 1 ); ?>" min="1" max="<?php echo esc_attr( $product->get_stock_quantity() ); ?>" />
					<button type="button" class="sob-qty-btn sob-drawer-qty-plus">+</button>
				</div>
				<button type="button" id="sob-drawer-add-to-cart-btn" class="sob-btn-primary sob-add-to-cart-drawer">
					<span class="dashicons dashicons-cart"></span>
					<?php esc_html_e( 'Add to Cart', 'smart-order-builder' ); ?>
				</button>
			<?php else : ?>
				<button type="button" class="sob-btn-primary" disabled="disabled">
					<?php esc_html_e( 'Out of Stock', 'smart-order-builder' ); ?>
				</button>
			<?php endif; ?>
		</div>

		<!-- Attributes Specification -->
		<?php if ( ! empty( $attributes ) ) : ?>
			<div class="sob-drawer-specs">
				<h4 class="sob-specs-title"><?php esc_html_e( 'Specifications', 'smart-order-builder' ); ?></h4>
				<table class="sob-specs-table">
					<tbody>
						<?php foreach ( $attributes as $attr ) : ?>
							<?php
							// Skip attributes that are used for variations
							if ( $attr->get_variation() ) {
								continue;
							}
							?>
							<tr>
								<th><?php echo esc_html( wc_attribute_label( $attr->get_name() ) ); ?></th>
								<td>
									<?php
									if ( $attr->is_taxonomy() ) {
										$terms = $attr->get_terms();
										$values = array_map( function( $t ) {
											return $t->name;
										}, $terms );
										echo esc_html( implode( ', ', $values ) );
									} else {
										echo esc_html( implode( ', ', $attr->get_options() ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Full description -->
		<?php if ( ! empty( $full_desc ) ) : ?>
			<div class="sob-drawer-full-desc">
				<h4 class="sob-specs-title"><?php esc_html_e( 'Description', 'smart-order-builder' ); ?></h4>
				<div class="sob-description-content">
					<?php echo wp_kses_post( wpautop( $full_desc ) ); ?>
				</div>
			</div>
		<?php endif; ?>

	</div>

</div>
