<<<<<<< HEAD
(function () {
    'use strict';

    function boot() {
        var addButton = document.getElementById('swsb-add-slot');
        var addRangeButton = document.getElementById('swsb-add-slot-range');
        var body = document.getElementById('swsb-slots-body');
        var template = document.getElementById('swsb-slot-template');
        var presetDay = document.getElementById('swsb-preset-day');
        var presetCapacity = document.getElementById('swsb-preset-capacity');
        var rangeStart = document.getElementById('swsb-range-start');
        var rangeEnd = document.getElementById('swsb-range-end');
        var presetButtons = Array.prototype.slice.call(document.querySelectorAll('.swsb-slot-preset'));

        if (!addButton || !body || !template || addButton.dataset.swsbReady === 'yes') {
            return;
        }

        function slotMinutes(label) {
            var match = String(label || '').trim().match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
            if (!match) return null;

            var hour = parseInt(match[1], 10);
            var minute = parseInt(match[2], 10);
            var period = match[3].toUpperCase();

            if (period === 'AM' && hour === 12) hour = 0;
            if (period === 'PM' && hour !== 12) hour += 12;

            return hour * 60 + minute;
        }

        function currentDay() {
            return presetDay ? presetDay.value : 'all';
        }

        function currentCapacity() {
            return Math.max(1, parseInt(presetCapacity ? presetCapacity.value : '1', 10) || 1);
        }

        function rowValues(row) {
            var day = row.querySelector('select[name*="[day]"]');
            var slot = row.querySelector('input[name*="[slot]"]');
            return {
                day: day ? day.value : 'all',
                slot: slot ? slot.value.trim() : ''
            };
        }

        function hasSlot(day, slot) {
            return Array.prototype.slice.call(body.querySelectorAll('tr')).some(function (row) {
                var values = rowValues(row);
                return values.day === day && values.slot.toLowerCase() === String(slot).toLowerCase();
            });
        }

        function updatePresetState() {
            var day = currentDay();
            presetButtons.forEach(function (button) {
                var active = hasSlot(day, button.dataset.swsbPresetSlot || '');
                button.classList.toggle('is-added', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        }

        function addSlot(slot, day, capacity) {
            slot = String(slot || '').trim();
            day = day || currentDay();
            capacity = capacity || currentCapacity();

            if (slot && hasSlot(day, slot)) {
                updatePresetState();
                return false;
            }

            var index = Date.now() + '-' + Math.floor(Math.random() * 100000);
            var tbody = document.createElement('tbody');
            tbody.innerHTML = template.innerHTML.replace(/__index__/g, index).trim();

            Array.prototype.slice.call(tbody.children).forEach(function (row) {
                var daySelect = row.querySelector('select[name*="[day]"]');
                var slotInput = row.querySelector('input[name*="[slot]"]');
                var capacityInput = row.querySelector('input[name*="[capacity]"]');
                var enabledInput = row.querySelector('input[name*="[enabled]"]');

                if (daySelect) daySelect.value = day;
                if (slotInput) slotInput.value = slot;
                if (capacityInput) capacityInput.value = capacity;
                if (enabledInput) enabledInput.checked = true;

                body.appendChild(row);
            });

            updatePresetState();
            return true;
        }

        addButton.dataset.swsbReady = 'yes';
        addButton.addEventListener('click', function () {
            addSlot('', currentDay(), currentCapacity());
        });

        body.addEventListener('click', function (event) {
            if (event.target && event.target.classList.contains('swsb-remove-slot')) {
                event.preventDefault();
                event.target.closest('tr').remove();
                updatePresetState();
            }
        });

        body.addEventListener('change', updatePresetState);

        presetButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                addSlot(button.dataset.swsbPresetSlot || '', currentDay(), currentCapacity());
            });
        });

        if (presetDay) {
            presetDay.addEventListener('change', updatePresetState);
        }

        if (addRangeButton && rangeStart && rangeEnd) {
            addRangeButton.addEventListener('click', function () {
                var start = slotMinutes(rangeStart.value);
                var end = slotMinutes(rangeEnd.value);
                var day = currentDay();
                var capacity = currentCapacity();

                if (start === null || end === null) return;
                if (end < start) {
                    var swap = start;
                    start = end;
                    end = swap;
                }

                presetButtons.forEach(function (button) {
                    var minutes = slotMinutes(button.dataset.swsbPresetSlot || '');
                    if (minutes !== null && minutes >= start && minutes <= end) {
                        addSlot(button.dataset.swsbPresetSlot, day, capacity);
                    }
                });

                updatePresetState();
            });
        }

        updatePresetState();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
=======
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
>>>>>>> 18d880aa7f0ae12d83ae6325acf755817dc221a7
