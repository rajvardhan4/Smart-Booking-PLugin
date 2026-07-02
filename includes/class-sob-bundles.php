<?php
/**
 * Class SOB_Bundles
 *
 * Handles Custom Post Type registration and queries for Smart Order Builder bundles.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Bundles {

	/**
	 * Constructor. Registers the CPT and metabox hooks.
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_bundle_cpt' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_bundle_metaboxes' ] );
		add_action( 'save_post_sob_bundle', [ $this, 'save_bundle_meta' ] );

		// Custom admin columns for Bundle CPT.
		add_filter( 'manage_sob_bundle_posts_columns', [ $this, 'set_bundle_columns' ] );
		add_action( 'manage_sob_bundle_posts_custom_column', [ $this, 'render_bundle_columns' ], 10, 2 );
	}

	/**
	 * Register the Custom Post Type 'sob_bundle'.
	 */
	public function register_bundle_cpt(): void {
		$labels = [
			'name'               => _x( 'Bundles', 'post type general name', 'smart-order-builder' ),
			'singular_name'      => _x( 'Bundle', 'post type singular name', 'smart-order-builder' ),
			'menu_name'          => _x( 'Smart Bundles', 'admin menu', 'smart-order-builder' ),
			'name_admin_bar'     => _x( 'Bundle', 'add new on admin bar', 'smart-order-builder' ),
			'add_new'            => _x( 'Add New', 'bundle', 'smart-order-builder' ),
			'add_new_item'       => __( 'Add New Bundle', 'smart-order-builder' ),
			'new_item'           => __( 'New Bundle', 'smart-order-builder' ),
			'edit_item'          => __( 'Edit Bundle', 'smart-order-builder' ),
			'view_item'          => __( 'View Bundle', 'smart-order-builder' ),
			'all_items'          => __( 'All Bundles', 'smart-order-builder' ),
			'search_items'       => __( 'Search Bundles', 'smart-order-builder' ),
			'parent_item_colon'  => __( 'Parent Bundles:', 'smart-order-builder' ),
			'not_found'          => __( 'No bundles found.', 'smart-order-builder' ),
			'not_found_in_trash' => __( 'No bundles found in Trash.', 'smart-order-builder' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => false, // Keep private, used only inside order builder
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'woocommerce', // Place under WooCommerce menu
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => [ 'title', 'thumbnail' ], // Title and Image
			'show_in_rest'       => false,
		];

		register_post_type( 'sob_bundle', $args );
	}

	/**
	 * Add Meta Boxes to 'sob_bundle' custom post type.
	 */
	public function add_bundle_metaboxes(): void {
		add_meta_box(
			'sob_bundle_details',
			__( 'Bundle Details', 'smart-order-builder' ),
			[ $this, 'render_bundle_metabox' ],
			'sob_bundle',
			'normal',
			'high'
		);
	}

	/**
	 * Render the bundle details metabox.
	 *
	 * @param WP_Post $post Current post object.
	 */
	public function render_bundle_metabox( WP_Post $post ): void {
		// Nonce for security verification.
		wp_nonce_field( 'sob_save_bundle_meta', 'sob_bundle_meta_nonce' );

		// Retrieve current values.
		$selected_products = get_post_meta( $post->ID, '_sob_bundle_products', true );
		if ( ! is_array( $selected_products ) ) {
			$selected_products = [];
		}

		$selected_qtys = get_post_meta( $post->ID, '_sob_bundle_product_quantities', true );
		if ( ! is_array( $selected_qtys ) ) {
			$selected_qtys = [];
		}

		$bundle_price = get_post_meta( $post->ID, '_sob_bundle_price', true );
		$bundle_priority = get_post_meta( $post->ID, '_sob_bundle_priority', true );
		if ( '' === $bundle_priority ) {
			$bundle_priority = 0;
		}

		// Renders the view
		?>
		<div class="sob-metabox-wrapper">
			<style>
				.sob-meta-row { margin-bottom: 20px; }
				.sob-meta-row label { display: block; font-weight: bold; margin-bottom: 5px; }
				.sob-meta-row input[type="text"], .sob-meta-row input[type="number"], .sob-meta-row select { width: 100%; max-width: 400px; padding: 8px; }
				.sob-meta-row .description { color: #666; font-size: 12px; margin-top: 4px; display: block; }
				.sob-bundle-products-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
				.sob-bundle-products-table th, .sob-bundle-products-table td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
				.sob-bundle-products-table th { background: #f8fafc; font-weight: bold; }
			</style>
			
			<div class="sob-meta-row">
				<label for="sob_product_search"><?php esc_html_e( 'Search & Add Products', 'smart-order-builder' ); ?></label>
				<select id="sob_product_search" class="sob-product-search-select" style="width:100%; max-width:500px;">
					<option value=""></option>
				</select>
				<span class="description"><?php esc_html_e( 'Search for WooCommerce products and add them to this bundle.', 'smart-order-builder' ); ?></span>
			</div>

			<div class="sob-meta-row">
				<label><?php esc_html_e( 'Selected Products & Quantities', 'smart-order-builder' ); ?></label>
				<table class="sob-bundle-products-table" style="max-width: 600px; border: 1px solid #ddd; background: #fff;">
					<thead>
						<tr>
							<th style="width: 60%;"><?php esc_html_e( 'Product', 'smart-order-builder' ); ?></th>
							<th style="width: 25%;"><?php esc_html_e( 'Quantity', 'smart-order-builder' ); ?></th>
							<th style="width: 15%; text-align: center;"><?php esc_html_e( 'Action', 'smart-order-builder' ); ?></th>
						</tr>
					</thead>
					<tbody id="sob-bundle-products-list">
						<?php
						if ( ! empty( $selected_products ) ) {
							foreach ( $selected_products as $product_id ) {
								$product = wc_get_product( $product_id );
								if ( $product ) {
									$qty = isset( $selected_qtys[ $product_id ] ) ? max( 1, absint( $selected_qtys[ $product_id ] ) ) : 1;
									$name = $product->get_name() . ' (#' . $product->get_id() . ')';
									?>
									<tr data-product-id="<?php echo esc_attr( $product_id ); ?>">
										<td>
											<strong><?php echo esc_html( $name ); ?></strong>
											<input type="hidden" name="sob_bundle_products[]" value="<?php echo esc_attr( $product_id ); ?>" />
										</td>
										<td>
											<input type="number" name="sob_bundle_product_qtys[<?php echo esc_attr( $product_id ); ?>]" value="<?php echo esc_attr( $qty ); ?>" min="1" style="width: 80px;" />
										</td>
										<td style="text-align: center;">
											<button type="button" class="button sob-remove-bundled-product" style="color: #b32d2e; border-color: #b32d2e;"><?php esc_html_e( 'Remove', 'smart-order-builder' ); ?></button>
										</td>
									</tr>
									<?php
								}
							}
						} else {
							?>
							<tr class="sob-no-products-row">
								<td colspan="3" style="text-align: center; color: #666; font-style: italic;"><?php esc_html_e( 'No products added to this bundle yet.', 'smart-order-builder' ); ?></td>
							</tr>
							<?php
						}
						?>
					</tbody>
				</table>
			</div>

			<div class="sob-meta-row">
				<label for="sob_bundle_price"><?php esc_html_e( 'Bundle Discounted Price', 'smart-order-builder' ); ?> (<?php echo esc_html( get_woocommerce_currency_symbol() ); ?>)</label>
				<input type="text" name="sob_bundle_price" id="sob_bundle_price" value="<?php echo esc_attr( $bundle_price ); ?>" placeholder="e.g. 99.99" />
				<span class="description"><?php esc_html_e( 'The price for the entire bundle package. Leave empty for no discount.', 'smart-order-builder' ); ?></span>
			</div>

			<div class="sob-meta-row">
				<label for="sob_bundle_priority"><?php esc_html_e( 'Bundle Priority / Order', 'smart-order-builder' ); ?></label>
				<input type="number" name="sob_bundle_priority" id="sob_bundle_priority" value="<?php echo esc_attr( $bundle_priority ); ?>" min="0" step="1" />
				<span class="description"><?php esc_html_e( 'Higher values display first in recommendations.', 'smart-order-builder' ); ?></span>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// When Select2 changes, add product to table
			$('#sob_product_search').on('select2:select', function(e) {
				var data = e.params.data;
				if (!data.id) return;

				// Check if product already exists in table
				if ($('#sob-bundle-products-list tr[data-product-id="' + data.id + '"]').length > 0) {
					alert('Product is already in this bundle.');
					$('#sob_product_search').val(null).trigger('change');
					return;
				}

				// Remove "no products" row if it exists
				$('#sob-bundle-products-list .sob-no-products-row').remove();

				// Add row
				var rowHtml = '<tr data-product-id="' + data.id + '">' +
					'<td><strong>' + data.text + '</strong><input type="hidden" name="sob_bundle_products[]" value="' + data.id + '" /></td>' +
					'<td><input type="number" name="sob_bundle_product_qtys[' + data.id + ']" value="1" min="1" style="width: 80px;" /></td>' +
					'<td style="text-align: center;"><button type="button" class="button sob-remove-bundled-product" style="color: #b32d2e; border-color: #b32d2e;">Remove</button></td>' +
					'</tr>';

				$('#sob-bundle-products-list').append(rowHtml);

				// Clear selection
				$('#sob_product_search').val(null).trigger('change');
			});

			// Remove product row
			$(document).on('click', '.sob-remove-bundled-product', function() {
				$(this).closest('tr').remove();
				if ($('#sob-bundle-products-list tr').length === 0) {
					$('#sob-bundle-products-list').append(
						'<tr class="sob-no-products-row"><td colspan="3" style="text-align: center; color: #666; font-style: italic;">No products added to this bundle yet.</td></tr>'
					);
				}
			});
		});
		</script>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int $post_id The post ID.
	 */
	public function save_bundle_meta( int $post_id ): void {
		// Verify nonce.
		if ( ! isset( $_POST['sob_bundle_meta_nonce'] ) || ! wp_verify_nonce( $_POST['sob_bundle_meta_nonce'], 'sob_save_bundle_meta' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save products.
		if ( isset( $_POST['sob_bundle_products'] ) && is_array( $_POST['sob_bundle_products'] ) ) {
			$products = array_map( 'absint', $_POST['sob_bundle_products'] );
			update_post_meta( $post_id, '_sob_bundle_products', $products );

			// Save quantities mapping.
			$quantities = [];
			if ( isset( $_POST['sob_bundle_product_qtys'] ) && is_array( $_POST['sob_bundle_product_qtys'] ) ) {
				foreach ( $_POST['sob_bundle_product_qtys'] as $prod_id => $qty ) {
					$prod_id = absint( $prod_id );
					$qty = max( 1, absint( $qty ) );
					if ( in_array( $prod_id, $products ) ) {
						$quantities[ $prod_id ] = $qty;
					}
				}
			}
			update_post_meta( $post_id, '_sob_bundle_product_quantities', $quantities );
		} else {
			delete_post_meta( $post_id, '_sob_bundle_products' );
			delete_post_meta( $post_id, '_sob_bundle_product_quantities' );
		}

		// Save price.
		if ( isset( $_POST['sob_bundle_price'] ) ) {
			$price = sanitize_text_field( $_POST['sob_bundle_price'] );
			if ( '' !== $price ) {
				$price = floatval( $price );
				update_post_meta( $post_id, '_sob_bundle_price', $price );
			} else {
				delete_post_meta( $post_id, '_sob_bundle_price' );
			}
		}

		// Save priority.
		if ( isset( $_POST['sob_bundle_priority'] ) ) {
			$priority = absint( $_POST['sob_bundle_priority'] );
			update_post_meta( $post_id, '_sob_bundle_priority', $priority );
		}
	}

	/**
	 * Customize post columns for Bundle CPT admin table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function set_bundle_columns( array $columns ): array {
		$new_columns = [];
		$new_columns['cb'] = $columns['cb'];
		$new_columns['image'] = __( 'Image', 'smart-order-builder' );
		$new_columns['title'] = $columns['title'];
		$new_columns['products'] = __( 'Included Products', 'smart-order-builder' );
		$new_columns['bundle_price'] = __( 'Bundle Price', 'smart-order-builder' );
		$new_columns['priority'] = __( 'Priority', 'smart-order-builder' );
		$new_columns['date'] = $columns['date'];
		return $new_columns;
	}

	/**
	 * Render post column contents.
	 *
	 * @param string $column Column name.
	 * @param int $post_id Post ID.
	 */
	public function render_bundle_columns( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'image':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, [ 50, 50 ] );
				} else {
					echo '<span class="dashicons dashicons-image-rotate" style="font-size: 35px; width: 35px; height: 35px; color: #ccc;"></span>';
				}
				break;

			case 'products':
				$products = get_post_meta( $post_id, '_sob_bundle_products', true );
				if ( is_array( $products ) && ! empty( $products ) ) {
					$names = [];
					foreach ( $products as $prod_id ) {
						$product = wc_get_product( $prod_id );
						if ( $product ) {
							$names[] = esc_html( $product->get_name() );
						}
					}
					echo implode( ', ', $names );
				} else {
					echo '<em>' . esc_html__( 'No products selected', 'smart-order-builder' ) . '</em>';
				}
				break;

			case 'bundle_price':
				$price = get_post_meta( $post_id, '_sob_bundle_price', true );
				if ( '' !== $price ) {
					echo esc_html( wc_price( $price ) );
				} else {
					echo esc_html__( 'Original price', 'smart-order-builder' );
				}
				break;

			case 'priority':
				$priority = get_post_meta( $post_id, '_sob_bundle_priority', true );
				echo esc_html( $priority !== '' ? $priority : '0' );
				break;
		}
	}

	/**
	 * Retrieve active bundles parsed for frontend use.
	 * Supports manual CPT, WooCommerce grouped products, and WC product bundles plugin.
	 *
	 * @param string $source Source type: 'manual', 'grouped', or 'wc_bundles'.
	 * @param int $limit Max bundles to fetch.
	 * @return array
	 */
	public function get_active_bundles( string $source = 'manual', int $limit = 4 ): array {
		$bundles = [];

		if ( 'grouped' === $source ) {
			$args = [
				'post_type'      => 'product',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'tax_query'      => [
					[
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => 'grouped',
					],
				],
			];

			$query = new WP_Query( $args );
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post ) {
					$product = wc_get_product( $post->ID );
					if ( ! $product ) {
						continue;
					}

					$children_ids = $product->get_children();
					if ( empty( $children_ids ) ) {
						continue;
					}

					// Calculate pricing.
					$original_price = 0;
					$products_details = [];
					foreach ( $children_ids as $child_id ) {
						$child = wc_get_product( $child_id );
						if ( $child && $child->is_purchasable() && $child->is_in_stock() ) {
							$child_price = floatval( $child->get_price() );
							$original_price += $child_price;
							$products_details[] = [
								'id'    => $child_id,
								'name'  => $child->get_name(),
								'price' => $child_price,
								'qty'   => 1,
							];
						}
					}

					// WooCommerce native grouped products don't have a discount by default,
					// but let's offer a custom filter or assume grouped price = sum.
					// We'll calculate a default 5% discount for grouped products as bundle upgrades to keep it appealing.
					$bundle_price = $original_price * 0.95;
					$savings = $original_price - $bundle_price;
					$savings_pct = $original_price > 0 ? round( ( $savings / $original_price ) * 100 ) : 0;

					$image_id  = $product->get_image_id();
					$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : wc_placeholder_img_src();

					$bundles[] = [
						'id'             => $post->ID,
						'name'           => $product->get_name(),
						'image'          => $image_url,
						'products'       => wp_list_pluck( $products_details, 'id' ),
						'product_details'=> $products_details,
						'original_price' => $original_price,
						'bundle_price'   => $bundle_price,
						'savings'        => $savings,
						'savings_percent'=> $savings_pct,
						'priority'       => 0,
						'source'         => 'grouped',
					];
				}
			}
			wp_reset_postdata();

		} elseif ( 'wc_bundles' === $source && class_exists( 'WC_Product_Bundle' ) ) {
			$args = [
				'post_type'      => 'product',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'tax_query'      => [
					[
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => 'bundle',
					],
				],
			];

			$query = new WP_Query( $args );
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post ) {
					$bundle = wc_get_product( $post->ID );
					if ( ! $bundle || ! method_exists( $bundle, 'get_bundled_items' ) ) {
						continue;
					}

					$bundled_items = $bundle->get_bundled_items();
					$product_ids = [];
					$products_details = [];
					$original_price = 0;

					foreach ( $bundled_items as $item ) {
						$child_id = $item->get_product_id();
						$child = wc_get_product( $child_id );
						if ( $child ) {
							$child_price = floatval( $child->get_price() );
							$item_qty    = method_exists( $item, 'get_quantity' ) ? max( 1, intval( $item->get_quantity() ) ) : 1;
							$original_price += ( $child_price * $item_qty );
							$product_ids[] = $child_id;
							$products_details[] = [
								'id'    => $child_id,
								'name'  => $child->get_name(),
								'price' => $child_price,
								'qty'   => $item_qty,
							];
						}
					}

					$bundle_price = floatval( $bundle->get_price() );
					$savings = $original_price - $bundle_price;
					$savings_pct = $original_price > 0 ? round( ( $savings / $original_price ) * 100 ) : 0;

					$image_id  = $bundle->get_image_id();
					$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : wc_placeholder_img_src();

					$bundles[] = [
						'id'             => $post->ID,
						'name'           => $bundle->get_name(),
						'image'          => $image_url,
						'products'       => $product_ids,
						'product_details'=> $products_details,
						'original_price' => $original_price,
						'bundle_price'   => $bundle_price,
						'savings'        => $savings,
						'savings_percent'=> $savings_pct,
						'priority'       => 0,
						'source'         => 'wc_bundles',
					];
				}
			}
			wp_reset_postdata();

		} else {
			// Default 'manual' source using CPT sob_bundle
			$args = [
				'post_type'      => 'sob_bundle',
				'posts_per_page' => $limit,
				'post_status'    => 'publish',
				'meta_key'       => '_sob_bundle_priority',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			];

			$query = new WP_Query( $args );
			if ( $query->have_posts() ) {
				foreach ( $query->posts as $post ) {
					$selected_ids = get_post_meta( $post->ID, '_sob_bundle_products', true );
					if ( ! is_array( $selected_ids ) || empty( $selected_ids ) ) {
						continue;
					}

					$qtys = get_post_meta( $post->ID, '_sob_bundle_product_quantities', true );
					if ( ! is_array( $qtys ) ) {
						$qtys = [];
					}

					$original_price = 0;
					$product_ids = [];
					$products_details = [];
					foreach ( $selected_ids as $prod_id ) {
						$product = wc_get_product( $prod_id );
						if ( $product && $product->is_purchasable() ) {
							$price = floatval( $product->get_price() );
							$qty = isset( $qtys[ $prod_id ] ) ? max( 1, absint( $qtys[ $prod_id ] ) ) : 1;
							$original_price += ( $price * $qty );
							$product_ids[] = $prod_id;
							$products_details[] = [
								'id'    => $prod_id,
								'name'  => $product->get_name(),
								'price' => $price,
								'qty'   => $qty,
							];
						}
					}

					if ( empty( $product_ids ) ) {
						continue;
					}

					$bundle_price = get_post_meta( $post->ID, '_sob_bundle_price', true );
					if ( '' === $bundle_price ) {
						$bundle_price = $original_price;
					} else {
						$bundle_price = floatval( $bundle_price );
					}

					$savings = $original_price - $bundle_price;
					$savings_pct = $original_price > 0 ? round( ( $savings / $original_price ) * 100 ) : 0;
					$priority = get_post_meta( $post->ID, '_sob_bundle_priority', true );

					$image_url = get_the_post_thumbnail_url( $post->ID, 'medium' );
					if ( ! $image_url ) {
						// Fallback: use first product image
						$first_prod = wc_get_product( $product_ids[0] );
						if ( $first_prod ) {
							$image_id = $first_prod->get_image_id();
							$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : wc_placeholder_img_src();
						} else {
							$image_url = wc_placeholder_img_src();
						}
					}

					$bundles[] = [
						'id'             => $post->ID,
						'name'           => $post->post_title,
						'image'          => $image_url,
						'products'       => $product_ids,
						'product_details'=> $products_details,
						'original_price' => $original_price,
						'bundle_price'   => $bundle_price,
						'savings'        => $savings,
						'savings_percent'=> $savings_pct,
						'priority'       => (int) $priority,
						'source'         => 'manual',
					];
				}
			}
			wp_reset_postdata();
		}

		return $bundles;
	}

	/**
	 * Retrieve children/bundled items and quantities for a WooCommerce Grouped or Bundle product.
	 * Used to display bundle contents in product row descriptions.
	 *
	 * @param int $product_id The product ID.
	 * @return array Array of arrays containing 'name' and 'qty'.
	 */
	public static function get_product_bundle_contents( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}

		$contents = [];
		if ( $product->is_type( 'grouped' ) ) {
			$children_ids = $product->get_children();
			foreach ( $children_ids as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$contents[] = [
						'name' => $child->get_name(),
						'qty'  => 1,
					];
				}
			}
		} elseif ( $product->is_type( 'bundle' ) && method_exists( $product, 'get_bundled_items' ) ) {
			$bundled_items = $product->get_bundled_items();
			foreach ( $bundled_items as $item ) {
				$child_id = $item->get_product_id();
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$qty = method_exists( $item, 'get_quantity' ) ? $item->get_quantity() : 1;
					$contents[] = [
						'name' => $child->get_name(),
						'qty'  => $qty,
					];
				}
			}
		}
		return $contents;
	}
}
