<?php
/**
 * Class SOB_Shortcode
 *
 * Registers and handles the [smart_order_builder] shortcode.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Shortcode {

	/**
	 * Constructor. Registers the shortcode.
	 */
	public function __construct() {
		add_shortcode( 'smart_order_builder', [ $this, 'render_shortcode' ] );
	}

	/**
	 * Render the order builder shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts ): string {
		$settings = SOB_Admin::get_settings();

		// Check if the builder is enabled.
		if ( 'yes' !== $settings['enabled'] ) {
			return '<p class="sob-disabled-msg">' . esc_html__( 'Smart Order Builder is currently disabled.', 'smart-order-builder' ) . '</p>';
		}

		// Enqueue registered assets.
		wp_enqueue_style( 'sob-frontend' );
		wp_enqueue_script( 'sob-frontend' );

		// Load initial products query.
		$paged = 1;
		$per_page = -1;
		
		$products_data = $this->query_products( '', '', $paged, $per_page );

		// Load active bundles.
		$bundles = [];
		if ( 'yes' === $settings['enable_recommended_bundles'] ) {
			$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
			if ( $bundles_module ) {
				$bundles = $bundles_module->get_active_bundles( $settings['bundle_source'], $settings['bundles_count'] );
			}
		}

		// Check for upgrade banner recommendations.
		$upgrade_banner_data = SOB_Cart::get_upgrade_banner_data();

		// Load cross-sell suggestions.
		$suggested_products = [];
		if ( 'yes' === $settings['enable_complete_order'] ) {
			$suggested_products = SOB_Cart::get_cart_suggestions( $settings['complete_order_count'] );
		}

		// Cart totals.
		$cart_summary = SOB_Cart::get_cart_summary_data();

		// Capture the output of order-builder.php.
		ob_start();
		self::get_template( 'order-builder', [
			'settings'            => $settings,
			'products'            => $products_data['products'],
			'max_num_pages'       => $products_data['max_num_pages'],
			'current_page'        => $paged,
			'bundles'             => $bundles,
			'upgrade_banner_data' => $upgrade_banner_data,
			'suggested_products'  => $suggested_products,
			'cart_summary'        => $cart_summary,
		] );
		return ob_get_clean();
	}

	/**
	 * Helper function to retrieve template files.
	 *
	 * @param string $template_name File name without .php.
	 * @param array  $args Variables to extract and scope.
	 */
	public static function get_template( string $template_name, array $args = [] ): void {
		if ( ! empty( $args ) ) {
			extract( $args ); // @codingStandardsIgnoreLine
		}

		$path = SOB_PATH . 'templates/' . $template_name . '.php';

		if ( file_exists( $path ) ) {
			include $path;
		}
	}

	/**
	 * Query WooCommerce products with filters.
	 *
	 * @param string $search Search term.
	 * @param string $category Category slug.
	 * @param int    $page Current page.
	 * @param int    $per_page Items per page.
	 * @return array Array containing product objects and pagination info.
	 */
	public function query_products( string $search = '', string $category = '', int $page = 1, int $per_page = 12 ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		// Apply category filter.
		if ( ! empty( $category ) ) {
			$args['tax_query'] = [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => sanitize_title( $category ),
				],
			];
		}

		// Apply search filter.
		if ( ! empty( $search ) ) {
			// Clean search term
			$search = sanitize_text_field( $search );
			
			// If search looks like SKU, look it up first
			$product_id_by_sku = wc_get_product_id_by_sku( $search );
			if ( $product_id_by_sku ) {
				$args['post__in'] = [ $product_id_by_sku ];
			} else {
				$args['s'] = $search;
			}
		}

		$query = new WP_Query( $args );
		
		return [
			'products'      => $query->posts,
			'max_num_pages' => $query->max_num_pages,
			'total'         => $query->found_posts,
		];
	}
}
