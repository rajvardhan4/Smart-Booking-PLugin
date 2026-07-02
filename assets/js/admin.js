/**
 * Smart Order Builder for WooCommerce
 * Admin Interface Javascript
 */

(function( $ ) {
	'use strict';

	$(document).ready(function() {
		initProductSelect2();
		initAdminOrderBundleGrouping();
	});

	/**
	 * Initialize Select2 on the Bundle Custom Post Type product selection box.
	 */
	function initProductSelect2() {
		var $select = $('.sob-product-search-select');
		if ( !$select.length ) {
			return;
		}

		if ( typeof $.fn.select2 !== 'undefined' ) {
			$select.select2({
				ajax: {
					url: sob_admin_params.ajax_url,
					dataType: 'json',
					delay: 300,
					data: function (params) {
						return {
							q: params.term,
							action: 'sob_admin_search_products',
							nonce: sob_admin_params.search_nonce
						};
					},
					processResults: function (data) {
						return {
							results: data.results
						};
					},
					cache: true
				},
				placeholder: 'Search for a product...',
				minimumInputLength: 2,
				width: '100%'
			});
		}
	}

	/**
	 * Group bundle products visually on the WooCommerce admin order edit page.
	 */
	function initAdminOrderBundleGrouping() {
		var groups = {};
		
		// Find all rows with the bundle badge
		$('.sob-admin-bundle-badge').each(function() {
			var groupId = $(this).data('group-id');
			var $tr = $(this).closest('tr');
			if (!groupId) return;
			if (!groups[groupId]) {
				groups[groupId] = [];
			}
			groups[groupId].push($tr);
		});

		// For each group, wrap them or style them
		$.each(groups, function(groupId, trs) {
			if (trs.length === 0) return;
			
			var $firstTr = trs[0];
			var badgeText = $(trs[0]).find('.sob-admin-bundle-badge').text();
			var bundleName = badgeText.replace('Part of Bundle: ', '').replace('Included in: ', '').trim();
			
			// Create a header row
			var $headerRow = $('<tr class="sob-admin-bundle-header-row" style="background: #f8fafc;"><td colspan="6" style="padding: 10px; border-bottom: 2px solid #bae6fd;">' +
				'<span class="dashicons dashicons-arrow-right-alt2 sob-admin-bundle-toggle" style="cursor:pointer; vertical-align: middle; transition: transform 0.2s; font-size: 18px; width: 18px; height: 18px; margin-right: 5px;"></span> ' +
				'<strong style="font-size: 1.1em; vertical-align: middle; color: #0369a1;">🎁 ' + bundleName + '</strong>' +
				'</td></tr>');
				
			$firstTr.before($headerRow);
			
			var $toggle = $headerRow.find('.sob-admin-bundle-toggle');
			
			// Hide/show toggle logic
			$toggle.on('click', function() {
				$toggle.toggleClass('expanded');
				if ($toggle.hasClass('expanded')) {
					$toggle.css('transform', 'rotate(90deg)');
					$.each(trs, function(i, $tr) {
						$tr.show();
					});
				} else {
					$toggle.css('transform', 'rotate(0deg)');
					$.each(trs, function(i, $tr) {
						$tr.hide();
					});
				}
			});
			
			// Style and hide child rows by default
			$.each(trs, function(i, $tr) {
				$tr.css({
					'background': '#f0f9ff',
					'border-left': '4px solid #0284c7'
				}).hide();
			});
		});
	}

})( jQuery );
