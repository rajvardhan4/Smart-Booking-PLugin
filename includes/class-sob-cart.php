<?php
/**
 * Class SOB_Cart
 *
 * Manages WooCommerce cart interactions, fee calculations, bundle matching, and product suggestions.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Cart {

	/**
	 * Flag to prevent infinite loop during quantity updates.
	 *
	 * @var bool
	 */
	private static bool $updating_group_qtys = false;

	/**
	 * Constructor. Hooks into WooCommerce cart fee calculation and checkout.
	 */
	public function __construct() {
		// Calculate discounts during cart fee calculations.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_bundle_discounts' ], 10, 1 );

		// Save cart item bundle meta to order items.
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_item_meta' ], 10, 4 );

		// Customize order item name to display bundle membership in invoices/emails.
		add_filter( 'woocommerce_order_item_name', [ $this, 'customize_order_item_name' ], 10, 2 );

		// Customize cart item name to display bundle membership in Cart and Checkout reviews.
		add_filter( 'woocommerce_cart_item_name', [ $this, 'customize_cart_item_name' ], 10, 3 );

		// Display bundle info in admin order edit screen.
		add_action( 'woocommerce_before_order_itemmeta', [ $this, 'admin_order_item_bundle_badge' ], 10, 3 );

		// Cart item visibility: hide non-representative items
		add_filter( 'woocommerce_cart_item_visible', [ $this, 'filter_cart_item_visible' ], 10, 3 );
		add_filter( 'woocommerce_widget_cart_item_visible', [ $this, 'filter_cart_item_visible' ], 10, 3 );
		add_filter( 'woocommerce_checkout_cart_item_visible', [ $this, 'filter_cart_item_visible' ], 10, 3 );

		// Custom cart item display (thumbnail, price, subtotal, quantity input)
		add_filter( 'woocommerce_cart_item_thumbnail', [ $this, 'customize_cart_item_thumbnail' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_price', [ $this, 'customize_cart_item_price' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', [ $this, 'customize_cart_item_subtotal' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_quantity', [ $this, 'customize_cart_item_quantity' ], 10, 3 );
		add_filter( 'woocommerce_checkout_cart_item_quantity', [ $this, 'customize_checkout_cart_item_quantity' ], 10, 3 );

		// Cart actions: quantity updates and item removal
		add_action( 'woocommerce_after_cart_item_quantity_update', [ $this, 'after_cart_item_quantity_update' ], 10, 4 );
		add_action( 'woocommerce_cart_item_removed', [ $this, 'cart_item_removed' ], 10, 2 );

		// Order line item visibility and quantity HTML overrides
		add_filter( 'woocommerce_order_item_visible', [ $this, 'filter_order_item_visible' ], 10, 2 );
		add_filter( 'woocommerce_order_item_quantity_html', [ $this, 'customize_order_item_quantity_html' ], 10, 2 );

		// Getter filters for order line items to correct values in invoice/slip templates
		add_filter( 'woocommerce_order_item_get_quantity', [ $this, 'filter_order_item_get_quantity' ], 10, 2 );
		add_filter( 'woocommerce_order_item_get_subtotal', [ $this, 'filter_order_item_get_subtotal' ], 10, 2 );
		add_filter( 'woocommerce_order_item_get_total', [ $this, 'filter_order_item_get_total' ], 10, 2 );
	}

	/**
	 * Retrieve all cart items in a bundle group.
	 *
	 * @param string $group_id
	 * @return array
	 */
	public static function get_bundle_group_items( string $group_id ): array {
		if ( ! WC()->cart ) {
			return [];
		}
		$group_items = [];
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( isset( $item['sob_bundle_group_id'] ) && $item['sob_bundle_group_id'] === $group_id ) {
				$group_items[ $key ] = $item;
			}
		}
		ksort( $group_items );
		return $group_items;
	}

	/**
	 * Check if a cart item is the representative for its bundle group.
	 *
	 * @param string $cart_item_key
	 * @param array $cart_item
	 * @return bool
	 */
	public static function is_representative_bundle_item( string $cart_item_key, array $cart_item ): bool {
		if ( ! isset( $cart_item['sob_bundle_group_id'] ) ) {
			return false;
		}
		$group_items = self::get_bundle_group_items( $cart_item['sob_bundle_group_id'] );
		$keys = array_keys( $group_items );
		return ! empty( $keys ) && $keys[0] === $cart_item_key;
	}

	/**
	 * Retrieve all order line items in a bundle group.
	 *
	 * @param WC_Order $order
	 * @param string $group_id
	 * @return array
	 */
	public static function get_order_bundle_group_items( $order, string $group_id ): array {
		$group_items = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$item_group_id = $item->get_meta( '_sob_bundle_group_id' );
			if ( $item_group_id === $group_id ) {
				$group_items[ $item_id ] = $item;
			}
		}
		ksort( $group_items );
		return $group_items;
	}

	/**
	 * Check if an order item is the representative for its bundle group.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param WC_Order $order
	 * @return bool
	 */
	public static function is_representative_order_item( $item, $order ): bool {
		$group_id = $item->get_meta( '_sob_bundle_group_id' );
		if ( ! $group_id ) {
			return false;
		}
		$group_items = self::get_order_bundle_group_items( $order, $group_id );
		$keys = array_keys( $group_items );
		return ! empty( $keys ) && intval( $keys[0] ) === intval( $item->get_id() );
	}

	/**
	 * Hide non-representative cart items in bundle groups.
	 */
	public function filter_cart_item_visible( bool $visible, array $cart_item, string $cart_item_key ): bool {
		if ( isset( $cart_item['sob_bundle_group_id'] ) ) {
			return self::is_representative_bundle_item( $cart_item_key, $cart_item );
		}
		return $visible;
	}

	/**
	 * Show bundle featured image for representative cart items.
	 */
	public function customize_cart_item_thumbnail( string $thumbnail, array $cart_item, string $cart_item_key ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) && self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
			$bundle_id = $cart_item['sob_bundle_id'];
			if ( has_post_thumbnail( $bundle_id ) ) {
				return get_the_post_thumbnail( $bundle_id, [ 60, 60 ] );
			}
		}
		return $thumbnail;
	}

	/**
	 * Override cart item price display for representative bundle items.
	 */
	public function customize_cart_item_price( string $price_html, array $cart_item, string $cart_item_key ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) && self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
			$bundle_id = $cart_item['sob_bundle_id'];
			$source    = $cart_item['sob_bundle_source'] ?? 'manual';
			
			$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
			if ( $bundles_module ) {
				$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
				foreach ( $active_bundles as $b ) {
					if ( $b['id'] == $bundle_id ) {
						return wc_price( $b['bundle_price'] );
					}
				}
			}
		}
		return $price_html;
	}

	/**
	 * Override cart item subtotal display for representative bundle items.
	 */
	public function customize_cart_item_subtotal( string $subtotal_html, array $cart_item, string $cart_item_key ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) && self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
			$bundle_id = $cart_item['sob_bundle_id'];
			$source    = $cart_item['sob_bundle_source'] ?? 'manual';
			
			$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
			if ( $bundles_module ) {
				$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
				foreach ( $active_bundles as $b ) {
					if ( $b['id'] == $bundle_id ) {
						// Calculate bundle qty
						$group_id = $cart_item['sob_bundle_group_id'];
						$group_items = self::get_bundle_group_items( $group_id );
						$bundle_qty = 1;
						$first_item = reset( $group_items );
						if ( $first_item ) {
							$prod_id = $first_item['product_id'];
							$base_qty = 1;
							foreach ( $b['product_details'] as $detail ) {
								if ( $detail['id'] == $prod_id ) {
									$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
									break;
								}
							}
							$bundle_qty = max( 1, intval( $first_item['quantity'] / $base_qty ) );
						}
						return wc_price( $b['bundle_price'] * $bundle_qty );
					}
				}
			}
		}
		return $subtotal_html;
	}

	/**
	 * Output the quantity input for representative bundle items, showing bundle quantity.
	 */
	public function customize_cart_item_quantity( string $product_quantity, string $cart_item_key, array $cart_item ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) && self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
			$bundle_id = $cart_item['sob_bundle_id'];
			$source    = $cart_item['sob_bundle_source'] ?? 'manual';
			
			$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
			if ( $bundles_module ) {
				$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
				$found_bundle = null;
				foreach ( $active_bundles as $b ) {
					if ( $b['id'] == $bundle_id ) {
						$found_bundle = $b;
						break;
					}
				}
				
				if ( $found_bundle ) {
					$group_id = $cart_item['sob_bundle_group_id'];
					$group_items = self::get_bundle_group_items( $group_id );
					$bundle_qty = 1;
					$first_item = reset( $group_items );
					if ( $first_item ) {
						$prod_id = $first_item['product_id'];
						$base_qty = 1;
						foreach ( $found_bundle['product_details'] as $detail ) {
							if ( $detail['id'] == $prod_id ) {
								$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
								break;
							}
						}
						$bundle_qty = max( 1, intval( $first_item['quantity'] / $base_qty ) );
					}

					$_product = $cart_item['data'];
					if ( $_product ) {
						if ( $_product->is_sold_individually() ) {
							return sprintf( '1 <input type="hidden" name="cart[%s][qty]" value="1" />', $cart_item_key );
						} else {
							return woocommerce_quantity_input(
								[
									'input_name'   => "cart[{$cart_item_key}][qty]",
									'input_value'  => $bundle_qty,
									'max_value'    => $_product->get_max_purchase_quantity(),
									'min_value'    => '0',
									'product_name' => get_the_title( $bundle_id ),
								],
								$_product,
								false
							);
						}
					}
				}
			}
		}
		return $product_quantity;
	}

	/**
	 * Override checkout item quantity display for representative bundle items.
	 */
	public function customize_checkout_cart_item_quantity( string $qty_html, array $cart_item, string $cart_item_key ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) ) {
			if ( self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
				$bundle_id = $cart_item['sob_bundle_id'];
				$source    = $cart_item['sob_bundle_source'] ?? 'manual';
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				if ( $found_bundle ) {
					$group_id = $cart_item['sob_bundle_group_id'];
					$group_items = self::get_bundle_group_items( $group_id );
					$bundle_qty = 1;
					$first_item = reset( $group_items );
					if ( $first_item ) {
						$prod_id = $first_item['product_id'];
						$base_qty = 1;
						foreach ( $found_bundle['product_details'] as $detail ) {
							if ( $detail['id'] == $prod_id ) {
								$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
								break;
							}
						}
						$bundle_qty = max( 1, intval( $first_item['quantity'] / $base_qty ) );
					}
					return ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', $bundle_qty ) . '</strong>';
				}
			} else {
				return ''; // Hidden anyway
			}
		}
		return $qty_html;
	}

	/**
	 * Propagate quantity updates from representative item to other items in the same bundle.
	 */
	public function after_cart_item_quantity_update( string $cart_item_key, int $quantity, int $old_quantity, $cart ): void {
		if ( self::$updating_group_qtys ) {
			return;
		}

		$cart_item = $cart->get_cart()[ $cart_item_key ] ?? null;
		if ( ! $cart_item || ! isset( $cart_item['sob_bundle_group_id'] ) ) {
			return;
		}

		if ( ! self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
			return;
		}

		self::$updating_group_qtys = true;

		$group_id  = $cart_item['sob_bundle_group_id'];
		$bundle_id = $cart_item['sob_bundle_id'];
		$source    = $cart_item['sob_bundle_source'] ?? 'manual';

		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		if ( $bundles_module ) {
			$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
			$found_bundle = null;
			foreach ( $active_bundles as $b ) {
				if ( $b['id'] == $bundle_id ) {
					$found_bundle = $b;
					break;
				}
			}

			if ( $found_bundle ) {
				$base_qtys = [];
				foreach ( $found_bundle['product_details'] as $item ) {
					$base_qtys[ $item['id'] ] = isset( $item['qty'] ) ? max( 1, intval( $item['qty'] ) ) : 1;
				}

				$group_items = self::get_bundle_group_items( $group_id );
				foreach ( $group_items as $key => $item ) {
					$prod_id = $item['product_id'];
					$base_qty = $base_qtys[ $prod_id ] ?? 1;
					$new_qty  = $base_qty * $quantity;
					if ( intval( $item['quantity'] ) !== intval( $new_qty ) ) {
						$cart->set_quantity( $key, $new_qty, false );
					}
				}
			}
		}

		self::$updating_group_qtys = false;
	}

	/**
	 * Remove all other products in a bundle group when any item is removed.
	 */
	public function cart_item_removed( string $cart_item_key, $cart ): void {
		$cart_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;
		if ( ! $cart_item || ! isset( $cart_item['sob_bundle_group_id'] ) ) {
			return;
		}

		$group_id = $cart_item['sob_bundle_group_id'];
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( isset( $item['sob_bundle_group_id'] ) && $item['sob_bundle_group_id'] === $group_id ) {
				$cart->remove_cart_item( $key );
			}
		}
	}

	public function filter_order_item_visible( bool $visible, $item ): bool {
		// Do not filter visibility in backend admin order edit screen to allow admin edits/views.
		if ( is_admin() && ! wp_doing_ajax() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen && ( 'shop_order' === $screen->id || 'woocommerce_page_wc-orders' === $screen->id ) ) {
				return $visible;
			}
		}
		$order = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
		if ( ! $order ) {
			return $visible;
		}
		$group_id = $item->get_meta( '_sob_bundle_group_id' );
		if ( $group_id ) {
			return self::is_representative_order_item( $item, $order );
		}
		return $visible;
	}

	/**
	 * Save bundle meta to order items.
	 *
	 * @param WC_Order_Item_Product $item
	 * @param string $cart_item_key
	 * @param array $values
	 * @param WC_Order $order
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ): void {
		if ( isset( $values['sob_bundle_id'] ) ) {
			$item->update_meta_data( '_sob_bundle_id', $values['sob_bundle_id'] );
		}
		if ( isset( $values['sob_bundle_group_id'] ) ) {
			$item->update_meta_data( '_sob_bundle_group_id', $values['sob_bundle_group_id'] );
		}
		if ( isset( $values['sob_bundle_source'] ) ) {
			$item->update_meta_data( '_sob_bundle_source', $values['sob_bundle_source'] );
		}
	}

	/**
	 * Customize the display name of order items that belong to a bundle.
	 * Representative item displays the bundle title and a collapsible details accordion.
	 */
	public function customize_order_item_name( string $item_name, $item ): string {
		if ( is_admin() ) {
			global $pagenow;
			if ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) {
				return $item_name;
			}
			if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
				return $item_name;
			}
		}

		$bundle_id = $item->get_meta( '_sob_bundle_id' );
		$group_id  = $item->get_meta( '_sob_bundle_group_id' );
		
		if ( $bundle_id && $group_id ) {
			$order = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
			if ( $order && self::is_representative_order_item( $item, $order ) ) {
				$bundle_title = get_the_title( $bundle_id );
				$source       = $item->get_meta( '_sob_bundle_source' ) ?: 'manual';
				
				$group_items = self::get_order_bundle_group_items( $order, $group_id );
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				ob_start();
				?>
				<div class="sob-cart-bundle-display-wrap" data-group-id="<?php echo esc_attr( $group_id ); ?>">
					<div class="sob-cart-bundle-display-header" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
						<span class="sob-cart-bundle-display-toggle-icon" style="font-weight: bold; font-size: 12px; color: #64748b; margin-right: 5px; display: inline-block;">▶</span>
						<strong class="sob-cart-bundle-display-title" style="font-size: 1.1em;"><?php echo esc_html( $bundle_title ); ?></strong>
						<span class="sob-cart-bundle-display-badge" style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-left: 6px;"><?php esc_html_e( 'Bundle Package', 'smart-order-builder' ); ?></span>
					</div>
					<div class="sob-cart-bundle-display-details" style="display: none; padding-left: 18px; margin-top: 6px; font-size: 0.9em; color: #555;">
						<ul style="list-style: none; margin: 0; padding: 0;">
							<?php foreach ( $group_items as $g_item ) : 
								$prod = $g_item->get_product();
								if ( ! $prod ) continue;
								$qty_per_bundle = 1;
								if ( $found_bundle ) {
									foreach ( $found_bundle['product_details'] as $detail ) {
										if ( $detail['id'] == $prod->get_id() ) {
											$qty_per_bundle = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
											break;
										}
									}
								}
							?>
								<li style="margin-bottom: 4px;"><?php echo esc_html( $qty_per_bundle ); ?> &times; <?php echo esc_html( $prod->get_name() ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<?php
				return ob_get_clean();
			} else {
				return ''; // Hidden anyway
			}
		}
		return $item_name;
	}

	/**
	 * Customize the display name of cart items that belong to a bundle in Cart/Checkout reviews.
	 */
	public function customize_cart_item_name( string $item_name, array $cart_item, string $cart_item_key ): string {
		if ( isset( $cart_item['sob_bundle_id'] ) ) {
			if ( self::is_representative_bundle_item( $cart_item_key, $cart_item ) ) {
				$bundle_title = get_the_title( $cart_item['sob_bundle_id'] );
				$bundle_id    = $cart_item['sob_bundle_id'];
				$group_id     = $cart_item['sob_bundle_group_id'];
				$source       = $cart_item['sob_bundle_source'] ?? 'manual';
				
				$group_items = self::get_bundle_group_items( $group_id );
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				ob_start();
				?>
				<div class="sob-cart-bundle-display-wrap" data-group-id="<?php echo esc_attr( $group_id ); ?>">
					<div class="sob-cart-bundle-display-header" style="cursor: pointer; display: flex; align-items: center; gap: 8px;">
						<span class="sob-cart-bundle-display-toggle-icon" style="font-weight: bold; font-size: 12px; color: #64748b; margin-right: 5px; display: inline-block;">▶</span>
						<strong class="sob-cart-bundle-display-title" style="font-size: 1.1em;"><?php echo esc_html( $bundle_title ); ?></strong>
						<span class="sob-cart-bundle-display-badge" style="background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 0.8em; font-weight: bold; margin-left: 6px;"><?php esc_html_e( 'Bundle Package', 'smart-order-builder' ); ?></span>
					</div>
					<div class="sob-cart-bundle-display-details" style="display: none; padding-left: 18px; margin-top: 6px; font-size: 0.9em; color: #555;">
						<ul style="list-style: none; margin: 0; padding: 0;">
							<?php foreach ( $group_items as $g_key => $g_item ) : 
								$prod = $g_item['data'];
								$qty_per_bundle = 1;
								if ( $found_bundle ) {
									foreach ( $found_bundle['product_details'] as $detail ) {
										if ( $detail['id'] == $prod->get_id() ) {
											$qty_per_bundle = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
											break;
										}
									}
								}
							?>
								<li style="margin-bottom: 4px;"><?php echo esc_html( $qty_per_bundle ); ?> &times; <?php echo esc_html( $prod->get_name() ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
				<?php
				return ob_get_clean();
			} else {
				return ''; // Hidden anyway
			}
		}
		return $item_name;
	}

	/**
	 * Override the display quantity of order items inside representative bundles.
	 */
	public function customize_order_item_quantity_html( string $qty_html, $item ): string {
		$bundle_id = $item->get_meta( '_sob_bundle_id' );
		$group_id  = $item->get_meta( '_sob_bundle_group_id' );
		$order     = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
		
		if ( $bundle_id && $group_id && $order ) {
			if ( self::is_representative_order_item( $item, $order ) ) {
				$source = $item->get_meta( '_sob_bundle_source' ) ?: 'manual';
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				$bundle_qty = 1;
				if ( $found_bundle ) {
					$prod_id = $item->get_product_id();
					$base_qty = 1;
					foreach ( $found_bundle['product_details'] as $detail ) {
						if ( $detail['id'] == $prod_id ) {
							$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
							break;
						}
					}
					$bundle_qty = max( 1, intval( $item->get_quantity() / $base_qty ) );
				}
				return ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', $bundle_qty ) . '</strong>';
			}
		}
		return $qty_html;
	}

	/**
	 * Filter get_quantity() of order items to show bundle quantities.
	 */
	public function filter_order_item_get_quantity( $qty, $item ) {
		if ( is_admin() ) {
			global $pagenow;
			if ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) {
				return $qty;
			}
			if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
				return $qty;
			}
		}
		$bundle_id = $item->get_meta( '_sob_bundle_id' );
		$group_id  = $item->get_meta( '_sob_bundle_group_id' );
		$order     = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
		
		if ( $bundle_id && $group_id && $order ) {
			if ( self::is_representative_order_item( $item, $order ) ) {
				$source = $item->get_meta( '_sob_bundle_source' ) ?: 'manual';
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				if ( $found_bundle ) {
					$prod_id = $item->get_product_id();
					$base_qty = 1;
					foreach ( $found_bundle['product_details'] as $detail ) {
						if ( $detail['id'] == $prod_id ) {
							$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
							break;
						}
					}
					return max( 1, intval( $qty / $base_qty ) );
				}
			} else {
				return 0; // Hiding non-representatives
			}
		}
		return $qty;
	}

	/**
	 * Filter get_subtotal() of order items to show bundle subtotals.
	 */
	public function filter_order_item_get_subtotal( $subtotal, $item ) {
		if ( is_admin() ) {
			global $pagenow;
			if ( 'post.php' === $pagenow || 'post-new.php' === $pagenow ) {
				return $subtotal;
			}
			if ( isset( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
				return $subtotal;
			}
		}
		$bundle_id = $item->get_meta( '_sob_bundle_id' );
		$group_id  = $item->get_meta( '_sob_bundle_group_id' );
		$order     = method_exists( $item, 'get_order' ) ? $item->get_order() : null;
		
		if ( $bundle_id && $group_id && $order ) {
			if ( self::is_representative_order_item( $item, $order ) ) {
				$source = $item->get_meta( '_sob_bundle_source' ) ?: 'manual';
				
				$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
				$found_bundle = null;
				if ( $bundles_module ) {
					$active_bundles = $bundles_module->get_active_bundles( $source, 100 );
					foreach ( $active_bundles as $b ) {
						if ( $b['id'] == $bundle_id ) {
							$found_bundle = $b;
							break;
						}
					}
				}
				
				if ( $found_bundle ) {
					$prod_id = $item->get_product_id();
					$base_qty = 1;
					foreach ( $found_bundle['product_details'] as $detail ) {
						if ( $detail['id'] == $prod_id ) {
							$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
							break;
						}
					}
					$bundle_qty = max( 1, intval( $item->get_quantity() / $base_qty ) );
					return $found_bundle['bundle_price'] * $bundle_qty;
				}
			} else {
				return 0; // Hiding non-representatives
			}
		}
		return $subtotal;
	}

	/**
	 * Filter get_total() of order items to show bundle totals.
	 */
	public function filter_order_item_get_total( $total, $item ) {
		return $this->filter_order_item_get_subtotal( $total, $item );
	}

	/**
	 * Display bundle info in admin order edit screen.
	 */
	public function admin_order_item_bundle_badge( $item_id, $item, $product ): void {
		$bundle_id = $item->get_meta( '_sob_bundle_id' );
		if ( $bundle_id ) {
			$bundle_title = get_the_title( $bundle_id );
			$group_id = $item->get_meta( '_sob_bundle_group_id' );
			echo '<div class="sob-admin-bundle-badge" data-group-id="' . esc_attr( $group_id ) . '" style="background: #e0f2fe; color: #0369a1; padding: 4px 8px; border-radius: 4px; display: inline-block; font-size: 11px; font-weight: bold; margin-bottom: 5px;">';
			echo esc_html__( 'Part of Bundle: ', 'smart-order-builder' ) . esc_html( $bundle_title );
			echo '</div>';
		}
	}

	/**
	 * Retrieve the quantity of a specific product ID (simple or variation) in the WooCommerce cart.
	 *
	 * @param int $product_id Product ID to check.
	 * @return int
	 */
	public static function get_product_cart_quantity( int $product_id ): int {
		if ( ! WC()->cart ) {
			return 0;
		}

		$qty = 0;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( $cart_item['product_id'] == $product_id || $cart_item['variation_id'] == $product_id ) {
				$qty += $cart_item['quantity'];
			}
		}
		return $qty;
	}

	/**
	 * Scans the cart and active bundles to apply bundle discounts as a negative fee.
	 *
	 * @param WC_Cart $cart WooCommerce Cart object.
	 */
	public function apply_bundle_discounts( WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		$settings = SOB_Admin::get_settings();
		if ( 'yes' !== $settings['enabled'] || 'yes' !== $settings['enable_recommended_bundles'] ) {
			return;
		}

		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		if ( ! $bundles_module ) {
			return;
		}

		// Retrieve all active bundles.
		$active_bundles = $bundles_module->get_active_bundles( $settings['bundle_source'], 100 );
		if ( empty( $active_bundles ) ) {
			return;
		}

		// 1. Calculate discounts for grouped bundles (explicitly added).
		$grouped_bundles = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['sob_bundle_group_id'] ) ) {
				$group_id = $cart_item['sob_bundle_group_id'];
				if ( ! isset( $grouped_bundles[ $group_id ] ) ) {
					$grouped_bundles[ $group_id ] = [
						'bundle_id' => $cart_item['sob_bundle_id'],
						'source'    => isset( $cart_item['sob_bundle_source'] ) ? $cart_item['sob_bundle_source'] : 'manual',
						'items'     => [],
					];
				}
				$grouped_bundles[ $group_id ]['items'][] = $cart_item;
			}
		}

		foreach ( $grouped_bundles as $group_id => $group_data ) {
			$bundle_id = $group_data['bundle_id'];
			$source    = $group_data['source'];
			
			// Find bundle definition
			$found_bundle = null;
			foreach ( $active_bundles as $b ) {
				if ( $b['id'] == $bundle_id ) {
					$found_bundle = $b;
					break;
				}
			}

			if ( $found_bundle ) {
				$bundle_qty = 1;
				$first_item = reset( $group_data['items'] );
				if ( $first_item ) {
					$prod_id = $first_item['product_id'];
					$base_qty = 1;
					foreach ( $found_bundle['product_details'] as $detail ) {
						if ( $detail['id'] == $prod_id ) {
							$base_qty = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
							break;
						}
					}
					$bundle_qty = max( 1, intval( $first_item['quantity'] / $base_qty ) );
				}

				$discount_amount = floatval( $found_bundle['savings'] ) * $bundle_qty;
				if ( $discount_amount > 0 ) {
					$fee_name = sprintf( __( 'Bundle Discount: %s', 'smart-order-builder' ), $found_bundle['name'] );
					$cart->add_fee( $fee_name, -$discount_amount, false );
				}
			}
		}

		// 2. Calculate discounts for implicitly formed bundles (items added individually).
		// Map current cart item quantities in a temporary array, EXCLUDING grouped bundles.
		$cart_quantities = [];
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['sob_bundle_group_id'] ) ) {
				continue; // Already processed above
			}
			$prod_id = $cart_item['product_id'];
			$var_id  = $cart_item['variation_id'];
			$item_id = $var_id > 0 ? $var_id : $prod_id;

			if ( ! isset( $cart_quantities[ $item_id ] ) ) {
				$cart_quantities[ $item_id ] = 0;
			}
			$cart_quantities[ $item_id ] += $cart_item['quantity'];

			if ( $var_id > 0 ) {
				if ( ! isset( $cart_quantities[ $prod_id ] ) ) {
					$cart_quantities[ $prod_id ] = 0;
				}
				$cart_quantities[ $prod_id ] += $cart_item['quantity'];
			}
		}

		// Sort bundles by savings descending to maximize customer discount (greedy approach).
		usort( $active_bundles, function( $a, $b ) {
			return $b['savings'] <=> $a['savings'];
		} );

		// Process matching for implicit bundles.
		foreach ( $active_bundles as $bundle ) {
			$needed_qty_map = [];
			
			// Map using actual quantity requirements
			foreach ( $bundle['product_details'] as $detail ) {
				$prod_id = $detail['id'];
				$qty     = isset( $detail['qty'] ) ? max( 1, intval( $detail['qty'] ) ) : 1;
				$needed_qty_map[ $prod_id ] = $qty;
			}

			if ( empty( $needed_qty_map ) ) {
				continue;
			}

			// Determine how many full sets of this bundle are in the cart.
			$possible_sets = 99999;
			foreach ( $needed_qty_map as $prod_id => $needed_qty ) {
				$in_cart_qty = $cart_quantities[ $prod_id ] ?? 0;
				if ( $in_cart_qty < $needed_qty ) {
					$possible_sets = 0;
					break;
				}
				$sets = floor( $in_cart_qty / $needed_qty );
				if ( $sets < $possible_sets ) {
					$possible_sets = $sets;
				}
			}

			// Apply negative fee if sets > 0.
			if ( $possible_sets > 0 && $possible_sets < 99999 ) {
				$discount_amount = $bundle['savings'] * $possible_sets;
				
				if ( $discount_amount > 0 ) {
					$fee_name = sprintf( __( 'Bundle Discount: %s (Auto-Match)', 'smart-order-builder' ), $bundle['name'] );
					$cart->add_fee( $fee_name, -$discount_amount, false );

					// Deduct matched quantities from pool to prevent double discount.
					foreach ( $needed_qty_map as $prod_id => $needed_qty ) {
						$deduct_qty = $needed_qty * $possible_sets;
						$cart_quantities[ $prod_id ] -= $deduct_qty;
						
						// If a variation, also deduct from parent pool.
						$product = wc_get_product( $prod_id );
						if ( $product && $product->get_parent_id() > 0 ) {
							$parent_id = $product->get_parent_id();
							if ( isset( $cart_quantities[ $parent_id ] ) ) {
								$cart_quantities[ $parent_id ] -= $deduct_qty;
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Retrieve calculated totals from the cart.
	 *
	 * @return array
	 */
	public static function get_cart_summary_data(): array {
		if ( ! WC()->cart ) {
			return [
				'total_items'    => 0,
				'subtotal'       => 0.00,
				'bundle_savings' => 0.00,
				'discounts'      => 0.00,
				'tax'            => 0.00,
				'total'          => 0.00,
			];
		}

		$total_items    = WC()->cart->get_cart_contents_count();
		$subtotal       = floatval( WC()->cart->get_subtotal() );
		$discounts      = floatval( WC()->cart->get_discount_total() );
		$tax            = floatval( WC()->cart->get_taxes_total() );
		$total          = floatval( WC()->cart->get_total( 'edit' ) );

		// Retrieve bundle savings by summing up our negative bundle fees.
		$bundle_savings = 0.00;
		foreach ( WC()->cart->get_fees() as $fee ) {
			if ( strpos( $fee->name, 'Bundle Discount:' ) === 0 ) {
				$bundle_savings += abs( floatval( $fee->amount ) );
			}
		}

		return [
			'total_items'    => $total_items,
			'subtotal'       => $subtotal,
			'bundle_savings' => $bundle_savings,
			'discounts'      => $discounts,
			'tax'            => $tax,
			'total'          => $total,
		];
	}

	/**
	 * Find if a customer is close to completing a bundle.
	 * Returns banner metadata if exactly N - 1 items of a bundle are in cart.
	 *
	 * @return array|null
	 */
	public static function get_upgrade_banner_data(): ?array {
		$settings = SOB_Admin::get_settings();
		if ( 'yes' !== $settings['enabled'] || 'yes' !== $settings['enable_upgrade_banner'] ) {
			return null;
		}

		$bundles_module = SOB_Plugin::get_instance()->get_module( 'bundles' );
		if ( ! $bundles_module ) {
			return null;
		}

		$active_bundles = $bundles_module->get_active_bundles( $settings['bundle_source'], 100 );
		if ( empty( $active_bundles ) ) {
			return null;
		}

		// Get items in cart.
		$cart_item_ids = [];
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$cart_item_ids[] = intval( $cart_item['product_id'] );
				if ( intval( $cart_item['variation_id'] ) > 0 ) {
					$cart_item_ids[] = intval( $cart_item['variation_id'] );
				}
			}
		}
		$cart_item_ids = array_unique( $cart_item_ids );

		// Look for a bundle where they have N - 1 products in the cart.
		foreach ( $active_bundles as $bundle ) {
			$bundle_products = array_map( 'intval', $bundle['products'] );
			$bundle_count    = count( $bundle_products );

			// Banner only applies to bundles with 2 or more products
			if ( $bundle_count < 2 ) {
				continue;
			}

			// Find intersection (products customer has in cart that belong to this bundle).
			$intersection = array_intersect( $bundle_products, $cart_item_ids );
			$present_count = count( $intersection );

			// If they have N - 1 products in the cart.
			if ( $present_count === ( $bundle_count - 1 ) ) {
				$missing_ids = array_diff( $bundle_products, $cart_item_ids );
				$missing_product_names = [];
				$missing_product_ids = [];

				foreach ( $missing_ids as $m_id ) {
					$prod = wc_get_product( $m_id );
					if ( $prod && $prod->is_purchasable() && $prod->is_in_stock() ) {
						$missing_product_names[] = $prod->get_name();
						$missing_product_ids[] = $m_id;
					}
				}

				// Only suggest if the missing product(s) are actually purchasable/in stock.
				if ( ! empty( $missing_product_ids ) ) {
					return [
						'bundle_id'           => $bundle['id'],
						'bundle_name'         => $bundle['name'],
						'savings'             => $bundle['savings'],
						'missing_products'    => $missing_product_names,
						'missing_product_ids' => $missing_product_ids,
					];
				}
			}
		}

		return null;
	}

	/**
	 * Retrieve product suggestions for the "Complete Your Order" sidebar.
	 *
	 * @param int $limit Number of recommendations.
	 * @return array Array of product IDs.
	 */
	public static function get_cart_suggestions( int $limit = 4 ): array {
		if ( ! WC()->cart ) {
			return [];
		}

		$cart_contents = WC()->cart->get_cart();
		$cart_product_ids = [];
		foreach ( $cart_contents as $item ) {
			$cart_product_ids[] = $item['product_id'];
		}
		$cart_product_ids = array_unique( $cart_product_ids );

		$suggested_ids = [];

		// 1. Related/Upsell/Cross-sell products.
		if ( ! empty( $cart_product_ids ) ) {
			foreach ( $cart_product_ids as $prod_id ) {
				$product = wc_get_product( $prod_id );
				if ( $product ) {
					// Merge cross-sells.
					$cross_sells = $product->get_cross_sell_ids();
					if ( ! empty( $cross_sells ) ) {
						$suggested_ids = array_merge( $suggested_ids, $cross_sells );
					}
					
					// Merge upsells.
					$upsells = $product->get_upsell_ids();
					if ( ! empty( $upsells ) ) {
						$suggested_ids = array_merge( $suggested_ids, $upsells );
					}

					// Merge related.
					$related = wc_get_related_products( $prod_id, $limit * 2 );
					if ( ! empty( $related ) ) {
						$suggested_ids = array_merge( $suggested_ids, $related );
					}
				}
			}
		}

		$suggested_ids = array_unique( $suggested_ids );
		// Filter out items already in the cart.
		$suggested_ids = array_diff( $suggested_ids, $cart_product_ids );

		// Validate that suggestion IDs are publish, purchasable, and in stock.
		$valid_suggestions = [];
		foreach ( $suggested_ids as $id ) {
			$prod = wc_get_product( $id );
			if ( $prod && $prod->is_visible() && $prod->is_purchasable() && $prod->is_in_stock() ) {
				$valid_suggestions[] = $id;
				if ( count( $valid_suggestions ) >= $limit ) {
					break;
				}
			}
		}

		// 2. Fallback: If not enough suggestions, fetch latest in-stock products.
		if ( count( $valid_suggestions ) < $limit ) {
			$needed = $limit - count( $valid_suggestions );
			
			$args = [
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $needed + count( $cart_product_ids ) + 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
			];

			$query = new WP_Query( $args );
			if ( ! is_wp_error( $query ) && ! empty( $query->posts ) ) {
				foreach ( $query->posts as $id ) {
					if ( in_array( $id, $cart_product_ids, true ) || in_array( $id, $valid_suggestions, true ) ) {
						continue;
					}
					
					$prod = wc_get_product( $id );
					if ( $prod && $prod->is_visible() && $prod->is_purchasable() && $prod->is_in_stock() ) {
						$valid_suggestions[] = $id;
						if ( count( $valid_suggestions ) >= $limit ) {
							break;
						}
					}
				}
			}
		}

		return array_slice( $valid_suggestions, 0, $limit );
	}
}
