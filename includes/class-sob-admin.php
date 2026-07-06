<?php
/**
 * Class SOB_Admin
 *
 * Handles the admin settings interface and save logic for Smart Order Builder.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Admin {

	/**
	 * Options key in WP DB.
	 *
	 * @var string
	 */
	private string $option_name = 'sob_settings';

	/**
	 * Constructor. Sets up admin menus, settings, and options initialization.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_settings_menu' ], 50 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Add Settings Submenu under WooCommerce menu.
	 */
	public function add_settings_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Smart Order Builder Settings', 'smart-order-builder' ),
			__( 'Smart Order Builder', 'smart-order-builder' ),
			'manage_woocommerce',
			'sob-settings',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register settings option and section fields.
	 */
	public function register_settings(): void {
		register_setting(
			'sob_settings_group',
			$this->option_name,
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_default_settings(),
			]
		);
	}

	/**
	 * Default settings options.
	 *
	 * @return array
	 */
	public function get_default_settings(): array {
		return [
			'enabled'                    => 'yes',
			'products_per_page'          => 12,
			'enable_search'              => 'yes',
			'enable_sticky_sidebar'      => 'yes',
			'enable_recommended_bundles' => 'yes',
			'enable_upgrade_banner'      => 'yes',
			'bundles_count'              => 4,
			'bundle_source'              => 'manual',
			'enable_complete_order'      => 'yes',
			'complete_order_count'       => 4,
		];
	}

	/**
	 * Fetch stored settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings(): array {
		$defaults = ( new self() )->get_default_settings();
		$stored   = get_option( 'sob_settings', [] );
		return wp_parse_args( $stored, $defaults );
	}

	/**
	 * Sanitization callback for settings save.
	 *
	 * @param array $input Input values.
	 * @return array
	 */
	public function sanitize_settings( array $input ): array {
		$stored   = get_option( $this->option_name, [] );
		$defaults = $this->get_default_settings();
		$current  = wp_parse_args( $stored, $defaults );

		$tab = isset( $_POST['sob_settings_tab'] ) ? sanitize_key( $_POST['sob_settings_tab'] ) : 'general';
		$sanitized = $current;

		if ( 'general' === $tab ) {
			$sanitized['enabled'] = isset( $input['enabled'] ) && 'yes' === $input['enabled'] ? 'yes' : 'no';
			
			$sanitized['products_per_page'] = isset( $input['products_per_page'] ) ? absint( $input['products_per_page'] ) : 12;
			if ( $sanitized['products_per_page'] <= 0 ) {
				$sanitized['products_per_page'] = 12;
			}

			$sanitized['enable_search']         = isset( $input['enable_search'] ) && 'yes' === $input['enable_search'] ? 'yes' : 'no';
			$sanitized['enable_sticky_sidebar'] = isset( $input['enable_sticky_sidebar'] ) && 'yes' === $input['enable_sticky_sidebar'] ? 'yes' : 'no';
		} elseif ( 'bundles' === $tab ) {
			$sanitized['enable_recommended_bundles'] = isset( $input['enable_recommended_bundles'] ) && 'yes' === $input['enable_recommended_bundles'] ? 'yes' : 'no';
			$sanitized['enable_upgrade_banner']      = isset( $input['enable_upgrade_banner'] ) && 'yes' === $input['enable_upgrade_banner'] ? 'yes' : 'no';

			$sanitized['bundles_count'] = isset( $input['bundles_count'] ) ? absint( $input['bundles_count'] ) : 4;
			if ( $sanitized['bundles_count'] <= 0 ) {
				$sanitized['bundles_count'] = 4;
			}

			$sanitized['bundle_source'] = isset( $input['bundle_source'] ) && in_array( $input['bundle_source'], [ 'manual', 'grouped', 'wc_bundles' ], true ) ? $input['bundle_source'] : 'manual';
		} elseif ( 'crosssell' === $tab ) {
			$sanitized['enable_complete_order'] = isset( $input['enable_complete_order'] ) && 'yes' === $input['enable_complete_order'] ? 'yes' : 'no';

			$sanitized['complete_order_count'] = isset( $input['complete_order_count'] ) ? absint( $input['complete_order_count'] ) : 4;
			if ( $sanitized['complete_order_count'] <= 0 ) {
				$sanitized['complete_order_count'] = 4;
			}
		}

		return $sanitized;
	}

	/**
	 * Render Settings Page output.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'smart-order-builder' ) );
		}

		$settings = self::get_settings();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

		?>
		<div class="wrap woocommerce">
			<h1><?php esc_html_e( 'Smart Order Builder Settings', 'smart-order-builder' ); ?></h1>

			<div class="notice notice-info sob-admin-shortcode-notice" style="margin: 20px 0 10px 0; padding: 15px; border-left-color: #2563eb; border-radius: 4px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border-left-width: 4px;">
				<p style="margin: 0; font-size: 14px; color: #1f2937;">
					<strong><?php esc_html_e( 'Shortcode Usage:', 'smart-order-builder' ); ?></strong>
					<?php esc_html_e( 'Copy and paste the following shortcode onto any page or section on your website to display the Smart Order Builder:', 'smart-order-builder' ); ?>
					<code style="background: #f3f4f6; color: #ef4444; padding: 4px 8px; border-radius: 4px; font-size: 14px; font-weight: bold; margin-left: 5px; cursor: pointer;" onclick="navigator.clipboard.writeText(this.innerText); alert('Shortcode copied to clipboard!');" title="Click to copy">[smart_order_builder]</code>
				</p>
			</div>
			
			<h2 class="nav-tab-wrapper">
				<a href="?page=sob-settings&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General Settings', 'smart-order-builder' ); ?>
				</a>
				<a href="?page=sob-settings&tab=bundles" class="nav-tab <?php echo 'bundles' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Bundle Settings', 'smart-order-builder' ); ?>
				</a>
				<a href="?page=sob-settings&tab=crosssell" class="nav-tab <?php echo 'crosssell' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Complete Your Order', 'smart-order-builder' ); ?>
				</a>
			</h2>

			<form method="post" action="options.php" class="sob-settings-form">
				<?php
				settings_fields( 'sob_settings_group' );
				?>
				<input type="hidden" name="sob_settings_tab" value="<?php echo esc_attr( $active_tab ); ?>" />

				<div class="sob-settings-tab-content" style="margin-top: 20px;">
					<?php if ( 'general' === $active_tab ) : ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="sob_enabled"><?php esc_html_e( 'Enable Plugin', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enabled]" id="sob_enabled" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Enable the shortcode output and order builder functionality.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_products_per_page"><?php esc_html_e( 'Products Per Page', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="number" name="sob_settings[products_per_page]" id="sob_products_per_page" value="<?php echo esc_attr( $settings['products_per_page'] ); ?>" min="1" step="1" />
									<span class="description"><?php esc_html_e( 'Number of WooCommerce products to display in the ordering table.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_enable_search"><?php esc_html_e( 'Enable Search & Filter', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enable_search]" id="sob_enable_search" value="yes" <?php checked( $settings['enable_search'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Show search input to filter products by name, SKU, and category.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_enable_sticky_sidebar"><?php esc_html_e( 'Enable Sticky Order Summary', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enable_sticky_sidebar]" id="sob_enable_sticky_sidebar" value="yes" <?php checked( $settings['enable_sticky_sidebar'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Keep the right-hand checkout panel fixed as the user scrolls.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
						</table>
					<?php elseif ( 'bundles' === $active_tab ) : ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="sob_enable_recommended_bundles"><?php esc_html_e( 'Enable Recommended Bundles', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enable_recommended_bundles]" id="sob_enable_recommended_bundles" value="yes" <?php checked( $settings['enable_recommended_bundles'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Display bundle recommendation grid below the products table.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_enable_upgrade_banner"><?php esc_html_e( 'Enable Smart Bundle Upgrade Banner', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enable_upgrade_banner]" id="sob_enable_upgrade_banner" value="yes" <?php checked( $settings['enable_upgrade_banner'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Alert users when they are close to forming a complete bundle package in their cart.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_bundles_count"><?php esc_html_e( 'Number of Bundles to Show', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="number" name="sob_settings[bundles_count]" id="sob_bundles_count" value="<?php echo esc_attr( $settings['bundles_count'] ); ?>" min="1" step="1" />
									<span class="description"><?php esc_html_e( 'Maximum number of bundle recommendations to show in the 2x2 grid.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_bundle_source"><?php esc_html_e( 'Bundle Source', 'smart-order-builder' ); ?></label></th>
								<td>
									<select name="sob_settings[bundle_source]" id="sob_bundle_source">
										<option value="manual" <?php selected( $settings['bundle_source'], 'manual' ); ?>><?php esc_html_e( 'Manual Bundles (via Custom Post Type)', 'smart-order-builder' ); ?></option>
										<option value="grouped" <?php selected( $settings['bundle_source'], 'grouped' ); ?>><?php esc_html_e( 'WooCommerce Grouped Products', 'smart-order-builder' ); ?></option>
										<option value="wc_bundles" <?php selected( $settings['bundle_source'], 'wc_bundles' ); ?>><?php esc_html_e( 'WooCommerce Product Bundles Plugin', 'smart-order-builder' ); ?></option>
									</select>
									<span class="description">
										<?php esc_html_e( 'Choose where bundle calculations and cards are loaded from.', 'smart-order-builder' ); ?>
										<br/><strong><?php esc_html_e( 'Note:', 'smart-order-builder' ); ?></strong> <?php esc_html_e( 'Manual bundles can be created in the "Smart Bundles" section under WooCommerce.', 'smart-order-builder' ); ?>
									</span>
								</td>
							</tr>
						</table>
					<?php elseif ( 'crosssell' === $active_tab ) : ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="sob_enable_complete_order"><?php esc_html_e( 'Enable "Complete Your Order"', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="checkbox" name="sob_settings[enable_complete_order]" id="sob_enable_complete_order" value="yes" <?php checked( $settings['enable_complete_order'], 'yes' ); ?> />
									<span class="description"><?php esc_html_e( 'Show cross-sell or upsell item suggestions in the right column.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sob_complete_order_count"><?php esc_html_e( 'Number of Suggested Products', 'smart-order-builder' ); ?></label></th>
								<td>
									<input type="number" name="sob_settings[complete_order_count]" id="sob_complete_order_count" value="<?php echo esc_attr( $settings['complete_order_count'] ); ?>" min="1" step="1" />
									<span class="description"><?php esc_html_e( 'Number of items to show in checkout suggestions.', 'smart-order-builder' ); ?></span>
								</td>
							</tr>
						</table>
					<?php endif; ?>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
