<?php
/**
 * Plugin Name: Smart Order Builder for WooCommerce
 * Description: Replace the normal slow WooCommerce shopping experience with a fast two-column order builder where customers can quickly add products, view bundles, see recommendations, and go to checkout.
 * Version: 1.0.0
 * Author: Antigravity AI
 * Text Domain: smart-order-builder
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * WC requires at least: 5.0
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Declare WooCommerce HPOS compatibility.
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Autoloader for Smart Order Builder classes.
 * Maps SOB_ Class Names to class-sob-filename.php.
 */
spl_autoload_register( function( $class ) {
	// Only load classes prefixed with SOB_
	if ( strpos( $class, 'SOB_' ) !== 0 ) {
		return;
	}

	$file_name = 'class-' . str_replace( '_', '-', strtolower( $class ) ) . '.php';
	$file_path = plugin_dir_path( __FILE__ ) . 'includes/' . $file_name;

	if ( file_exists( $file_path ) ) {
		require_once $file_path;
	}
} );

/**
 * Check if WooCommerce is active.
 * Renders an admin notice if WooCommerce is missing.
 */
function sob_check_woocommerce_dependency() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'sob_woocommerce_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Render admin notice when WooCommerce is not active.
 */
function sob_woocommerce_missing_notice() {
	$message = __( 'Smart Order Builder for WooCommerce requires WooCommerce to be installed and active.', 'smart-order-builder' );
	echo '<div class="notice notice-error is-dismissible"><p><strong>' . esc_html( $message ) . '</strong></p></div>';
}

// Initialize the plugin if WooCommerce is active.
add_action( 'plugins_loaded', function() {
	if ( sob_check_woocommerce_dependency() ) {
		// Initialize the main plugin coordinator.
		SOB_Plugin::get_instance();
	}
} );
