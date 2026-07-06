<?php
/**
 * Template: bundle-card.php
 *
 * Renders a single bundle recommendation card.
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

<div class="sob-bundle-card" data-bundle-id="<?php echo esc_attr( $id ); ?>" data-source="<?php echo esc_attr( $bundle['source'] ); ?>">
	
	<!-- Savings badge -->
	<?php if ( $savings > 0 ) : ?>
		<div class="sob-bundle-badge">
			<?php printf( esc_html__( 'Save %s%%', 'smart-order-builder' ), esc_html( $savings_percent ) ); ?>
		</div>
	<?php endif; ?>

	<div class="sob-bundle-img-wrap">
		<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" class="sob-bundle-img" />
	</div>

	<div class="sob-bundle-info">
		<h4 class="sob-bundle-name"><?php echo esc_html( $name ); ?></h4>
		
		<!-- List of products included -->
		<div class="sob-bundle-products-list">
			<span class="sob-bundle-includes-label"><?php esc_html_e( 'Includes:', 'smart-order-builder' ); ?></span>
			<ul>
				<?php foreach ( $prod_details as $item ) : ?>
					<?php 
					$qty = isset( $item['qty'] ) ? max( 1, intval( $item['qty'] ) ) : 1;
					?>
					<li><?php echo esc_html( $qty ) . ' &times; ' . esc_html( $item['name'] ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>

		<!-- Pricing block -->
		<div class="sob-bundle-pricing">
			<?php if ( $savings > 0 ) : ?>
				<span class="sob-bundle-orig-price"><?php echo wp_kses_post( wc_price( $original_price ) ); ?></span>
			<?php endif; ?>
			<span class="sob-bundle-final-price"><?php echo wp_kses_post( wc_price( $bundle_price ) ); ?></span>
		</div>
		
		<?php if ( $savings > 0 ) : ?>
			<div class="sob-bundle-savings-text">
				<?php printf( esc_html__( 'You save %s', 'smart-order-builder' ), wp_kses_post( wc_price( $savings ) ) ); ?>
			</div>
		<?php endif; ?>
	</div>

	<!-- Actions -->
	<div class="sob-bundle-actions">
		<button type="button" class="sob-btn-secondary sob-bundle-view-btn" title="<?php esc_attr_e( 'View bundle details', 'smart-order-builder' ); ?>">
			<?php esc_html_e( 'View Details', 'smart-order-builder' ); ?>
		</button>
		
		<button type="button" class="sob-btn-primary sob-bundle-add-btn">
			<span class="dashicons dashicons-cart"></span>
			<?php esc_html_e( 'Add Bundle', 'smart-order-builder' ); ?>
		</button>
	</div>

</div>
