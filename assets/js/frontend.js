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
