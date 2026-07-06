<<<<<<< HEAD
(function () {
    'use strict';

    var SCRIPT_VERSION = '1.1.6';

    function qsa(root, selector) {
        return Array.prototype.slice.call(root.querySelectorAll(selector));
    }

    function money(amount, symbol) {
        var box = document.createElement('textarea');
        box.innerHTML = symbol || '$';
        return box.value + Number(amount || 0).toFixed(2);
    }

    function prettyDate(value) {
        var parts = value.split('-').map(Number);
        var date = new Date(parts[0], parts[1] - 1, parts[2]);

        return date.toLocaleDateString(undefined, {
            weekday: 'long',
            month: 'long',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function browserTimezone() {
        try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || 'Local browser time';
        } catch (error) {
            return 'Local browser time';
        }
    }

    function convertSlotLabel(dateValue, slotLabel, targetTimezone) {
        var match = String(slotLabel).trim().match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)?$/i);

        if (!dateValue || !match || !targetTimezone) {
            return slotLabel;
        }

        var hour = parseInt(match[1], 10);
        var minute = parseInt(match[2] || '0', 10);
        var meridiem = match[3] ? match[3].toUpperCase() : '';

        if (meridiem === 'PM' && hour < 12) {
            hour += 12;
        }
        if (meridiem === 'AM' && hour === 12) {
            hour = 0;
        }

        var date = new Date(dateValue + 'T' + String(hour).padStart(2, '0') + ':' + String(minute).padStart(2, '0') + ':00Z');

        if (Number.isNaN(date.getTime())) {
            return slotLabel;
        }

        try {
            return new Intl.DateTimeFormat(undefined, {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true,
                timeZone: targetTimezone
            }).format(date);
        } catch (error) {
            return slotLabel;
        }
    }

    function setActive(buttons, activeButton) {
        buttons.forEach(function (button) {
            var active = button === activeButton;
            var isSlot = !!button.dataset.swsbSlotLabel;

            button.classList.toggle('is-selected', active);
            button.setAttribute('aria-checked', active ? 'true' : 'false');
            button.style.background = active ? 'linear-gradient(135deg, #0b8f5a, #08784c)' : '#fff';
            button.style.borderColor = active ? '#0b8f5a' : (isSlot ? '#8f948b' : '#d5d5ce');
            button.style.color = active ? '#fff' : (isSlot ? '#151711' : '#0b8f5a');
            button.style.boxShadow = active ? '0 14px 32px rgba(11,143,90,.34), 0 0 0 4px rgba(223,255,47,.58)' : '0 2px 10px rgba(0,0,0,.05)';
            button.style.transform = active ? 'translateY(-2px) scale(1.03)' : 'translateY(0) scale(1)';
        });
    }

    function initBooking(booking) {
        if (booking.dataset.swsbReady === SCRIPT_VERSION) {
            return;
        }
        booking.dataset.swsbReady = SCRIPT_VERSION;

        var data = {};
        try {
            data = JSON.parse(booking.getAttribute('data-swsb') || '{}');
        } catch (error) {
            data = {};
        }
        var basePrice = parseFloat(data.price || 0);

        var config = window.swsbBooking || {};
        var symbol = config.currencySymbol || '$';
        var form = booking.closest('form.cart') || booking.closest('form') || document;
        var siteTimezone = data.siteTimezone || 'America/New_York';
        var browserTz = browserTimezone();
        var timezone = siteTimezone;
        var dateButtons = qsa(booking, '[data-swsb-date-label]');
        var slotButtons = qsa(booking, '[data-swsb-slot-label]');
        var dayButtons = qsa(booking, '[data-swsb-days]');
        var validationNotice = booking.querySelector('.swsb-validation-notice');
        var timezoneSelect = form.querySelector('[data-swsb-timezone-select]');
        var localTimezoneOption = timezoneSelect ? timezoneSelect.querySelector('[data-swsb-local-timezone-option]') : null;
        var quantityInput = form.querySelector('input.qty');
        var paymentInputs = qsa(form, 'input[name="swsb_payment_type"]');

        function quantity() {
            return Math.max(1, parseInt(quantityInput ? quantityInput.value : '1', 10) || 1);
        }

        function paymentType() {
            var checked = form.querySelector('input[name="swsb_payment_type"]:checked');
            var hidden = form.querySelector('input[name="swsb_payment_type"][type="hidden"]');
            return checked ? checked.value : (hidden ? hidden.value : 'full');
        }

        function depositAmount(total) {
            var amount = Number(data.depositAmount || 0);

            if (data.depositType === 'percent') {
                return total * (amount / 100);
            }

            return Math.min(total, amount * quantity());
        }

        function write(selector, value) {
            var node = booking.querySelector(selector);
            if (node) {
                node.textContent = value;
            }
        }

        function selectedDate() {
            var checked = form.querySelector('input[name="swsb_booking_date"]:checked');
            return checked ? checked.value : '';
        }

        function selectedTime() {
            var checked = form.querySelector('input[name="swsb_booking_time"]:checked');
            return checked ? checked.value : '';
        }

        function selectedVariations() {
            var selected = [];
            if (!data.attributes || !data.variations) {
                return selected;
            }
            data.attributes.forEach(function(attr) {
                var name = 'swsb_attribute_' + attr.id;
                var checked = form.querySelector('input[name="' + name + '"]:checked');
                if (checked) {
                    var vIndex = parseInt(checked.value, 10);
                    // Filter enabled variations for this attribute
                    var attrVars = data.variations.filter(function(v) {
                        return v.attribute_id === attr.id;
                    });
                    if (attrVars[vIndex]) {
                        selected.push(attrVars[vIndex]);
                    }
                }
            });
            return selected;
        }

        function selectedDaysNeeded() {
            var selected = selectedVariations();
            var durationAttrId = data.durationAttributeId || '';
            var days = 1;
            
            selected.forEach(function(v) {
                if (v && v.attribute_id === durationAttrId) {
                    if (v.value && !isNaN(parseInt(v.value, 10))) {
                        days = parseInt(v.value, 10);
                    }
                }
            });
            return Math.max(1, days);
        }

        function getConsecutiveDates(startDateStr, days) {
            var dates = [];
            var parts = startDateStr.split('-').map(Number);
            var date = new Date(parts[0], parts[1] - 1, parts[2]);
            for (var i = 0; i < days; i++) {
                var temp = new Date(date);
                temp.setDate(date.getDate() + i);
                var y = temp.getFullYear();
                var m = String(temp.getMonth() + 1).padStart(2, '0');
                var d = String(temp.getDate()).padStart(2, '0');
                dates.push(y + '-' + m + '-' + d);
            }
            return dates;
        }

        function slotMinutes(label) {
            var value = String(label || '').trim();
            var match12 = value.match(/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i);
            if (match12) {
                var hour = parseInt(match12[1], 10);
                var minute = parseInt(match12[2] || '0', 10);
                var period = match12[3].toUpperCase();
                if (period === 'AM' && hour === 12) hour = 0;
                if (period === 'PM' && hour !== 12) hour += 12;
                return hour * 60 + minute;
            }
            var match24 = value.match(/^(\d{1,2}):(\d{2})$/);
            if (match24) {
                return (parseInt(match24[1], 10) * 60) + parseInt(match24[2], 10);
            }
            return null;
        }

        function calculateEndTime(startTimeLabel, durationStr) {
            if (!startTimeLabel) return '';
            var minutes = slotMinutes(startTimeLabel);
            if (minutes === null) return '';

            var durationMins = 0;
            var hrMatch = String(durationStr).match(/(\d+)\s*hr/i);
            var minMatch = String(durationStr).match(/(\d+)\s*min/i);
            if (hrMatch) {
                durationMins = parseInt(hrMatch[1], 10) * 60;
            } else if (minMatch) {
                durationMins = parseInt(minMatch[1], 10);
            } else {
                var numMatch = String(durationStr).match(/(\d+)/);
                if (numMatch) {
                    durationMins = parseInt(numMatch[1], 10);
                }
            }

            if (!durationMins) return '';

            var endMins = (minutes + durationMins) % 1440;
            var endHour = Math.floor(endMins / 60);
            var endMin = endMins % 60;
            var period = endHour >= 12 ? 'PM' : 'AM';
            var displayHour = endHour % 12;
            if (displayHour === 0) displayHour = 12;
            return displayHour + ':' + String(endMin).padStart(2, '0') + ' ' + period;
        }

        function updateSlotLabels() {
            slotButtons.forEach(function (button) {
                var original = button.dataset.swsbSlotLabel || '';
                var span = button.querySelector('[data-swsb-slot-text]');

                if (span) {
                    span.textContent = original;
                }
            });
        }

        function updateSummary() {
            var qty = quantity();
            var days = selectedDaysNeeded();
            var total = 0;
            var evType = data.eventType || 'single';

            if (evType === 'multi') {
                var selected = selectedVariations();
                var sumPrice = 0;
                selected.forEach(function(v) {
                    sumPrice += parseFloat(v.price || 0);
                });
                if (sumPrice > 0) {
                    total = sumPrice * qty;
                } else {
                    total = Number(data.price || 0) * qty;
                }
            } else {
                total = Number(data.price || 0) * qty;
            }

            var payType = paymentType();
            var deposit = payType === 'deposit' ? (total / 2) : total;
            var remaining = payType === 'deposit' ? Math.max(0, total - deposit) : 0;
            
            var dateValue = selectedDate();
            var timeValue = selectedTime();
            
            var range = [];
            var dateValid = true;
            
            if (dateValue) {
                range = getConsecutiveDates(dateValue, days);
                for (var i = 0; i < range.length; i++) {
                    var dateVal = range[i];
                    var foundCard = dateButtons.find(function(card){
                        return card.getAttribute('data-swsb-date-label') === dateVal;
                    });
                    if (!foundCard) {
                        dateValid = false;
                        break;
                    }
                }
            }

            if (dateValue && !dateValid) {
                var checkedDate = form.querySelector('input[name="swsb_booking_date"]:checked');
                if (checkedDate) {
                    checkedDate.checked = false;
                }
                dateValue = '';
                range = [];
                if (validationNotice) {
                    validationNotice.textContent = 'Selected rental period is unavailable. Please choose another start date.';
                    validationNotice.style.display = 'block';
                }
                var timePanel = booking.querySelector('.swsb-time-panel');
                if (timePanel) {
                    timePanel.style.display = 'none';
                }
            } else {
                if (validationNotice) {
                    validationNotice.style.display = 'none';
                }
                var timePanel = booking.querySelector('.swsb-time-panel');
                if (timePanel && dateValue) {
                    timePanel.style.display = '';
                }
            }

            dayButtons.forEach(function(btn){
                var input = btn.querySelector('input');
                var active = input && input.checked;
                btn.classList.toggle('is-selected', !!active);
                btn.style.setProperty('background', active ? 'linear-gradient(135deg,#0b8f5a,#08784c)' : '#fff', 'important');
                btn.style.setProperty('border-color', active ? '#0b8f5a' : '#d5d5ce', 'important');
                btn.style.setProperty('color', active ? '#fff' : '#0b8f5a', 'important');
                btn.style.setProperty('box-shadow', active ? '0 14px 32px rgba(11,143,90,.34),0 0 0 4px rgba(223,255,47,.58)' : '0 2px 10px rgba(0,0,0,.05)', 'important');
            });

            if (dateValue && dateValid) {
                dateButtons.forEach(function(card){
                    var dateVal = card.getAttribute('data-swsb-date-label');
                    var isSelected = range.indexOf(dateVal) !== -1;
                    card.classList.toggle('is-selected', isSelected);
                    card.setAttribute('aria-checked', isSelected ? 'true' : 'false');
                    card.style.setProperty('background', isSelected ? 'linear-gradient(135deg,#0b8f5a,#08784c)' : '#fff', 'important');
                    card.style.setProperty('border-color', isSelected ? '#0b8f5a' : '#d5d5ce', 'important');
                    card.style.setProperty('color', isSelected ? '#fff' : '#0b8f5a', 'important');
                    card.style.setProperty('box-shadow', isSelected ? '0 14px 32px rgba(11,143,90,.34),0 0 0 4px rgba(223,255,47,.58)' : '0 2px 10px rgba(0,0,0,.05)', 'important');
                    card.style.setProperty('transform', isSelected ? 'translateY(-2px) scale(1.03)' : 'translateY(0) scale(1)', 'important');
                });
            } else {
                setActive(dateButtons, null);
            }

            var timeInput = form.querySelector('input[name="swsb_booking_time"]:checked');
            setActive(slotButtons, timeInput ? timeInput.closest('[data-swsb-slot-label]') : null);

            if (evType === 'multi') {
                if (data.attributes) {
                    data.attributes.forEach(function(attr) {
                        var name = 'swsb_attribute_' + attr.id;
                        var checked = form.querySelector('input[name="' + name + '"]:checked');
                        var label = '-';
                        if (checked) {
                            var vIndex = parseInt(checked.value, 10);
                            var attrVars = data.variations.filter(function(v) {
                                return v.attribute_id === attr.id;
                            });
                            if (attrVars[vIndex]) {
                                label = attrVars[vIndex].label;
                            }
                        }
                        write('[data-swsb-summary-attribute-val="' + attr.id + '"]', label);
                    });
                }
                write('[data-swsb-summary-start-date]', dateValue ? prettyDate(dateValue) : 'Choose from calendar');
                
                var endDateVal = range.length > 0 ? range[range.length - 1] : '';
                write('[data-swsb-summary-end-date]', endDateVal ? prettyDate(endDateVal) : 'Choose from calendar');
                
                var formattedRange = '';
                if (range.length > 0) {
                    formattedRange = range.map(function(d){ return prettyDate(d); }).join(', ');
                } else {
                    formattedRange = 'Choose from calendar';
                }
                write('[data-swsb-summary-selected-dates]', formattedRange);
                write('[data-swsb-summary-start-time]', timeValue || 'Choose a slot');
            } else {
                write('[data-swsb-summary-date]', dateValue ? prettyDate(dateValue) : 'Choose from calendar');
                write('[data-swsb-summary-time]', timeValue || 'Choose a slot');
            }
            
            var endTimeVal = calculateEndTime(timeValue, data.duration);
            write('[data-swsb-summary-end-time]', endTimeVal || '-');

            write('[data-swsb-summary-qty]', qty);
            write('[data-swsb-summary-price]', money(total, symbol));
            write('[data-swsb-summary-deposit]', money(deposit, symbol));
            write('[data-swsb-summary-remaining]', money(remaining, symbol));
            updateSlotLabels();
        }

        if (timezoneSelect && browserTz) {
            timezoneSelect.value = siteTimezone;
            if (localTimezoneOption) {
                localTimezoneOption.value = browserTz;
                try {
                    localTimezoneOption.textContent = 'Your local time: ' + new Intl.DateTimeFormat([], {
                        hour: 'numeric',
                        minute: '2-digit',
                        hour12: true,
                        timeZone: browserTz
                    }).format(new Date());
                } catch (error) {
                    localTimezoneOption.textContent = 'Your local time';
                }
            }
            timezoneSelect.addEventListener('change', function () {
                timezone = timezoneSelect.value || siteTimezone;
                updateSlotLabels();
                updateSummary();
            });
        }

        dateButtons.forEach(function (button) {
            var input = button.querySelector('input[name="swsb_booking_date"]');

            function chooseDate() {
                setActive(dateButtons, button);
                updateSummary();
            }

            button.addEventListener('click', chooseDate);
            if (input) {
                input.addEventListener('change', chooseDate);
            }
        });

        slotButtons.forEach(function (button) {
            var input = button.querySelector('input[name="swsb_booking_time"]');

            function chooseSlot() {
                setActive(slotButtons, button);
                updateSummary();
            }

            button.addEventListener('click', chooseSlot);
            if (input) {
                input.addEventListener('change', chooseSlot);
            }
        });

        dayButtons.forEach(function (button) {
            var input = button.querySelector('input[type="radio"]');
            function chooseDays() {
                updateSummary();
            }
            button.addEventListener('click', function() {
                if (input) input.checked = true;
                chooseDays();
            });
            if (input) {
                input.addEventListener('change', chooseDays);
            }
        });

        if (quantityInput) {
            quantityInput.addEventListener('input', updateSummary);
            quantityInput.addEventListener('change', updateSummary);
        }

        paymentInputs.forEach(function (input) {
            input.addEventListener('change', updateSummary);
        });

        form.addEventListener('submit', function (event) {
            if (!selectedDate() || !selectedTime()) {
                event.preventDefault();
                booking.scrollIntoView({ behavior: 'smooth', block: 'center' });
                booking.style.boxShadow = '0 0 0 4px rgba(223,255,47,.7), 0 16px 40px rgba(11,143,90,.22)';
                setTimeout(function () {
                    booking.style.boxShadow = '';
                }, 1500);
            }
        });

        updateSlotLabels();
        updateSummary();

        // Variations and attributes integration
        var $form = jQuery(form);
        if ($form.length && $form.hasClass('variations_form')) {
            var currentVarId = parseInt($form.find('input[name="variation_id"]').val() || 0, 10);
            if (!currentVarId) {
                booking.style.opacity = '0.4';
                booking.style.pointerEvents = 'none';
            }

            $form.on('found_variation', function(event, variation) {
                if (variation && variation.variation_id) {
                    data.price = parseFloat(variation.display_price);

                    var varIdInput = form.querySelector('input[name="swsb_variation_id"]');
                    if (!varIdInput) {
                        varIdInput = document.createElement('input');
                        varIdInput.type = 'hidden';
                        varIdInput.name = 'swsb_variation_id';
                        form.appendChild(varIdInput);
                    }
                    varIdInput.value = variation.variation_id;

                    var varAttrsInput = form.querySelector('input[name="swsb_variation_attributes"]');
                    if (!varAttrsInput) {
                        varAttrsInput = document.createElement('input');
                        varAttrsInput.type = 'hidden';
                        varAttrsInput.name = 'swsb_variation_attributes';
                        form.appendChild(varAttrsInput);
                    }
                    varAttrsInput.value = JSON.stringify(variation.attributes);

                    booking.style.opacity = '1';
                    booking.style.pointerEvents = 'auto';

                    updateSummary();
                }
            });

            $form.on('reset_data', function() {
                data.price = basePrice;

                var varIdInput = form.querySelector('input[name="swsb_variation_id"]');
                if (varIdInput) varIdInput.value = '';

                var varAttrsInput = form.querySelector('input[name="swsb_variation_attributes"]');
                if (varAttrsInput) varAttrsInput.value = '';

                booking.style.opacity = '0.4';
                booking.style.pointerEvents = 'none';

                updateSummary();
            });
        }
    }

    function boot() {
        qsa(document, '.swsb-booking').forEach(initBooking);
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
>>>>>>> 18d880aa7f0ae12d83ae6325acf755817dc221a7
