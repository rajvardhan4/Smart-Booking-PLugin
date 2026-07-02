<?php
/**
 * Template: order-builder.php
 *
 * Renders the main order builder page interface.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>

<div class="sob-app-container" id="sob-order-builder">
	
	<!-- Toast Notifications -->
	<div class="sob-toast-container" id="sob-toasts"></div>

	<!-- Main Three-Column Layout -->
	<div class="sob-grid-layout">
		
		<!-- Column 1: Table -->
		<div class="sob-col-table">
			
			<!-- Search & Filter Controls -->
			<?php if ( 'yes' === $settings['enable_search'] ) : ?>
				<div class="sob-filter-panel">
					<div class="sob-search-box">
						<span class="dashicons dashicons-search"></span>
						<input type="text" id="sob-search-input" placeholder="<?php esc_attr_e( 'Search products by name, SKU, or keyword...', 'smart-order-builder' ); ?>" autocomplete="off" />
					</div>
					
					<div class="sob-category-filter">
						<select id="sob-category-select">
							<option value=""><?php esc_html_e( 'All Categories', 'smart-order-builder' ); ?></option>
							<?php
							$categories = get_terms( [
								'taxonomy'   => 'product_cat',
								'hide_empty' => true,
							] );
							if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
								foreach ( $categories as $cat ) {
									echo '<option value="' . esc_attr( $cat->slug ) . '">' . esc_html( $cat->name ) . '</option>';
								}
							}
							?>
						</select>
					</div>
				</div>
			<?php endif; ?>

			<!-- Smart Bundle Upgrade Banner Container (Hidden) -->
			<div class="sob-upgrade-banner-wrapper" id="sob-upgrade-banner-container" style="display: none !important;"></div>

			<!-- Product Table Wrapper -->
			<div class="sob-table-container">
				<table class="sob-products-table">
					<thead>
						<tr>
							<th class="sob-col-img"><?php esc_html_e( 'Item', 'smart-order-builder' ); ?></th>
							<th class="sob-col-name"><?php esc_html_e( 'Product', 'smart-order-builder' ); ?></th>
							<th class="sob-col-price"><?php esc_html_e( 'Price', 'smart-order-builder' ); ?></th>
							<th class="sob-col-qty"><?php esc_html_e( 'Quantity', 'smart-order-builder' ); ?></th>
							<th class="sob-col-view"><?php esc_html_e( 'View', 'smart-order-builder' ); ?></th>
						</tr>
					</thead>
					<tbody id="sob-products-body">
						<?php
						if ( ! empty( $products ) ) {
							foreach ( $products as $prod_post ) {
								SOB_Shortcode::get_template( 'product-row', [ 'product_id' => $prod_post->ID ] );
							}
						} else {
							?>
							<tr class="sob-no-products">
								<td colspan="5"><?php esc_html_e( 'No products found.', 'smart-order-builder' ); ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
				
				<!-- Table Loader Overlay -->
				<div class="sob-table-loader" id="sob-table-loader" style="display:none;">
					<div class="sob-spinner"></div>
				</div>
			</div>

		</div> <!-- End Column 1 -->

		<!-- Column 2: Recommended Bundles -->
		<div class="sob-col-bundles">
			<?php if ( 'yes' === $settings['enable_recommended_bundles'] && ! empty( $bundles ) ) : ?>
				<div class="sob-bundles-section">
					<h3 class="sob-section-title"><?php esc_html_e( 'Recommended Product Bundles', 'smart-order-builder' ); ?></h3>
					<div class="sob-bundles-grid">
						<?php
						foreach ( $bundles as $bundle ) {
							SOB_Shortcode::get_template( 'bundle-card', [ 'bundle' => $bundle ] );
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div> <!-- End Column 2 -->

		<!-- Column 3: Order Summary -->
		<div class="sob-col-summary <?php echo 'yes' === $settings['enable_sticky_sidebar'] ? 'sob-sticky-sidebar' : ''; ?>">
			
			<!-- Sticky Order Summary Widget -->
			<div class="sob-summary-card">
				<h3 class="sob-summary-title"><?php esc_html_e( 'Order Summary', 'smart-order-builder' ); ?></h3>
				
				<div class="sob-summary-content" id="sob-summary-totals">
					<?php SOB_Shortcode::get_template( 'order-totals', [ 'cart_summary' => $cart_summary ] ); ?>
				</div>


				<div class="sob-checkout-action" style="margin-top: 20px;">
					<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="sob-checkout-btn <?php echo ( $cart_summary['total_items'] <= 0 ) ? 'disabled' : ''; ?>" id="sob-checkout-button">
						<?php esc_html_e( 'Proceed to Checkout', 'smart-order-builder' ); ?>
						<span class="dashicons dashicons-arrow-right-alt"></span>
					</a>
				</div>
			</div>

		</div> <!-- End Column 3 -->

	</div> <!-- End Grid Layout -->

	<!-- Floating off-canvas product detail drawer -->
	<div class="sob-drawer-backdrop" id="sob-product-drawer-backdrop" style="display:none;">
		<div class="sob-drawer" id="sob-product-drawer">
			<!-- Populated via AJAX -->
		</div>
	</div>

	<!-- Floating lightbox bundle modal -->
	<div class="sob-modal-backdrop" id="sob-bundle-modal-backdrop" style="display:none;">
		<div class="sob-modal" id="sob-bundle-modal">
			<!-- Populated via AJAX -->
		</div>
	</div>

</div>
