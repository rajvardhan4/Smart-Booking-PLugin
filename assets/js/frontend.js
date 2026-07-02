/**
 * Smart Order Builder for WooCommerce
 * Frontend Javascript Interactions
 */

(function( $ ) {
	'use strict';

	// Main application state
	var state = {
		currentPage: 1,
		searchTimer: null,
		xhrSearch: null
	};

	$(document).ready(function() {
		initEventListeners();
		initWooCommerceCardEnhancements();
		initGlobalEventListeners();

		// Move overlays to body to prevent theme stacking context issues (going behind header/footer)
		$('#sob-product-drawer-backdrop').appendTo('body');
		$('#sob-bundle-modal-backdrop').appendTo('body');
	});

	/**
	 * Setup event listeners for the order builder interface.
	 */
	function initEventListeners() {
		var $app = $('#sob-order-builder');
		if ( !$app.length ) {
			return;
		}

		// 1. Search & Filter Inputs
		$app.on('keyup input', '#sob-search-input', function() {
			clearTimeout(state.searchTimer);
			state.searchTimer = setTimeout(function() {
				state.currentPage = 1;
				fetchProducts();
			}, 300);
		});

		$app.on('change', '#sob-category-select', function() {
			state.currentPage = 1;
			fetchProducts();
		});

		// 2. Pagination Clicks
		$app.on('click', '#sob-pag-prev', function() {
			if (state.currentPage > 1) {
				state.currentPage--;
				fetchProducts();
			}
		});

		$app.on('click', '#sob-pag-next', function() {
			var totalPages = parseInt($('#sob-total-pages').text(), 10) || 1;
			if (state.currentPage < totalPages) {
				state.currentPage++;
				fetchProducts();
			}
		});

		// 3. Product Quantity Selectors (Plus/Minus in table)
		$app.on('click', '.sob-qty-minus', function() {
			adjustTableQty($(this), -1);
		});

		$app.on('click', '.sob-qty-plus', function() {
			adjustTableQty($(this), 1);
		});

		$app.on('change', '.sob-qty-input', function() {
			updateTableQtyDirect($(this));
		});

		$app.on('keydown', '.sob-qty-input', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				$(this).blur();
			}
		});

		// 4. Product Quick View Drawer
		$app.on('click', '.sob-quickview-trigger', function() {
			var productId = $(this).closest('.sob-product-row').data('product-id');
			openProductDrawer(productId);
		});

		$('body').on('click', '#sob-product-drawer-backdrop, .sob-drawer-close', function(e) {
			if ( e.target === this || $(this).hasClass('sob-drawer-close') ) {
				closeProductDrawer();
			}
		});

		// 5. Drawer Gallery Switcher
		$('body').on('click', '.sob-gallery-thumb', function() {
			var largeUrl = $(this).data('large-url');
			$('.sob-gallery-thumb').removeClass('active');
			$(this).addClass('active');
			$('#sob-drawer-main-image').attr('src', largeUrl);
		});

		// 6. Drawer Variation Selects
		$('body').on('change', '.sob-variation-select', function() {
			handleVariationChange();
		});

		// 7. Drawer Qty Adjustment & Add to Cart
		$('body').on('click', '.sob-drawer-qty-minus', function() {
			var $input = $('#sob-drawer-qty-input');
			var val = parseInt($input.val(), 10) || 1;
			if (val > 1) {
				$input.val(val - 1);
			}
		});

		$('body').on('click', '.sob-drawer-qty-plus', function() {
			var $input = $('#sob-drawer-qty-input');
			var val = parseInt($input.val(), 10) || 1;
			var max = parseInt($input.attr('max'), 10) || 9999;
			if (val < max) {
				$input.val(val + 1);
			}
		});

		$('body').on('change', '#sob-drawer-qty-input', function() {
			var val = parseInt($(this).val(), 10);
			var min = parseInt($(this).attr('min'), 10) || 1;
			var max = parseInt($(this).attr('max'), 10) || 9999;
			if (isNaN(val) || val < min) {
				val = min;
			}
			if (val > max) {
				val = max;
			}
			$(this).val(val);
		});

		$('body').on('click', '#sob-drawer-add-to-cart-btn', function() {
			addDrawerProductToCart();
		});

		// 8. Recommended Bundles Clicks
		$app.on('click', '.sob-bundle-view-btn', function() {
			var $card = $(this).closest('.sob-bundle-card');
			openBundleModal($card.data('bundle-id'), $card.data('source'));
		});

		$app.on('click', '.sob-bundle-add-btn', function() {
			var $card = $(this).closest('.sob-bundle-card');
			addBundleToCart($card.data('bundle-id'), $card.data('source'));
		});

		// 9. Bundle Modal Clicks
		$('body').on('click', '#sob-bundle-modal-backdrop, .sob-modal-close', function(e) {
			if ( e.target === this || $(this).hasClass('sob-modal-close') ) {
				closeBundleModal();
			}
		});

		$('body').on('click', '.sob-modal-add-bundle-btn', function() {
			var $body = $(this).closest('.sob-modal-body');
			addBundleToCart($body.data('bundle-id'), $body.data('source'));
			closeBundleModal();
		});

		// 10. Upgrade Banner Clicks
		$app.on('click', '.sob-banner-upgrade-btn', function() {
			var bundleId = $(this).closest('.sob-upgrade-banner').data('bundle-id');
			addBundleToCart(bundleId, 'manual');
		});

		$app.on('click', '.sob-banner-dismiss-btn', function() {
			$('#sob-upgrade-banner-container').fadeOut(250);
		});

		// 11. Cart Section (Complete Your Order) quantity changes
		$app.on('click', '.sob-cart-qty-minus', function() {
			adjustCartQty($(this), -1);
		});

		$app.on('click', '.sob-cart-qty-plus', function() {
			adjustCartQty($(this), 1);
		});

		$app.on('change', '.sob-cart-qty-input', function() {
			updateCartQtyDirect($(this));
		});

		$app.on('keydown', '.sob-cart-qty-input', function(e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				$(this).blur();
			}
		});

		// 12. Sidebar Collapsible Bundle Cart Items
		$('body').on('click', '.sob-cart-bundle-header', function(e) {
			if ($(e.target).closest('.sob-bundle-qty-selector, .sob-cart-bundle-remove-btn').length > 0) {
				return;
			}
			var $card = $(this).closest('.sob-cart-bundle-card');
			var $details = $card.find('.sob-cart-bundle-details');
			var $toggle = $card.find('.sob-cart-bundle-toggle');
			
			$details.slideToggle(200);
			$card.toggleClass('expanded');
			if ($card.hasClass('expanded')) {
				$toggle.text('▼');
			} else {
				$toggle.text('▶');
			}
		});

		$('body').on('click', '.sob-bundle-qty-minus', function() {
			var $card = $(this).closest('.sob-cart-bundle-card');
			var groupId = $card.data('bundle-group-id');
			var $input = $card.find('.sob-bundle-qty-input');
			var currentQty = parseInt($input.val()) || 1;
			var newQty = currentQty - 1;
			updateBundleQty(groupId, newQty, $input);
		});

		$('body').on('click', '.sob-bundle-qty-plus', function() {
			var $card = $(this).closest('.sob-cart-bundle-card');
			var groupId = $card.data('bundle-group-id');
			var $input = $card.find('.sob-bundle-qty-input');
			var currentQty = parseInt($input.val()) || 1;
			var newQty = currentQty + 1;
			updateBundleQty(groupId, newQty, $input);
		});

		$('body').on('click', '.sob-cart-bundle-remove-btn', function() {
			if (confirm('Are you sure you want to remove this bundle?')) {
				var $card = $(this).closest('.sob-cart-bundle-card');
				var groupId = $card.data('bundle-group-id');
				updateBundleQty(groupId, 0, null);
			}
		});

		$app.on('click', '#sob-empty-cart-btn', function(e) {
			e.preventDefault();
			if (confirm('Are you sure you want to empty your cart?')) {
				var $loader = $('<div class="sob-loader-overlay"><div class="sob-spinner"></div></div>');
				$('#sob-order-builder').append($loader);
				$.ajax({
					url: sob_params.ajax_url,
					type: 'POST',
					dataType: 'json',
					data: {
						action: 'sob_clear_cart',
						nonce: sob_params.ajax_nonce
					},
					success: function(response) {
						$loader.remove();
						if (response.success) {
							syncUIWithCartState(response.data);
							showToast('Cart emptied.', 'success');
						}
					},
					error: function() {
						$loader.remove();
					}
				});
			}
		});

		// Accordion details toggle logic is registered in global listeners below.

		// Close elements on ESC key
		$(document).on('keydown', function(e) {
			if (e.key === 'Escape') {
				closeProductDrawer();
				closeBundleModal();
			}
		});
	}

	/**
	 * Renders toast alert message.
	 */
	function showToast(message, type) {
		type = type || 'success';
		var $container = $('#sob-toasts');
		if ( !$container.length ) return;

		var $toast = $('<div class="sob-toast ' + type + '">' + message + '</div>');
		$container.append($toast);

		setTimeout(function() {
			$toast.addClass('hide');
			setTimeout(function() {
				$toast.remove();
			}, 300);
		}, 3000);
	}

	/**
	 * Search and query products via Ajax.
	 */
	function fetchProducts() {
		var searchVal = $('#sob-search-input').val();
		var catVal    = $('#sob-category-select').val();
		var $body     = $('#sob-products-body');
		var $loader   = $('#sob-table-loader');

		if (state.xhrSearch) {
			state.xhrSearch.abort();
		}

		$loader.fadeIn(150);

		state.xhrSearch = $.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_search_products',
				nonce: sob_params.ajax_nonce,
				search: searchVal,
				category: catVal,
				page: state.currentPage
			},
			success: function(response) {
				$loader.fadeOut(150);
				if (response.success) {
					$body.html(response.data.html);
					
					// Update pagination parameters
					$('#sob-current-page').text(response.data.current_page);
					$('#sob-total-pages').text(response.data.max_num_pages);
					
					// Toggle button visibility
					$('#sob-pag-prev').prop('disabled', response.data.current_page <= 1);
					$('#sob-pag-next').prop('disabled', response.data.current_page >= response.data.max_num_pages);
					
					if (response.data.max_num_pages <= 1) {
						$('#sob-pagination-container').hide();
					} else {
						$('#sob-pagination-container').show();
					}
					
					// Ensure quantities match what is currently in cart after reload
					triggerCartSummarySync();
				}
			},
			error: function(xhr, status, err) {
				if (status !== 'abort') {
					$loader.fadeOut(150);
					showToast(sob_params.i18n.error_occurred, 'error');
				}
			}
		});
	}

	/**
	 * Adjust quantity spinner from product table row.
	 */
	function adjustTableQty($btn, direction) {
		var $row      = $btn.closest('.sob-product-row');
		var prodId    = $row.data('product-id');
		var $input    = $row.find('.sob-qty-input');
		var current   = parseInt($input.val(), 10) || 0;
		var max       = parseInt($input.attr('max'), 10) || 9999;
		
		var targetVal = current + direction;
		if (targetVal < 0) targetVal = 0;
		if (targetVal > max) targetVal = max;

		if (targetVal === current) return;

		// Show loader state on inputs
		$input.prop('disabled', true);
		$btn.prop('disabled', true);

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_update_quantity',
				nonce: sob_params.ajax_nonce,
				product_id: prodId,
				quantity: targetVal
			},
			success: function(response) {
				$input.prop('disabled', false);
				$btn.prop('disabled', false);

				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.cart_updated, 'success');
				} else {
					showToast(response.data.message || sob_params.i18n.update_failed, 'error');
					$input.val(current); // revert
				}
			},
			error: function() {
				$input.prop('disabled', false);
				$btn.prop('disabled', false);
				showToast(sob_params.i18n.error_occurred, 'error');
				$input.val(current); // revert
			}
		});
	}

	/**
	 * Directly update quantity from input field in product table row.
	 */
	function updateTableQtyDirect($input) {
		var $row      = $input.closest('.sob-product-row');
		var prodId    = $row.data('product-id');
		var val       = parseInt($input.val(), 10);
		var max       = parseInt($input.attr('max'), 10) || 9999;
		
		if (isNaN(val) || val < 0) {
			val = 0;
		}
		if (val > max) {
			val = max;
		}

		$input.val(val);

		var $btns = $row.find('.sob-qty-btn');
		$input.prop('disabled', true);
		$btns.prop('disabled', true);

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_update_quantity',
				nonce: sob_params.ajax_nonce,
				product_id: prodId,
				quantity: val
			},
			success: function(response) {
				$input.prop('disabled', false);
				$btns.prop('disabled', false);

				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.cart_updated, 'success');
				} else {
					showToast(response.data.message || sob_params.i18n.update_failed, 'error');
					if (response.data && response.data.cart_quantities && response.data.cart_quantities[prodId] !== undefined) {
						$input.val(response.data.cart_quantities[prodId]);
					} else {
						$input.val(0);
					}
				}
			},
			error: function() {
				$input.prop('disabled', false);
				$btns.prop('disabled', false);
				showToast(sob_params.i18n.error_occurred, 'error');
				$input.val(0);
			}
		});
	}

	/**
	 * Adjust quantity spinner from sidebar cart item.
	 */
	function adjustCartQty($btn, direction) {
		var $item     = $btn.closest('.sob-cart-item');
		var prodId    = $item.data('product-id');
		var $input    = $item.find('.sob-cart-qty-input');
		var current   = parseInt($input.val(), 10) || 0;
		var max       = parseInt($input.attr('max'), 10) || 9999;
		
		var targetVal = current + direction;
		if (targetVal < 0) targetVal = 0;
		if (targetVal > max) targetVal = max;

		if (targetVal === current) return;

		// Show loader state on inputs
		$input.prop('disabled', true);
		$btn.prop('disabled', true);

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_update_quantity',
				nonce: sob_params.ajax_nonce,
				product_id: prodId,
				quantity: targetVal
			},
			success: function(response) {
				$input.prop('disabled', false);
				$btn.prop('disabled', false);

				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.cart_updated, 'success');
				} else {
					showToast(response.data.message || sob_params.i18n.update_failed, 'error');
					$input.val(current); // revert
				}
			},
			error: function() {
				$input.prop('disabled', false);
				$btn.prop('disabled', false);
				showToast(sob_params.i18n.error_occurred, 'error');
				$input.val(current); // revert
			}
		});
	}

	/**
	 * Directly update quantity from input field in sidebar cart item.
	 */
	function updateCartQtyDirect($input) {
		var $item     = $input.closest('.sob-cart-item');
		var prodId    = $item.data('product-id');
		var val       = parseInt($input.val(), 10);
		var max       = parseInt($input.attr('max'), 10) || 9999;
		
		if (isNaN(val) || val < 0) {
			val = 0;
		}
		if (val > max) {
			val = max;
		}

		$input.val(val);

		var $btns = $item.find('.sob-cart-qty-btn');
		$input.prop('disabled', true);
		$btns.prop('disabled', true);

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_update_quantity',
				nonce: sob_params.ajax_nonce,
				product_id: prodId,
				quantity: val
			},
			success: function(response) {
				$input.prop('disabled', false);
				$btns.prop('disabled', false);

				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.cart_updated, 'success');
				} else {
					showToast(response.data.message || sob_params.i18n.update_failed, 'error');
					if (response.data && response.data.cart_quantities && response.data.cart_quantities[prodId] !== undefined) {
						$input.val(response.data.cart_quantities[prodId]);
					} else {
						$input.val(0);
					}
				}
			},
			error: function() {
				$input.prop('disabled', false);
				$btns.prop('disabled', false);
				showToast(sob_params.i18n.error_occurred, 'error');
				$input.val(0);
			}
		});
	}

	/**
	 * Update quantity of all products inside a bundle group via AJAX.
	 */
	function updateBundleQty(groupId, quantity, $input) {
		var $loader = $('<div class="sob-loader-overlay"><div class="sob-spinner"></div></div>');
		$('#sob-order-builder').append($loader);

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_update_bundle_quantity',
				nonce: sob_params.ajax_nonce,
				bundle_group_id: groupId,
				quantity: quantity
			},
			success: function(response) {
				$loader.remove();
				if (response.success) {
					syncUIWithCartState(response.data);
				} else {
					showToast(response.data.message || 'Error updating bundle.', 'error');
					if ($input) {
						$input.val(parseInt($input.val()) || 1);
					}
				}
			},
			error: function() {
				$loader.remove();
				showToast('Connection error occurred.', 'error');
			}
		});
	}

	/**
	 * Open Right-Side product quick view drawer.
	 */
	function openProductDrawer(productId) {
		var $backdrop = $('#sob-product-drawer-backdrop');
		var $drawer   = $('#sob-product-drawer');

		$drawer.html('<div style="padding: 40px; text-align: center;"><div class="sob-spinner" style="margin:0 auto;"></div></div>');
		$backdrop.fadeIn(200);
		$('html, body').addClass('sob-no-scroll');

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_get_product_quick_view',
				nonce: sob_params.ajax_nonce,
				product_id: productId
			},
			success: function(response) {
				if (response.success) {
					$drawer.html(response.data.html);
					// Check initial variation price state
					handleVariationChange();
				} else {
					$drawer.html('<div style="padding:40px; text-align:center; color:var(--sob-danger);">' + response.data.message + '</div>');
				}
			},
			error: function() {
				$drawer.html('<div style="padding:40px; text-align:center; color:var(--sob-danger);">' + sob_params.i18n.error_occurred + '</div>');
			}
		});
	}

	function closeProductDrawer() {
		$('#sob-product-drawer-backdrop').fadeOut(200);
		$('html, body').removeClass('sob-no-scroll');
	}

	/**
	 * Open Bundle view details Modal.
	 */
	function openBundleModal(bundleId, source) {
		var $backdrop = $('#sob-bundle-modal-backdrop');
		var $modal   = $('#sob-bundle-modal');

		$modal.html('<div style="padding: 40px; text-align: center;"><div class="sob-spinner" style="margin:0 auto;"></div></div>');
		$backdrop.fadeIn(200);
		$('html, body').addClass('sob-no-scroll');

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_get_bundle_quick_view',
				nonce: sob_params.ajax_nonce,
				bundle_id: bundleId,
				source: source
			},
			success: function(response) {
				if (response.success) {
					$modal.html(response.data.html);
				} else {
					$modal.html('<div style="padding:40px; text-align:center; color:var(--sob-danger);">' + response.data.message + '</div>');
				}
			},
			error: function() {
				$modal.html('<div style="padding:40px; text-align:center; color:var(--sob-danger);">' + sob_params.i18n.error_occurred + '</div>');
			}
		});
	}

	function closeBundleModal() {
		$('#sob-bundle-modal-backdrop').fadeOut(200);
		$('html, body').removeClass('sob-no-scroll');
	}

	/**
	 * Handle variation select changes in Quick View drawer.
	 */
	function handleVariationChange() {
		var $body = $('.sob-drawer-body');
		var variationsStr = $body.data('variations');
		if ( !variationsStr ) return; // Not variable

		var variations = typeof variationsStr === 'string' ? JSON.parse(variationsStr) : variationsStr;
		var $selects   = $('.sob-variation-select');
		
		var selectedAttrs = {};
		var allSelected = true;

		$selects.each(function() {
			var attrName = $(this).data('attribute');
			var val      = $(this).val();
			if (!val) {
				allSelected = false;
			}
			selectedAttrs[attrName] = val;
		});

		var $addBtn = $('#sob-drawer-add-to-cart-btn');
		var $priceDisplay = $('#sob-drawer-price-display');

		if (!allSelected) {
			$addBtn.prop('disabled', true);
			$('#sob-selected-variation-id').val('');
			return;
		}

		// Find matching variation object
		var matched = null;
		for (var i = 0; i < variations.length; i++) {
			var variation = variations[i];
			var matchCount = 0;
			var attributesKeys = Object.keys(variation.attributes);

			for (var attrKey in selectedAttrs) {
				var val = selectedAttrs[attrKey];
				// WooCommerce attributes can match empty variation fields (matches all options)
				var vAttrKey = attrKey;
				
				// Attribute taxonomies might have 'attribute_pa_' prefix in variation attributes
				if (!variation.attributes.hasOwnProperty(vAttrKey)) {
					vAttrKey = 'attribute_' + attrKey;
				}

				if (variation.attributes[vAttrKey] === "" || variation.attributes[vAttrKey] === val) {
					matchCount++;
				}
			}

			if (matchCount === Object.keys(selectedAttrs).length) {
				matched = variation;
				break;
			}
		}

		if (matched) {
			$('#sob-selected-variation-id').val(matched.variation_id);
			$priceDisplay.html(matched.price_html);
			
			if (matched.is_in_stock) {
				$addBtn.prop('disabled', false).text(sob_params.i18n.added ? 'Add to Cart' : 'Add to Cart');
				$('#sob-drawer-qty-input').attr('max', matched.max_qty || 9999);
			} else {
				$addBtn.prop('disabled', true).text('Out of Stock');
			}

			// Optionally swap image if variation has custom image
			if (matched.image && matched.image.src) {
				$('#sob-drawer-main-image').attr('src', matched.image.src);
			}
		} else {
			$addBtn.prop('disabled', true).text('Selection Unavailable');
			$('#sob-selected-variation-id').val('');
		}
	}

	/**
	 * Add product in Quick View drawer to WooCommerce cart.
	 */
	function addDrawerProductToCart() {
		var productId = $('.sob-drawer-body').data('product-id');
		var variationId = $('#sob-selected-variation-id').val();
		var qty         = parseInt($('#sob-drawer-qty-input').val(), 10) || 1;
		var $btn        = $('#sob-drawer-add-to-cart-btn');

		$btn.prop('disabled', true).text('Adding...');

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_add_to_cart',
				nonce: sob_params.ajax_nonce,
				product_id: productId,
				variation_id: variationId,
				quantity: qty
			},
			success: function(response) {
				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.added, 'success');
					closeProductDrawer();
				} else {
					showToast(response.data.message || sob_params.i18n.add_failed, 'error');
					$btn.prop('disabled', false).text('Add to Cart');
				}
			},
			error: function() {
				showToast(sob_params.i18n.error_occurred, 'error');
				$btn.prop('disabled', false).text('Add to Cart');
			}
		});
	}

	/**
	 * Add entire bundle products to cart via AJAX.
	 */
	function addBundleToCart(bundleId, source) {
		showToast('Adding bundle items...', 'success');

		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_add_bundle_to_cart',
				nonce: sob_params.ajax_nonce,
				bundle_id: bundleId,
				source: source
			},
			success: function(response) {
				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.bundle_added, 'success');
					
					// Upgrade banner matches might clear out
					if (response.data.upgrade_banner_html === '') {
						$('#sob-upgrade-banner-container').fadeOut(250);
					}
				} else {
					showToast(response.data.message || 'Failed to add bundle.', 'error');
				}
			},
			error: function() {
				showToast(sob_params.i18n.error_occurred, 'error');
			}
		});
	}

	/**
	 * Add Suggested cross-sell item to cart.
	 */
	function addSuggestedProduct(prodId) {
		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_add_suggested_product',
				nonce: sob_params.ajax_nonce,
				product_id: prodId
			},
			success: function(response) {
				if (response.success) {
					syncUIWithCartState(response.data);
					showToast(sob_params.i18n.added, 'success');
				} else {
					showToast(response.data.message || 'Failed to add item.', 'error');
				}
			},
			error: function() {
				showToast(sob_params.i18n.error_occurred, 'error');
			}
		});
	}

	/**
	 * Query cart summary endpoint manually (triggers full sync).
	 */
	function triggerCartSummarySync() {
		$.ajax({
			url: sob_params.ajax_url,
			type: 'POST',
			dataType: 'json',
			data: {
				action: 'sob_get_cart_summary',
				nonce: sob_params.ajax_nonce
			},
			success: function(response) {
				if (response.success) {
					syncUIWithCartState(response.data);
				}
			}
		});
	}

	/**
	 * Master UI sync with returned AJAX JSON cart state structure.
	 */
	function syncUIWithCartState(data) {
		// 1. Update Order Summary sidebar HTML.
		$('#sob-summary-totals').html(data.cart_summary_html);

		// Toggle checkout button disabled status.
		var totalItems = parseInt(data.total_items, 10) || 0;
		if (totalItems <= 0) {
			$('#sob-checkout-button').addClass('disabled');
		} else {
			$('#sob-checkout-button').removeClass('disabled');
		}

		// 2. Update Smart Bundle Upgrade Banner.
		var $bannerContainer = $('#sob-upgrade-banner-container');
		if (data.upgrade_banner_html) {
			$bannerContainer.html(data.upgrade_banner_html).fadeIn(250);
		} else {
			$bannerContainer.fadeOut(250, function() {
				$(this).empty();
			});
		}

		// 3. Update Complete Your Order Suggestions.
		var $suggestionsContainer = $('#sob-suggestions-container');
		var $suggestionsList      = $('#sob-suggestions-list');
		if (data.suggestions_html) {
			$suggestionsList.html(data.suggestions_html);
			$suggestionsContainer.fadeIn(250);
		} else {
			$suggestionsContainer.fadeOut(250, function() {
				$suggestionsList.empty();
			});
		}

		// 4. Synchronize all quantity inputs on product rows in the table.
		$('.sob-product-row').each(function() {
			var pId = $(this).data('product-id');
			var $qtyInput = $(this).find('.sob-qty-input');
			var currentQtyInCart = data.cart_quantities[pId] || 0;
			
			$qtyInput.val(currentQtyInCart);
		});
	}

	/**
	 * Inject custom WooCommerce shop grid card buttons, single product booking CTAs, and hero custom buttons.
	 */
	function initWooCommerceCardEnhancements() {
		// 1. Shop Page Cards - Inject buttons
		$('.woocommerce ul.products li.product').each(function() {
			var $product = $(this);
			
			// Prevent duplicate injection
			if ($product.find('.product-card-buttons').length) {
				return;
			}

			// Auto-detect the product page URL
			var $link = $product.find('.woocommerce-loop-product__link').first();
			if (!$link.length) {
				$link = $product.find('a').first();
			}
			var productUrl = $link.length ? $link.attr('href') : '#';

			// Create container for buttons
			var $buttonContainer = $('<div class="product-card-buttons"></div>');
			$buttonContainer.html(
				'<a href="tel:9412006090" class="product-call-btn">' +
					'<svg viewBox="0 0 24 24">' +
						'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>' +
					'</svg>' +
					'<span>Call Us</span>' +
				'</a>' +
				'<a href="' + productUrl + '" class="product-learn-btn">' +
					'<svg viewBox="0 0 24 24">' +
						'<circle cx="12" cy="12" r="10"></circle>' +
						'<line x1="12" y1="16" x2="12" y2="12"></line>' +
						'<line x1="12" y1="8" x2="12.01" y2="8"></line>' +
					'</svg>' +
					'<span>Reserve Now</span>' +
				'</a>'
			);

			var $price = $product.find('.price');
			if ($price.length) {
				$price.after($buttonContainer);
			} else {
				// Alignment helper for cards with no price
				var $emptyPrice = $('<span class="price empty-price-placeholder">&nbsp;</span>');
				var $title = $product.find('.woocommerce-loop-product__title, h2, h3').first();
				if ($title.length) {
					$title.after($emptyPrice);
					$emptyPrice.after($buttonContainer);
				} else {
					$product.append($emptyPrice).append($buttonContainer);
				}
			}
		});

		// 2. Single Product Page - Inject Call to Reserve button
		var $reserveBtn = $('.single_add_to_cart_button, .wc-bookings-booking-form-button');
		if (!$reserveBtn.length) {
			$reserveBtn = $('button').filter(function() {
				return $(this).text().trim().indexOf('Reserve Now') !== -1;
			}).first();
		}

		if ($reserveBtn.length && !$('.single-product-call-btn').length) {
			var $btnGroup = $('<div class="single-product-booking-buttons"></div>');
			var $callBtn = $(
				'<a href="tel:9412006090" class="single-product-call-btn">' +
					'<svg viewBox="0 0 24 24">' +
						'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>' +
					'</svg>' +
					'<span>Call to Reserve</span>' +
				'</a>'
			);

			$reserveBtn.before($btnGroup);
			$btnGroup.append($reserveBtn).append($callBtn);
		}

		// 3. Hero Section - Inject Customize A Package button next to Reserve Now
		var $heroReserveBtn = $('a, button, .button').filter(function() {
			return $(this).text().trim().toLowerCase() === 'reserve now';
		}).first();

		if ($heroReserveBtn.length && !$('.hero-customize-btn').length) {
			var $customizeBtn = $('<a href="/package" class="hero-customize-btn">Customize A Package</a>');
			var $container = $heroReserveBtn.parent();
			$container.addClass('hero-btn-container');
			$heroReserveBtn.addClass('hero-reserve-btn');
			$heroReserveBtn.after($customizeBtn);
		}
	}

	/**
	 * Setup global event listeners active on all pages (Cart, Checkout, Order details, etc.)
	 */
	function initGlobalEventListeners() {
		// Cart/Checkout/Order details collapsible bundle accordion
		$('body').on('click', '.sob-cart-bundle-display-header', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $wrap = $(this).closest('.sob-cart-bundle-display-wrap');
			var $details = $wrap.find('.sob-cart-bundle-display-details');
			var $icon = $wrap.find('.sob-cart-bundle-display-toggle-icon');
			
			$details.slideToggle(200);
			$wrap.toggleClass('expanded');
			if ($wrap.hasClass('expanded')) {
				$icon.text('▼');
			} else {
				$icon.text('▶');
			}
		});
	}

})( jQuery );
