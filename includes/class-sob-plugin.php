<?php
/**
 * Class SOB_Plugin
 *
 * Core coordinator class for the Smart Order Builder plugin.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class SOB_Plugin {

	/**
	 * Singleton instance of the class.
	 *
	 * @var SOB_Plugin|null
	 */
	private static ?SOB_Plugin $instance = null;

	/**
	 * Sub-module instances.
	 *
	 * @var array
	 */
	private array $modules = [];

	/**
	 * Get the singleton instance.
	 *
	 * @return SOB_Plugin
	 */
	public static function get_instance(): SOB_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers constants, hooks, and initializes sub-modules.
	 */
	private function __construct() {
		$this->define_constants();
		$this->init_modules();

		// Asset enqueuing hooks.
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'register_admin_assets' ] );
	}

	/**
	 * Enqueue frontend assets globally.
	 */
	public function enqueue_frontend_assets(): void {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'sob-frontend' );
		wp_enqueue_script( 'sob-frontend' );
	}

	/**
	 * Define plugin-wide constants.
	 */
	private function define_constants(): void {
		if ( ! defined( 'SOB_VERSION' ) ) {
			define( 'SOB_VERSION', time() );
		}
		if ( ! defined( 'SOB_PATH' ) ) {
			define( 'SOB_PATH', plugin_dir_path( dirname( __FILE__ ) ) );
		}
		if ( ! defined( 'SOB_URL' ) ) {
			define( 'SOB_URL', plugin_dir_url( dirname( __FILE__ ) ) );
		}
	}

	/**
	 * Initialize plugin sub-modules.
	 */
	private function init_modules(): void {
		// Initialize the database and bundle models first.
		$this->modules['bundles']   = new SOB_Bundles();
		$this->modules['cart']      = new SOB_Cart();
		$this->modules['admin']     = new SOB_Admin();
		$this->modules['ajax']      = new SOB_Ajax();
		$this->modules['shortcode'] = new SOB_Shortcode();
	}

	/**
	 * Register frontend assets (loaded conditionally via shortcode).
	 */
	public function register_frontend_assets(): void {
		wp_register_style(
			'sob-frontend',
			SOB_URL . 'assets/css/frontend.css',
			[],
			SOB_VERSION
		);

		wp_register_script(
			'sob-frontend',
			SOB_URL . 'assets/js/frontend.js',
			[ 'jquery' ],
			SOB_VERSION,
			true
		);

		// Localize the script with configurations, nonces, and AJAX URL.
		wp_localize_script(
			'sob-frontend',
			'sob_params',
			[
				'ajax_url'   => admin_url( 'admin-ajax.php' ),
				'ajax_nonce' => wp_create_nonce( 'sob_ajax_nonce' ),
				'currency_symbol' => get_woocommerce_currency_symbol(),
				'checkout_url'    => wc_get_checkout_url(),
				'i18n' => [
					'added'          => __( 'Added to cart!', 'smart-order-builder' ),
					'add_failed'     => __( 'Failed to add item.', 'smart-order-builder' ),
					'update_failed'  => __( 'Failed to update quantity.', 'smart-order-builder' ),
					'remove_failed'  => __( 'Failed to remove item.', 'smart-order-builder' ),
					'bundle_added'   => __( 'Bundle added to cart!', 'smart-order-builder' ),
					'cart_updated'   => __( 'Cart updated.', 'smart-order-builder' ),
					'error_occurred' => __( 'An error occurred. Please try again.', 'smart-order-builder' ),
				],
			]
		);
	}

	/**
	 * Register and enqueue admin assets.
	 *
	 * @param string $hook_suffix The current admin screen page suffix.
	 */
	public function register_admin_assets( string $hook_suffix ): void {
		// Load select2 if not already enqueued (common in WooCommerce).
		wp_register_style( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', [], '4.0.13' );
		wp_register_script( 'select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js', [ 'jquery' ], '4.0.13', true );

		// Register plugin admin assets.
		wp_register_style(
			'sob-admin',
			SOB_URL . 'assets/css/admin.css',
			[],
			SOB_VERSION
		);

		wp_register_script(
			'sob-admin',
			SOB_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			SOB_VERSION,
			true
		);

		// Enqueue admin assets on our settings page, post types edit/post screens, and WooCommerce order screens.
		$screen = get_current_screen();
		$is_sob_page = ( 
			'woocommerce_page_sob-settings' === $hook_suffix || 
			( $screen && 'sob_bundle' === $screen->post_type ) ||
			( $screen && ( 'shop_order' === $screen->post_type || 'woocommerce_page_wc-orders' === $screen->id || 'woocommerce_page_wc-orders-edit' === $screen->id || 'shop_order' === $screen->id ) )
		);

		if ( $is_sob_page ) {
			wp_enqueue_style( 'select2' );
			wp_enqueue_script( 'select2' );
			wp_enqueue_style( 'sob-admin' );
			wp_enqueue_script( 'sob-admin' );

			wp_localize_script(
				'sob-admin',
				'sob_admin_params',
				[
					'ajax_url'   => admin_url( 'admin-ajax.php' ),
					'ajax_nonce' => wp_create_nonce( 'sob_admin_nonce' ),
					'search_nonce' => wp_create_nonce( 'sob_admin_search_nonce' ),
				]
			);
		}
	}

	/**
	 * Get reference to initialized modules.
	 *
	 * @param string $key The module key.
	 * @return mixed|null
	 */
	public function get_module( string $key ) {
		return $this->modules[ $key ] ?? null;
	}
}
