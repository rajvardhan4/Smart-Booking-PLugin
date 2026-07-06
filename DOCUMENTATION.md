# Smart Order Builder for WooCommerce — Comprehensive Documentation

Smart Order Builder is a premium, high-performance, and secure WordPress/WooCommerce plugin that replaces the standard WooCommerce product catalog with a streamlined two-column order builder interface. It is designed to accelerate bulk ordering, upselling, and bundle sales.

---

## 🌟 Core Features & Customizations Built

### 1. Two-Column Ordering Layout
- **Left Column (70% width)**:
  - **Category Filter & Search Bar**: Real-time product search by name, SKU, or keyword with AJAX-based filtering.
  - **Customizable Product Table**: Shows product thumbnail image, name, SKU, short description, price, quantity selectors (`-` and `+`), and a Quick View eye icon. The table column heading `Image` has been renamed to `Item` and styled with white text.
  - **Smart Bundle Upgrade Banner**: Dynamically scans the customer's cart. If the customer has added exactly $N-1$ items of a bundle, it shows a premium banner suggesting they add the missing item to upgrade and save money.
  - **Recommended Bundles Grid**: Shows active bundle deals in a clean 2x2 grid showing the items included, regular price, discounted bundle price, and savings.
- **Right Column (30% width) - Sticky Sidebar**:
  - **Order Summary**: Real-time calculation of total items, subtotal, bundle discounts, tax, and grand total.
  - **Selected Items List (Interactive Cart)**: Lists the added products in the cart in place of cross-sells. Each item features its thumbnail image, name, line subtotal price, and individual quantity adjusters (`-` and `+`). Decreasing any item to `0` removes it from both the sidebar and the main products table in real-time.
  - **Proceed to Checkout CTA**: Standard WooCommerce checkout redirection.

### 2. Product Detail off-canvas Drawer
- Triggered by clicking the Quick View eye icon in the products table.
- Displays product name, SKU, price, stock status, full description, product image gallery with an active thumbnail switcher, variation selection support, and quantity input selector.
- Prevents background page scrolling when open and stays fixed in front of all headers and footers.

### 3. Lightbox Modal for Bundles
- Shows detailed information about recommended bundle items when "View Details" is clicked.
- Features modern CSS Grid centering, backdrop blur, scrollable listing for tall elements, and locks the body scroll to prevent background scroll interference. It has an elevated `z-index` so it displays above sticky themes, menus, footers, and headers.

### 4. Custom Smart Bundles CPT (Backend Manager)
- Registered as **Smart Bundles** (`sob_bundle`) directly under the main **WooCommerce** admin menu.
- Allows admins to:
  - Search and select WooCommerce products to include in a package.
  - Specify a custom **Bundle Discounted Price**.
  - Configure **Bundle Priority** for grid ordering.
  - Set a featured image to represent the bundle package.

### 5. Automated Bundle Price Calculations & Discounts
- Hooks into WooCommerce's `woocommerce_cart_calculate_fees` hook.
- Loops through active bundles and applies bundle discounts as a negative fee (e.g. `Bundle Discount: Ultimate Beach Pack`).
- Uses a greedy matching algorithm: if the cart contains multiple instances of bundle products, it awards discounts for multiple full bundle sets.

### 6. Security and HPOS Compatibility
- Declares WooCommerce High-Performance Order Storage (HPOS) compatibility.
- Implements security verification (AJAX nonces) on all front-end endpoints to prevent CSRF attacks.

---

## 🛠️ Step-by-Step Setup & Installation

Follow these steps to upload, activate, and use the plugin:

### Step 1: Uploading the Plugin ZIP
1. Locate the compressed plugin file: [smart-order-builder.zip](file:///C:/Rishis%20Antigravity/smart-order-builder.zip).
2. Log in to your live WordPress admin dashboard (e.g., `http://yourdomain.com/wp-admin`).
3. Navigate to **Plugins > Add New**.
4. Click the **Upload Plugin** button at the top.
5. Click **Choose File**, select `smart-order-builder.zip`, and click **Install Now**.
6. Once uploaded successfully, click **Activate Plugin**.

### Step 2: Displaying the Builder on the Frontend
1. In your WordPress admin dashboard, navigate to **Pages > Add New**.
2. Title the page (e.g., "Customize A Package" or "Shop").
3. Add a shortcode block and enter:
   ```text
   [smart_order_builder]
   ```
4. Click **Publish** or **Update**.
5. Visit the page on your frontend to view the active order builder.

### Step 3: Creating and Displaying Smart Bundles
1. Navigate to **WooCommerce > Smart Bundles** in your admin panel.
2. Click **Add New**.
3. Enter a title for the bundle (e.g., "Ultimate Beach Pack").
4. Under the **Bundle Details** box:
   - Search and select the products that make up the package.
   - Enter the **Bundle Discounted Price** (e.g., if regular items cost $225, set it to $180).
   - Enter a **Priority** number (higher numbers display first).
5. Set a **Featured Image** for the bundle card display.
6. Click **Publish**.

### Step 4: Configuring Global Settings
- Go to **WooCommerce > Smart Order Builder** to adjust general preferences, change the number of products per page, customize bundle calculations, or toggle the sticky sidebar sections.

---

## 📁 Code Architecture and File Map

- [smart-order-builder.php](file:///C:/Rishis%20Antigravity/smart-order-builder/smart-order-builder.php): Entry file, declares autoloader mapping class prefixes `SOB_` to includes.
- [includes/class-sob-ajax.php](file:///C:/Rishis%20Antigravity/smart-order-builder/includes/class-sob-ajax.php): Handles secure AJAX requests for product filters, quantity updates, drawer info, and cart summaries.
- [includes/class-sob-cart.php](file:///C:/Rishis%20Antigravity/smart-order-builder/includes/class-sob-cart.php): Implements the negative fee discount engine and upgrade banner checks.
- [includes/class-sob-bundles.php](file:///C:/Rishis%20Antigravity/smart-order-builder/includes/class-sob-bundles.php): Custom Post Type registration and metabox saving.
- [includes/class-sob-shortcode.php](file:///C:/Rishis%20Antigravity/smart-order-builder/includes/class-sob-shortcode.php): Defines the `[smart_order_builder]` shortcode structure.
- [templates/order-builder.php](file:///C:/Rishis%20Antigravity/smart-order-builder/templates/order-builder.php): Layout shell showing left column table and right column cart summaries.
- [templates/cart-item.php](file:///C:/Rishis%20Antigravity/smart-order-builder/templates/cart-item.php): Template showing sidebar product thumbnails and quantity selectors.
- [assets/js/frontend.js](file:///C:/Rishis%20Antigravity/smart-order-builder/assets/js/frontend.js): Listens for clicks, handles AJAX sync, and manages scroll-lock class toggles.
- [assets/css/frontend.css](file:///C:/Rishis%20Antigravity/smart-order-builder/assets/css/frontend.css): Modern CSS styling grid, colors, overlays, and elevated z-index rules.
