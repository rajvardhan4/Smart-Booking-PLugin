<?php
/**
 * Class SOB_Ajax
 *
 * Handles AJAX requests for product searching, cart updates, drawer/modal populating, and admin utilities.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Ajax {

	/**
	 * Constructor. Registers the AJAX hook listeners.
	 */
	public function __construct() {
		// Frontend Ajax Actions (both logged-in and guests)
		$frontend_actions = [
			'sob_search_products',
			'sob_add_to_cart',
			'sob_update_quantity',
			'sob_remove_from_cart',
			'sob_get_cart_summary',
			'sob_get_product_quick_view',
			'sob_get_bundle_quick_view',
			'sob_add_bundle_to_cart',
			'sob_add_suggested_product',
			'sob_update_bundle_quantity',
			'sob_clear_cart',
		];

		foreach ( $frontend_actions as $action ) {
			add_action( 'wp_ajax_' . $action, [ $this, $action ] );
			add_action( 'wp_ajax_nopriv_' . $action, [ $this, $action ] );
		}

		// Admin Ajax Actions
		add_action( 'wp_ajax_sob_admin_search_products', [ $this, 'sob_admin_search_products' ] );
	}

	/**
	 * Verify Ajax security tokens.
	 */
	private function verify_security_token(): void {
		if ( ! check_ajax_referer( 'sob_ajax_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security verification failed. Please refresh the page.', 'smart-order-builder' ) ], 403 );
		}
	}

	/**
	 * Output standard JSON data structure containing updated cart state.
	 *
	 * @param string $message Action message.
	 */
	private function send_cart_state_json( string $message = '' ): void {
		$settings = SOB_Admin::get_settings();
		$cart_summary = SOB_Cart::get_cart_summary_data();
		
		// Render order-totals template
		ob_start();
		SOB_Shortcode::get_template( 'order-totals', [ 'cart_summary' => $cart_summary ] );
		$cart_summary_html = ob_get_clean();

		// Render upgrade-banner template if applicable
		$upgrade_banner_data = SOB_Cart::get_upgrade_banner_data();
		$upgrade_banner_html = '';
		if ( ! empty( $upgrade_banner_data ) ) {
			ob_start();
			SOB_Shortcode::get_template( 'upgrade-banner', [ 'data' => $upgrade_banner_data ] );
			$upgrade_banner_html = ob_get_clean();
		}

		// Render cart items (in place of suggestions_html)
		$suggestions_html   = '';
		if ( 'yes' === $settings['enable_complete_order'] ) {
			if ( WC()->cart && ! WC()->cart->is_empty() ) {
				ob_start();

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

				$suggestions_html = ob_get_clean();
			}
		}

		// Cart item quantities map (only individual items, excluding bundles)
		$cart_quantities = [];
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( isset( $cart_item['sob_bundle_group_id'] ) ) {
					continue;
				}
				$prod_id = $cart_item['product_id'];
				$var_id  = $cart_item['variation_id'];
				
				// Map simple or variation ID back
				$id_key = $var_id > 0 ? $var_id : $prod_id;
				if ( ! isset( $cart_quantities[ $id_key ] ) ) {
					$cart_quantities[ $id_key ] = 0;
				}
				$cart_quantities[ $id_key ] += $cart_item['quantity'];

				// Map parent ID as well if it's variation
				if ( $var_id > 0 ) {
					if ( ! isset( $cart_quantities[ $prod_id ] ) ) {
						$cart_quantities[ $prod_id ] = 0;
					}
					$cart_quantities[ $prod_id ] += $cart_item['quantity'];
				}
			}
		}

		wp_send_json_success( [
			'message'             => $message,
			'cart_summary_html'   => $cart_summary_html,
			'upgrade_banner_html' => $upgrade_banner_html,
			'suggestions_html'    => $suggestions_html,
			'cart_quantities'     => $cart_quantities,
			'total_items'         => $cart_summary['total_items'],
		] );
	}

	/**
	 * Search and filter products AJAX endpoint.
	 */
	public function sob_search_products(): void {
		// For search, nonce validation is optional, but let's check it anyway.
		$this->verify_security_token();

		$search   = isset( $_POST['search'] ) ? sanitize_text_field( $_POST['search'] ) : '';
		$category = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

		$settings = SOB_Admin::get_settings();
		$per_page = -1;

		$shortcode = SOB_Plugin::get_instance()->get_module( 'shortcode' );
		$results   = $shortcode->query_products( $search, $category, $page, $per_page );

		ob_start();
		if ( ! empty( $results['products'] ) ) {
			foreach ( $results['products'] as $prod_post ) {
				SOB_Shortcode::get_template( 'product-row', [ 'product_id' => $prod_post->ID ] );
			}
		} else {
			?>
			<tr class="sob-no-products">
				<td colspan="5"><?php esc_html_e( 'No products found matching your search.', 'smart-order-builder' ); ?></td>
			</tr>
			<?php
		}
		$html = ob_get_clean();

		wp_send_json_success( [
			'html'          => $html,
			'max_num_pages' => $results['max_num_pages'],
			'current_page'  => $page,
		] );
	}

	/**
	 * Add product to cart AJAX endpoint.
	 */
	public function sob_add_to_cart(): void {
		$this->verify_security_token();

		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$quantity     = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 1;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing product ID.', 'smart-order-builder' ) ] );
		}

		$added_id = $variation_id > 0 ? $variation_id : $product_id;
		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

		if ( $cart_item_key ) {
			$this->send_cart_state_json( __( 'Product added to cart.', 'smart-order-builder' ) );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not add product to cart.', 'smart-order-builder' ) ] );
		}
	}

	/**
	 * Update product quantity in cart AJAX endpoint.
	 */
	public function sob_update_quantity(): void {
		$this->verify_security_token();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$quantity   = isset( $_POST['quantity'] ) ? absint( $_POST['quantity'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing product ID.', 'smart-order-builder' ) ] );
		}

		// Locate cart item key
		$cart_item_key = '';
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $item['product_id'] == $product_id || $item['variation_id'] == $product_id ) {
				$cart_item_key = $key;
				break;
			}
		}

		if ( empty( $cart_item_key ) && $quantity > 0 ) {
			// If not in cart, add it
			$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity );
		} elseif ( ! empty( $cart_item_key ) ) {
			if ( $quantity <= 0 ) {
				// Remove if qty becomes 0
				WC()->cart->remove_cart_item( $cart_item_key );
			} else {
				WC()->cart->set_quantity( $cart_item_key, $quantity );
			}
		}

		$this->send_cart_state_json( __( 'Cart updated.', 'smart-order-builder' ) );
	}

	/**
	 * Remove product from cart AJAX endpoint.
	 */
	public function sob_remove_from_cart(): void {
		$this->verify_security_token();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing product ID.', 'smart-order-builder' ) ] );
		}

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( $item['product_id'] == $product_id || $item['variation_id'] == $product_id ) {
				WC()->cart->remove_cart_item( $key );
				break;
			}
		}

		$this->send_cart_state_json( __( 'Item removed from cart.', 'smart-order-builder' ) );
	}

	/**
	 * Retrieve cart summary HTML.
	 */
	public function sob_get_cart_summary(): void {
		$this->verify_security_token();
		$this->send_cart_state_json();
	}

	/**
	 * Retrieve product details for right-side drawer.
	 */
	public function sob_get_product_quick_view(): void {
		$this->verify_security_token();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing product ID.', 'smart-order-builder' ) ] );
		}

		ob_start();
		SOB_Shortcode::get_template( 'quick-view-drawer', [ 'product_id' => $product_id ] );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Retrieve bundle details for modal.
	 */
	public function sob_get_bundle_quick_view(): void {
		$this->verify_security_token();

		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
		$source    = isset( $_POST['source'] ) ? sanitize_text_field( $_POST['source'] ) : 'manual';

		if ( ! $bundle_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing bundle ID.', 'smart-order-builder' ) ] );
		}

		// Query active bundles to find this specific one
		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		$active_bundles = $bundles_module->get_active_bundles( $source, 100 ); // Load all to locate
		
		$found_bundle = null;
		foreach ( $active_bundles as $b ) {
			if ( $b['id'] == $bundle_id ) {
				$found_bundle = $b;
				break;
			}
		}

		if ( ! $found_bundle ) {
			wp_send_json_error( [ 'message' => __( 'Bundle not found.', 'smart-order-builder' ) ] );
		}

		ob_start();
		SOB_Shortcode::get_template( 'bundle-modal', [ 'bundle' => $found_bundle ] );
		$html = ob_get_clean();

		wp_send_json_success( [ 'html' => $html ] );
	}

	/**
	 * Add bundle products to cart AJAX endpoint.
	 */
	public function sob_add_bundle_to_cart(): void {
		$this->verify_security_token();

		$bundle_id = isset( $_POST['bundle_id'] ) ? absint( $_POST['bundle_id'] ) : 0;
		$source    = isset( $_POST['source'] ) ? sanitize_text_field( $_POST['source'] ) : 'manual';

		if ( ! $bundle_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing bundle ID.', 'smart-order-builder' ) ] );
		}

		// Retrieve bundle products.
		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
		
		$found_bundle = null;
		foreach ( $active_bundles as $b ) {
			if ( $b['id'] == $bundle_id ) {
				$found_bundle = $b;
				break;
			}
		}

		if ( ! $found_bundle ) {
			wp_send_json_error( [ 'message' => __( 'Bundle not found.', 'smart-order-builder' ) ] );
		}

		// Generate unique bundle group ID.
		$group_id = uniqid( 'sob_bg_' );
		$cart_item_data = [
			'sob_bundle_id'       => $bundle_id,
			'sob_bundle_group_id' => $group_id,
			'sob_bundle_source'   => $source,
		];

		// Add products in bundle to cart as individual items.
		$added_any = false;
		foreach ( $found_bundle['product_details'] as $item ) {
			$prod_id = $item['id'];
			$qty     = isset( $item['qty'] ) ? max( 1, intval( $item['qty'] ) ) : 1;

			$product = wc_get_product( $prod_id );
			if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
				WC()->cart->add_to_cart( $prod_id, $qty );
				$added_any = true;
			}
		}

		if ( $added_any ) {
			$this->send_cart_state_json( sprintf( __( '"%s" added to order.', 'smart-order-builder' ), $found_bundle['name'] ) );
		} else {
			wp_send_json_error( [ 'message' => __( 'Products in this bundle are currently out of stock.', 'smart-order-builder' ) ] );
		}
	}

	/**
	 * Update quantity of all items in a bundle group.
	 */
	public function sob_update_bundle_quantity(): void {
		$this->verify_security_token();

		$group_id = isset( $_POST['bundle_group_id'] ) ? sanitize_text_field( $_POST['bundle_group_id'] ) : '';
		$quantity = isset( $_POST['quantity'] ) ? max( 0, intval( $_POST['quantity'] ) ) : 1;

		if ( empty( $group_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Missing bundle group ID.', 'smart-order-builder' ) ] );
		}

		// Find the bundle ID and source from the cart items
		$bundle_id = 0;
		$source    = 'manual';
		$group_items = [];

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['sob_bundle_group_id'] ) && $cart_item['sob_bundle_group_id'] === $group_id ) {
				$bundle_id = intval( $cart_item['sob_bundle_id'] );
				if ( isset( $cart_item['sob_bundle_source'] ) ) {
					$source = $cart_item['sob_bundle_source'];
				}
				$group_items[$cart_item_key] = $cart_item;
			}
		}

		if ( ! $bundle_id || empty( $group_items ) ) {
			wp_send_json_error( [ 'message' => __( 'Bundle group not found in cart.', 'smart-order-builder' ) ] );
		}

		if ( $quantity === 0 ) {
			// Remove all items in this group
			foreach ( array_keys( $group_items ) as $cart_item_key ) {
				WC()->cart->remove_cart_item( $cart_item_key );
			}
			$this->send_cart_state_json( __( 'Bundle removed from order.', 'smart-order-builder' ) );
			return;
		}

		// Retrieve bundle definition to get base quantities
		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
		
		$found_bundle = null;
		foreach ( $active_bundles as $b ) {
			if ( $b['id'] == $bundle_id ) {
				$found_bundle = $b;
				break;
			}
		}

		if ( ! $found_bundle ) {
			wp_send_json_error( [ 'message' => __( 'Bundle definition not found.', 'smart-order-builder' ) ] );
		}

		// Map product ID to base quantity
		$base_qtys = [];
		foreach ( $found_bundle['product_details'] as $item ) {
			$base_qtys[$item['id']] = isset( $item['qty'] ) ? max( 1, intval( $item['qty'] ) ) : 1;
		}

		// Update quantities
		foreach ( $group_items as $cart_item_key => $cart_item ) {
			$prod_id = $cart_item['product_id'];
			$base_qty = isset( $base_qtys[$prod_id] ) ? $base_qtys[$prod_id] : 1;
			$new_qty = $base_qty * $quantity;
			WC()->cart->set_quantity( $cart_item_key, $new_qty, false );
		}

		// Re-calculate totals
		WC()->cart->calculate_totals();

		$this->send_cart_state_json( __( 'Bundle quantity updated.', 'smart-order-builder' ) );
	}

	/**
	 * Add suggested product to cart AJAX endpoint.
	 */
	public function sob_add_suggested_product(): void {
		$this->verify_security_token();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing product ID.', 'smart-order-builder' ) ] );
		}

		$product = wc_get_product( $product_id );
		if ( $product && $product->is_purchasable() && $product->is_in_stock() ) {
			WC()->cart->add_to_cart( $product_id, 1 );
			$this->send_cart_state_json( __( 'Suggested product added to cart.', 'smart-order-builder' ) );
		} else {
			wp_send_json_error( [ 'message' => __( 'This product is currently unavailable.', 'smart-order-builder' ) ] );
		}
	}

	/**
	 * Admin AJAX product search for Custom Post Type metabox Select2.
	 */
	public function sob_admin_search_products(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized access.', 'smart-order-builder' ) ], 403 );
		}

		if ( ! check_ajax_referer( 'sob_admin_search_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'smart-order-builder' ) ], 403 );
		}

		$search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
		
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			's'              => $search,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		// SKU search fallback if search query is SKU
		$product_id_by_sku = wc_get_product_id_by_sku( $search );
		if ( $product_id_by_sku ) {
			$args['post__in'] = [ $product_id_by_sku ];
			unset( $args['s'] );
		}

		$query = new WP_Query( $args );
		$results = [];

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$product = wc_get_product( $post->ID );
				if ( $product ) {
					$text = $product->get_name() . ' (#' . $product->get_id() . ')';
					$sku  = $product->get_sku();
					if ( ! empty( $sku ) ) {
						$text .= ' - SKU: ' . $sku;
					}
					$results[] = [
						'id'   => $post->ID,
						'text' => $text,
					];
				}
			}
		}

		wp_send_json( [ 'results' => $results ] );
	}

	/**
	 * Clear all items in WooCommerce cart.
	 */
	public function sob_clear_cart(): void {
		$this->verify_security_token();
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		$this->send_cart_state_json( __( 'Cart emptied.', 'smart-order-builder' ) );
	}
}
