<?php
/**
 * Template: bundle-modal.php
 *
 * Renders the content of the recommended bundle quick view modal.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$id              = $bundle['id'];
$name            = $bundle['name'];
$image           = $bundle['image'];
$products        = $bundle['products'];
$prod_details    = $bundle['product_details'];
$original_price  = $bundle['original_price'];
$bundle_price    = $bundle['bundle_price'];
$savings         = $bundle['savings'];
$savings_percent = $bundle['savings_percent'];
?>

<div class="sob-modal-header">
	<h3 class="sob-modal-title"><?php echo esc_html( $name ); ?></h3>
	<button type="button" class="sob-modal-close" aria-label="<?php esc_attr_e( 'Close modal', 'smart-order-builder' ); ?>">&times;</button>
</div>

<div class="sob-modal-body" data-bundle-id="<?php echo esc_attr( $id ); ?>" data-source="<?php echo esc_attr( $bundle['source'] ); ?>">
	
	<div class="sob-modal-grid">
		
		<!-- Left: Bundle Image -->
		<div class="sob-modal-media">
			<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" class="sob-modal-img" />
		</div>
		
		<!-- Right: Bundle details & pricing -->
		<div class="sob-modal-info">
			
			<div class="sob-modal-products-list">
				<h4 class="sob-modal-section-title"><?php esc_html_e( 'What\'s Included:', 'smart-order-builder' ); ?></h4>
				
				<div class="sob-modal-bundle-items">
					<?php foreach ( $prod_details as $item ) : ?>
						<?php
						$prod = wc_get_product( $item['id'] );
						$thumb_id = $prod ? $prod->get_image_id() : 0;
						$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : wc_placeholder_img_src( 'thumbnail' );
						?>
						<div class="sob-modal-item-row">
							<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" class="sob-modal-item-thumb" />
							<div class="sob-modal-item-desc">
								<span class="sob-modal-item-name">
									<?php 
									$qty = isset( $item['qty'] ) ? max( 1, intval( $item['qty'] ) ) : 1;
									echo esc_html( $qty ) . ' &times; ' . esc_html( $item['name'] ); 
									?>
								</span>
								<span class="sob-modal-item-price"><?php echo wp_kses_post( wc_price( $item['price'] * $qty ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			</div>

			<div class="sob-modal-pricing-box">
				<div class="sob-modal-price-row">
					<span><?php esc_html_e( 'Total Value:', 'smart-order-builder' ); ?></span>
					<span class="sob-modal-orig-price"><?php echo wp_kses_post( wc_price( $original_price ) ); ?></span>
				</div>
				
				<?php if ( $savings > 0 ) : ?>
					<div class="sob-modal-price-row sob-modal-savings-row">
						<span><?php esc_html_e( 'Bundle Discount:', 'smart-order-builder' ); ?></span>
						<span class="sob-modal-savings-badge">-<?php echo wp_kses_post( wc_price( $savings ) ); ?> (<?php echo esc_html( $savings_percent ); ?>% Off)</span>
					</div>
				<?php endif; ?>

				<hr class="sob-modal-hr" />

				<div class="sob-modal-price-row sob-modal-final-row">
					<span><?php esc_html_e( 'Bundle Price:', 'smart-order-builder' ); ?></span>
					<span class="sob-modal-final-price"><?php echo wp_kses_post( wc_price( $bundle_price ) ); ?></span>
				</div>
			</div>

			<div class="sob-modal-actions">
				<button type="button" class="sob-modal-add-bundle-btn">
					<span class="dashicons dashicons-cart sob-button-dashicon" style="font-size: 18px; width: 18px; height: 18px; display: inline-block; margin-right: 6px; color: currentColor; vertical-align: middle;"></span>
					<span><?php esc_html_e( 'Add Entire Bundle', 'smart-order-builder' ); ?></span>
				</button>
			</div>

		</div> <!-- End Right Info -->

	</div>

</div>
