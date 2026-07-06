<?php
/**
 * Smart Order Builder for WooCommerce Uninstall
 *
 * Runs when the plugin is deleted from the WordPress admin panel.
 * Completely erases all settings, CPT entries, and database traces.
 *
 * @package SmartOrderBuilder
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if file is accessed directly.
}

// 1. Delete stored settings options.
delete_option( 'sob_settings' );

// 2. Query and force-delete all 'sob_bundle' custom post type entries.
$bundles = get_posts( [
	'post_type'   => 'sob_bundle',
	'numberposts' => -1,
	'post_status' => 'any',
] );

if ( ! empty( $bundles ) ) {
	foreach ( $bundles as $post ) {
		// Force delete bypassing trash to ensure metadata cleanup is immediate.
		wp_delete_post( $post->ID, true );
	}
}

// 3. Clear transients if any exist.
delete_transient( 'sob_active_bundles_manual' );
delete_transient( 'sob_active_bundles_grouped' );
delete_transient( 'sob_active_bundles_wc_bundles' );
