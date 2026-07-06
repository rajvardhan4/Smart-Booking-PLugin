<<<<<<< HEAD
=== WooBookify ===
Contributors: digitalsuncity
Requires at least: 6.0
Requires PHP: 8.0
Requires Plugins: woocommerce
Stable tag: 1.1.6

WooBookify converts WooCommerce products into single-date, single-slot bookable services.

== Features ==
- Product-level booking settings tab
- Single-date calendar picker
- Product-specific single-label time slots
- Slot capacity and day-wise slot availability
- Booking validation
- Deposit or full payment option
- Booking summary on product, cart, checkout, order, and emails
- Custom booking button text
- Dedicated Bookings admin dashboard
- CSV export
- Global 12-hour or 24-hour time format
- HPOS compatibility declaration

== Changelog ==

= 1.0.3 =
- Add one-click half-hour time slot presets and a range generator to the product Booking Slot Manager.

= 1.0.2 =
- Keep Eastern time as the default timezone, remove timezone text from slot buttons, and harden cart payment/timezone metadata.

= 1.0.1 =
- Hide and reject time slots that have already passed for today's booking date in Eastern business time.
=======
=== Smart Order Builder for WooCommerce ===
Contributors: Antigravity AI
Tags: woocommerce, cart, ajax, bundle, quick order, wholesale, b2b, order builder
Requires at least: 5.8
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace the normal slow WooCommerce shopping experience with a fast two-column order builder page where customers can quickly add products, view bundles, see recommendations, and go to checkout.

== Description ==

Smart Order Builder for WooCommerce is a premium-grade wholesale and bulk ordering portal solution designed to streamline B2B and wholesale purchasing. It renders a clean two-column grid on any page via a simple shortcode:

Left Column: Product table with pagination, AJAX quantity adjustments, off-canvas quick view drawers, smart upgrade banners, and recommended bundle grids.
Right Column: Sticky checkout totals summary and related item suggestion panels.

All quantities sync instantly with the WooCommerce cart via secure AJAX.

== Installation ==

1. Upload the entire `smart-order-builder` folder to the `/wp-content/plugins/` directory, or upload the zipped plugin file directly from the WordPress plugin installer.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Ensure WooCommerce is active.
4. Navigate to `WooCommerce > Smart Order Builder` to configure settings.
5. Create a new page and add the shortcode `[smart_order_builder]` to display the ordering panel.

== Shortcode Usage ==

Use the following shortcode on any post, page, or widget block:

`[smart_order_builder]`

This shortcode does not require arguments as it dynamically reads settings defined in the WooCommerce administration page.

== How to Create a Bundle ==

1. In the WordPress Admin Dashboard, navigate to the **WooCommerce** menu.
2. Click on **Bundles** (CPT).
3. Click **Add New**.
4. Set the title of the bundle.
5. Upload a featured image. This serves as the display thumbnail in the frontend grids.
6. In the **Bundle Details** metabox:
   - Search and select the WooCommerce products that make up this package.
   - Enter a **Discounted Price** for the entire set (leave blank to sum up regular prices with no additional discount).
   - Define a priority order (larger numbers display first).
7. Publish the bundle. It will immediately begin showing up in recommendations if the category matches or fallback recommendations are enqueued.

== How to Test Cart AJAX ==

1. Open your browser console (F12) and switch to the **Network** tab.
2. Load the page containing `[smart_order_builder]`.
3. Increase or decrease product quantities in the table. You will see POST requests targeting `/wp-admin/admin-ajax.php` with the action `sob_update_quantity`.
4. Observe the JSON response. It returns the updated grand totals, savings, cross-sell HTML, and a map of current cart quantities to ensure all inputs are dynamically synchronized.

== Limitations ==

- Variable Products: The quick view drawer fully supports selecting variation attributes and adjusting quantities. However, adding variations directly from the main product table requires utilizing the Quick View eye icon to specify variant options first.
- Third-Party Plugins: Complex product add-ons, file uploads, or customized dynamic pricing from external plugins are not natively mapped in the order builder table but will fall back to their default WooCommerce handlers during checkout.
>>>>>>> 18d880aa7f0ae12d83ae6325acf755817dc221a7
