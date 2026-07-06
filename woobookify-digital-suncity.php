<?php
/**
 * Plugin Name: WooBookify
 * Description: Premium single-date, single-slot booking system for WooCommerce service products.
 * Version: 1.1.7
 * Author: Digital Suncity
 * Text Domain: woobookify
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WBDS_VERSION', '1.1.7');
define('WBDS_URL', plugin_dir_url(__FILE__));
define('WBDS_PATH', plugin_dir_path(__FILE__));

final class WooBookify_Digital_Suncity_Plugin {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'booking_ui']);
        add_filter('woocommerce_product_data_tabs', [$this, 'product_data_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'product_data_panel']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_settings']);
        add_filter('woocommerce_is_sold_individually', [$this, 'booking_sold_individually'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [$this, 'booking_button_text'], 10, 2);
        add_filter('woocommerce_add_to_cart_quantity', [$this, 'booking_add_to_cart_quantity'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_booking'], 10, 4);
        add_filter('woocommerce_add_cart_item_data', [$this, 'save_cart_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_data'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [$this, 'apply_deposit_price']);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
        add_action('woocommerce_checkout_order_processed', [$this, 'create_booking_records'], 20, 3);
        add_action('woocommerce_email_order_meta', [$this, 'email_order_booking_meta'], 20, 4);
        add_action('wp_ajax_swsb_get_slots', [$this, 'ajax_get_slots']);
        add_action('wp_ajax_nopriv_swsb_get_slots', [$this, 'ajax_get_slots']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_swsb_export_bookings', [$this, 'export_bookings_csv']);
        add_action('admin_post_swsb_booking_status', [$this, 'update_booking_status']);
        add_filter('woocommerce_email_from_name', [$this, 'email_from_name']);
        add_filter('woocommerce_email_from_address', [$this, 'email_from_address']);
    }

    public static function activate() {
        global $wpdb;

        if (!function_exists('deactivate_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $active_plugins = (array) get_option('active_plugins', []);
        foreach ($active_plugins as $active_plugin) {
            if (
                false !== strpos($active_plugin, 'smart-woocommerce-slot-booking')
                || false !== strpos($active_plugin, 'dumpking-booking-rsr')
            ) {
                deactivate_plugins($active_plugin, true);
            }
        }

        $table = self::bookings_table();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL DEFAULT 0,
            product_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            customer_name varchar(200) NOT NULL DEFAULT '',
            customer_email varchar(200) NOT NULL DEFAULT '',
            customer_phone varchar(80) NOT NULL DEFAULT '',
            booking_date date NOT NULL,
            booking_slot varchar(80) NOT NULL DEFAULT '',
            quantity int(11) NOT NULL DEFAULT 1,
            duration varchar(80) NOT NULL DEFAULT '',
            payment_type varchar(30) NOT NULL DEFAULT 'full',
            deposit_amount decimal(18,4) NOT NULL DEFAULT 0,
            remaining_amount decimal(18,4) NOT NULL DEFAULT 0,
            notes text NULL,
            status varchar(30) NOT NULL DEFAULT 'confirmed',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY product_id (product_id),
            KEY booking_date (booking_date),
            KEY status (status)
        ) {$charset_collate};");

        add_option('swsb_time_format', '12');
        add_option('swsb_emails_enabled', 'yes');
        add_option('swsb_email_sender_name', get_bloginfo('name'));
        add_option('swsb_email_sender_email', get_bloginfo('admin_email'));
        add_option('swsb_email_accent_color', '#dfff2f');
    }

    private static function bookings_table() {
        global $wpdb;

        return $wpdb->prefix . 'smart_bookings';
    }

    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    public function frontend_assets() {
        $this->enqueue_frontend_assets();
    }

    private function enqueue_frontend_assets() {
        if (wp_style_is('swsb-style', 'enqueued') && wp_script_is('swsb-script', 'enqueued')) {
            return;
        }

        wp_enqueue_style('swsb-style', WBDS_URL . 'assets/css/frontend.css', [], WBDS_VERSION);
        wp_enqueue_script('swsb-script', WBDS_URL . 'assets/js/frontend.js', [], WBDS_VERSION, true);
        wp_localize_script('swsb-script', 'swsbBooking', $this->frontend_script_config());
    }

    private function frontend_script_config() {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('swsb_frontend_nonce'),
            'timeFormat' => get_option('swsb_time_format', '12'),
            'currencySymbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$',
            'i18n' => [
                'selectDate' => __('Select a date to view availability', 'smart-woocommerce-slot-booking'),
                'noSlots' => __('No available slots for this date.', 'smart-woocommerce-slot-booking'),
                'loading' => __('Loading slots...', 'smart-woocommerce-slot-booking'),
            ],
        ];
    }

    private function inline_frontend_styles() {
        static $printed = false;

        if ($printed) {
            return;
        }

        $printed = true;
        $css_file = WBDS_PATH . 'assets/css/frontend.css';
        $css = file_exists($css_file) ? file_get_contents($css_file) : '';

        if ($css) {
            echo '<style id="swsb-inline-style">' . wp_strip_all_tags($css) . '</style>';
        }

        echo '<script id="swsb-inline-config">window.swsbBooking=' . wp_json_encode($this->frontend_script_config()) . ';</script>';
    }

    private function inline_frontend_script() {
        static $printed = false;

        if ($printed) {
            return;
        }

        $printed = true;
        $js_file = WBDS_PATH . 'assets/js/frontend.js';
        $js = file_exists($js_file) ? file_get_contents($js_file) : '';

        if ($js) {
            echo '<script id="swsb-inline-script">' . $js . '</script>';
        }
    }

    public function admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php', 'toplevel_page_swsb-bookings', 'swsb-bookings_page_swsb-settings', 'bookings_page_swsb-settings'], true)) {
            return;
        }

        wp_enqueue_style('swsb-admin', WBDS_URL . 'assets/css/admin.css', [], WBDS_VERSION);
        wp_enqueue_script('swsb-admin', WBDS_URL . 'assets/js/admin.js', ['jquery'], WBDS_VERSION, true);
    }

    public function product_data_tab($tabs) {
        $tabs['swsb_booking'] = [
            'label' => __('Booking Settings', 'smart-woocommerce-slot-booking'),
            'target' => 'swsb_booking_data',
            'class' => [],
            'priority' => 65,
        ];

        return $tabs;
    }

    public function product_data_panel() {
        global $post;

        $product_id = $post ? $post->ID : 0;
        $settings = $this->get_product_settings($product_id);
        $slots = $this->get_product_slots($product_id);
        $days = $this->weekdays();
        ?>
        <div id="swsb_booking_data" class="panel woocommerce_options_panel swsb-admin-panel hidden">
            <div class="swsb-admin-grid">
                <section>
                    <h3><?php esc_html_e('General', 'smart-woocommerce-slot-booking'); ?></h3>
                    <?php
                    woocommerce_wp_checkbox([
                        'id' => '_swsb_enabled',
                        'label' => __('Enable booking', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['enabled'],
                    ]);
                    woocommerce_wp_select([
                        'id' => '_swsb_event_type',
                        'label' => __('Event Type', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['event_type'],
                        'options' => [
                            'single' => __('Single Day Event', 'smart-woocommerce-slot-booking'),
                            'multi' => __('Multi Day Event', 'smart-woocommerce-slot-booking'),
                        ],
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_button_text',
                        'label' => __('Booking button text', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['button_text'],
                        'placeholder' => __('Book Now', 'smart-woocommerce-slot-booking'),
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_duration',
                        'label' => __('Service duration', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['duration'],
                        'placeholder' => __('8 hr', 'smart-woocommerce-slot-booking'),
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_max_per_slot',
                        'label' => __('Default max bookings per slot', 'smart-woocommerce-slot-booking'),
                        'type' => 'number',
                        'custom_attributes' => ['min' => '1'],
                        'value' => $settings['max_per_slot'],
                    ]);
                    ?>
                </section>

                <section>
                    <h3><?php esc_html_e('Calendar', 'smart-woocommerce-slot-booking'); ?></h3>
                    <?php
                    woocommerce_wp_select([
                        'id' => '_swsb_disable_weekends',
                        'label' => __('Disable weekends', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['disable_weekends'],
                        'options' => ['no' => __('No', 'smart-woocommerce-slot-booking'), 'yes' => __('Yes', 'smart-woocommerce-slot-booking')],
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_disabled_dates',
                        'label' => __('Disable custom dates', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['disabled_dates'],
                        'description' => __('Comma separated YYYY-MM-DD dates.', 'smart-woocommerce-slot-booking'),
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_buffer_days',
                        'label' => __('Buffer days', 'smart-woocommerce-slot-booking'),
                        'type' => 'number',
                        'custom_attributes' => ['min' => '0'],
                        'value' => $settings['buffer_days'],
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_advance_limit',
                        'label' => __('Advance booking limit days', 'smart-woocommerce-slot-booking'),
                        'type' => 'number',
                        'custom_attributes' => ['min' => '0'],
                        'value' => $settings['advance_limit'],
                    ]);
                    ?>
                </section>

                <section>
                    <h3><?php esc_html_e('Pricing', 'smart-woocommerce-slot-booking'); ?></h3>
                    <?php
                    woocommerce_wp_checkbox([
                        'id' => '_swsb_deposit_enabled',
                        'label' => __('Enable deposits', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['deposit_enabled'],
                    ]);
                    woocommerce_wp_select([
                        'id' => '_swsb_deposit_type',
                        'label' => __('Deposit type', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['deposit_type'],
                        'options' => ['fixed' => __('Fixed amount', 'smart-woocommerce-slot-booking'), 'percent' => __('Percentage', 'smart-woocommerce-slot-booking')],
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_deposit_amount',
                        'label' => __('Deposit amount', 'smart-woocommerce-slot-booking'),
                        'type' => 'number',
                        'custom_attributes' => ['min' => '0', 'step' => '0.01'],
                        'value' => $settings['deposit_amount'],
                    ]);
                    woocommerce_wp_text_input([
                        'id' => '_swsb_remaining_text',
                        'label' => __('Remaining amount text', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['remaining_text'],
                    ]);
                    ?>
                </section>

                <section>
                    <h3><?php esc_html_e('Summary', 'smart-woocommerce-slot-booking'); ?></h3>
                    <?php
                    woocommerce_wp_textarea_input([
                        'id' => '_swsb_summary_notes',
                        'label' => __('Custom summary notes', 'smart-woocommerce-slot-booking'),
                        'value' => $settings['summary_notes'],
                    ]);
                    ?>
                </section>
            </div>

            <!-- Section 1: Attribute Manager -->
            <section class="swsb-attribute-manager swsb-multi-day-only-field" style="margin-top:24px;border-top:1px solid #d9d9d2;padding-top:18px;">
                <h3><?php esc_html_e('Attribute Manager', 'smart-woocommerce-slot-booking'); ?></h3>
                <p><?php esc_html_e('Manage booking attributes for Multi Day Events.', 'smart-woocommerce-slot-booking'); ?></p>

                <table class="widefat swsb-attributes-table" style="margin-top: 14px; width: 100%;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Attribute Name', 'smart-woocommerce-slot-booking'); ?></th>
                            <th style="width: 150px; text-align: center;"><?php esc_html_e('Controls Duration', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'smart-woocommerce-slot-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="swsb-attributes-body">
                        <?php if (!empty($settings['attributes'])) : ?>
                            <?php foreach ($settings['attributes'] as $index => $attribute) : ?>
                                <?php 
                                $is_duration = ($attribute['id'] === $settings['duration_attribute_id']);
                                $this->attribute_row($index, $attribute, $is_duration); 
                                ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="swsb-add-attribute"><?php esc_html_e('Add Attribute', 'smart-woocommerce-slot-booking'); ?></button></p>
                <script type="text/template" id="swsb-attribute-template">
                    <?php $this->attribute_row('__index__', ['id' => '', 'name' => ''], false); ?>
                </script>
            </section>

            <!-- Section 2: Variation Manager -->
            <section class="swsb-variation-manager swsb-multi-day-only-field" style="margin-top:24px;border-top:1px solid #d9d9d2;padding-top:18px; display: none;" id="swsb-variation-manager-container">
                <h3 id="swsb-variation-manager-title"><?php esc_html_e('Variation Manager', 'smart-woocommerce-slot-booking'); ?></h3>
                <p><?php esc_html_e('Manage variations belonging to the active attribute.', 'smart-woocommerce-slot-booking'); ?></p>

                <table class="widefat swsb-variations-table" style="margin-top: 14px; width: 100%;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Variation Label', 'smart-woocommerce-slot-booking'); ?></th>
                            <th class="swsb-col-duration"><?php esc_html_e('Booking Duration', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Price', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Enabled', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Sort Order', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'smart-woocommerce-slot-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="swsb-variations-body">
                        <?php if (!empty($settings['variations'])) : ?>
                            <?php foreach ($settings['variations'] as $index => $variation) : ?>
                                <?php $this->variation_row($index, $variation); ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="swsb-add-variation"><?php esc_html_e('Add Variation', 'smart-woocommerce-slot-booking'); ?></button></p>
                <script type="text/template" id="swsb-variation-template">
                    <?php $this->variation_row('__index__', ['attribute_id' => '__attribute_id__', 'label' => '', 'value' => '', 'price' => 0, 'enabled' => 'yes', 'sort' => 0]); ?>
                </script>
            </section>

            <script>
            (function(){
                var addAttrBtn = document.getElementById('swsb-add-attribute');
                var addVarBtn = document.getElementById('swsb-add-variation');
                var attrBody = document.getElementById('swsb-attributes-body');
                var varBody = document.getElementById('swsb-variations-body');
                var attrTemplate = document.getElementById('swsb-attribute-template');
                var varTemplate = document.getElementById('swsb-variation-template');
                var varContainer = document.getElementById('swsb-variation-manager-container');
                var varTitle = document.getElementById('swsb-variation-manager-title');

                if (!addAttrBtn || !addVarBtn || !attrBody || !varBody || !attrTemplate || !varTemplate || !varContainer || !varTitle) {
                    return;
                }

                var activeAttributeId = '';

                // Active Attribute & Variations Filtering
                function selectAttribute(id, name) {
                    activeAttributeId = id;
                    varContainer.style.display = 'block';
                    varTitle.textContent = '<?php esc_html_e('Variations for', 'smart-woocommerce-slot-booking'); ?> "' + name + '"';
                    
                    // Highlight active row in attributes table
                    attrBody.querySelectorAll('.swsb-attribute-row').forEach(function(row){
                        var rowId = row.querySelector('.swsb-attribute-id-input').value;
                        if (rowId === id) {
                            row.style.background = '#f0f5fa';
                        } else {
                            row.style.background = '';
                        }
                    });

                    // Hide/Show Duration Column based on radio selection
                    var durationAttrRadio = attrBody.querySelector('.swsb-duration-attr-radio:checked');
                    var isDuration = durationAttrRadio && durationAttrRadio.value === id;

                    var table = varContainer.querySelector('table');
                    var durationHeaders = table.querySelectorAll('th.swsb-col-duration');
                    var durationCells = varBody.querySelectorAll('td.swsb-col-duration');

                    durationHeaders.forEach(function(th){
                        th.style.display = isDuration ? '' : 'none';
                    });
                    
                    // Filter variation rows
                    varBody.querySelectorAll('.swsb-variation-row').forEach(function(row){
                        var rowAttrId = row.querySelector('.swsb-variation-attr-id-input').value;
                        if (rowAttrId === id) {
                            row.style.display = '';
                            var cell = row.querySelector('td.swsb-col-duration');
                            if (cell) {
                                cell.style.display = isDuration ? '' : 'none';
                            }
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }

                // Attributes List Events
                addAttrBtn.addEventListener('click', function(){
                    var index = Date.now();
                    var id = 'attr_' + index;
                    var html = attrTemplate.innerHTML
                        .replace(/__index__/g, index)
                        .replace(/attr___index__/g, id);
                    var tbody = document.createElement('tbody');
                    tbody.innerHTML = html.trim();
                    var newRow = tbody.firstElementChild;
                    attrBody.appendChild(newRow);

                    // If it's the first attribute, check the controls duration radio
                    var radios = attrBody.querySelectorAll('.swsb-duration-attr-radio');
                    if (radios.length === 1) {
                        radios[0].checked = true;
                    }

                    var nameInput = newRow.querySelector('.swsb-attribute-name-input');
                    selectAttribute(id, nameInput.value || 'Unnamed Attribute');
                });

                attrBody.addEventListener('click', function(event){
                    if (event.target && event.target.classList.contains('swsb-remove-attribute')) {
                        event.preventDefault();
                        if (confirm('Are you sure you want to remove this attribute and all its variations?')) {
                            var row = event.target.closest('tr');
                            if (row) {
                                var id = row.querySelector('.swsb-attribute-id-input').value;
                                row.remove();
                                
                                // Clean up child variations
                                varBody.querySelectorAll('.swsb-variation-row').forEach(function(vr){
                                    if (vr.querySelector('.swsb-variation-attr-id-input').value === id) {
                                        vr.remove();
                                    }
                                });

                                if (activeAttributeId === id) {
                                    varContainer.style.display = 'none';
                                    activeAttributeId = '';
                                }
                            }
                        }
                    } else if (event.target && event.target.classList.contains('swsb-edit-attribute-variations')) {
                        event.preventDefault();
                        var row = event.target.closest('tr');
                        if (row) {
                            var id = row.querySelector('.swsb-attribute-id-input').value;
                            var name = row.querySelector('.swsb-attribute-name-input').value;
                            selectAttribute(id, name);
                        }
                    }
                });

                attrBody.addEventListener('input', function(event){
                    if (event.target && event.target.classList.contains('swsb-attribute-name-input')) {
                        var row = event.target.closest('tr');
                        var id = row.querySelector('.swsb-attribute-id-input').value;
                        if (activeAttributeId === id) {
                            varTitle.textContent = '<?php esc_html_e('Variations for', 'smart-woocommerce-slot-booking'); ?> "' + event.target.value + '"';
                        }
                    }
                });

                attrBody.addEventListener('change', function(event){
                    if (event.target && event.target.classList.contains('swsb-duration-attr-radio')) {
                        // Re-trigger columns visibility for active attribute variations
                        if (activeAttributeId) {
                            var activeRow = attrBody.querySelector('.swsb-attribute-row[data-attribute-id="' + activeAttributeId + '"]');
                            if (activeRow) {
                                var name = activeRow.querySelector('.swsb-attribute-name-input').value;
                                selectAttribute(activeAttributeId, name);
                            }
                        }
                    }
                });

                // Variations Manager Events
                addVarBtn.addEventListener('click', function(){
                    if (!activeAttributeId) {
                        alert('Please select or add an attribute first.');
                        return;
                    }
                    var index = Date.now();
                    var html = varTemplate.innerHTML
                        .replace(/__index__/g, index)
                        .replace(/__attribute_id__/g, activeAttributeId);
                    var tbody = document.createElement('tbody');
                    tbody.innerHTML = html.trim();
                    var newRow = tbody.firstElementChild;
                    varBody.appendChild(newRow);

                    // Re-trigger filtering to ensure correct columns visibility
                    var activeRow = attrBody.querySelector('.swsb-attribute-row[data-attribute-id="' + activeAttributeId + '"]');
                    if (activeRow) {
                        var name = activeRow.querySelector('.swsb-attribute-name-input').value;
                        selectAttribute(activeAttributeId, name);
                    }
                });

                varBody.addEventListener('click', function(event){
                    if (event.target && event.target.classList.contains('swsb-remove-variation')) {
                        event.preventDefault();
                        var row = event.target.closest('tr');
                        if (row) row.remove();
                    }
                });

                // Auto-load variations for the first attribute on page ready
                var firstRow = attrBody.querySelector('.swsb-attribute-row');
                if (firstRow) {
                    var id = firstRow.querySelector('.swsb-attribute-id-input').value;
                    var name = firstRow.querySelector('.swsb-attribute-name-input').value;
                    selectAttribute(id, name);
                }
            }());
            </script>

            <script>
            jQuery(document).ready(function($){
                function swsb_toggle_settings() {
                    var eventType = $('#_swsb_event_type').val();
                    
                    if (eventType === 'multi') {
                        $('.swsb-multi-day-only-field').each(function(){
                            if ($(this).is('section')) {
                                $(this).show();
                            } else {
                                $(this).closest('.form-field').show();
                            }
                        });
                    } else {
                        $('.swsb-multi-day-only-field').each(function(){
                            if ($(this).is('section')) {
                                $(this).hide();
                            } else {
                                $(this).closest('.form-field').hide();
                            }
                        });
                    }
                }
                
                $('#_swsb_event_type').on('change', swsb_toggle_settings);
                swsb_toggle_settings();
            });
            </script>

            <section class="swsb-slot-manager">
                <h3><?php esc_html_e('Booking Slot Manager', 'smart-woocommerce-slot-booking'); ?></h3>
                <p><?php esc_html_e('Use single slot labels only, for example 9:00 AM or 14:00. No from-to format is used.', 'smart-woocommerce-slot-booking'); ?></p>
                <div class="swsb-slot-presets" aria-label="<?php esc_attr_e('Quick add booking time slots', 'smart-woocommerce-slot-booking'); ?>">
                    <div class="swsb-slot-presets-head">
                        <div>
                            <h4><?php esc_html_e('Quick Add Half-Hour Slots', 'smart-woocommerce-slot-booking'); ?></h4>
                            <p><?php esc_html_e('Click any preset time to add it to this product. Existing slots are highlighted.', 'smart-woocommerce-slot-booking'); ?></p>
                        </div>
                        <div class="swsb-slot-preset-controls">
                            <label>
                                <?php esc_html_e('Day', 'smart-woocommerce-slot-booking'); ?>
                                <select id="swsb-preset-day">
                                    <?php foreach ($days as $day_key => $day_label) : ?>
                                        <option value="<?php echo esc_attr($day_key); ?>"><?php echo esc_html($day_label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <?php esc_html_e('Capacity', 'smart-woocommerce-slot-booking'); ?>
                                <input id="swsb-preset-capacity" type="number" min="1" value="1">
                            </label>
                        </div>
                    </div>
                    <div class="swsb-slot-range">
                        <label>
                            <?php esc_html_e('Start', 'smart-woocommerce-slot-booking'); ?>
                            <select id="swsb-range-start">
                                <?php foreach ($this->half_hour_slot_options() as $slot_option) : ?>
                                    <option value="<?php echo esc_attr($slot_option); ?>" <?php selected($slot_option, '7:00 AM'); ?>><?php echo esc_html($slot_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <?php esc_html_e('End', 'smart-woocommerce-slot-booking'); ?>
                            <select id="swsb-range-end">
                                <?php foreach ($this->half_hour_slot_options() as $slot_option) : ?>
                                    <option value="<?php echo esc_attr($slot_option); ?>" <?php selected($slot_option, '10:00 AM'); ?>><?php echo esc_html($slot_option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <button type="button" class="button button-primary" id="swsb-add-slot-range"><?php esc_html_e('Add Range', 'smart-woocommerce-slot-booking'); ?></button>
                    </div>
                    <div class="swsb-slot-preset-grid">
                        <?php foreach ($this->half_hour_slot_options() as $slot_option) : ?>
                            <button type="button" class="button swsb-slot-preset" data-swsb-preset-slot="<?php echo esc_attr($slot_option); ?>"><?php echo esc_html($slot_option); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <table class="widefat swsb-slots-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Day', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Time Slot', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Capacity', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Enabled', 'smart-woocommerce-slot-booking'); ?></th>
                            <th><?php esc_html_e('Actions', 'smart-woocommerce-slot-booking'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="swsb-slots-body">
                        <?php foreach ($slots as $index => $slot) : ?>
                            <?php $this->slot_row($index, $slot, $days); ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button type="button" class="button" id="swsb-add-slot"><?php esc_html_e('Add More', 'smart-woocommerce-slot-booking'); ?></button></p>
                <script type="text/template" id="swsb-slot-template">
                    <?php $this->slot_row('__index__', ['day' => 'all', 'slot' => '', 'capacity' => '1', 'enabled' => 'yes'], $days); ?>
                </script>
                <script>
                (function(){
                    var addButton = document.getElementById('swsb-add-slot');
                    var body = document.getElementById('swsb-slots-body');
                    var template = document.getElementById('swsb-slot-template');

                    if (document.querySelector('.swsb-slot-presets')) {
                        return;
                    }

                    if (!addButton || !body || !template || addButton.dataset.swsbReady === 'yes') {
                        return;
                    }

                    addButton.dataset.swsbReady = 'yes';
                    addButton.addEventListener('click', function(){
                        var index = Date.now();
                        var html = template.innerHTML.replace(/__index__/g, index);
                        var tbody = document.createElement('tbody');
                        tbody.innerHTML = html.trim();
                        Array.prototype.slice.call(tbody.children).forEach(function(row){
                            body.appendChild(row);
                        });
                    });

                    body.addEventListener('click', function(event){
                        if (event.target && event.target.classList.contains('swsb-remove-slot')) {
                            event.preventDefault();
                            var row = event.target.closest('tr');
                            if (row) row.remove();
                        }
                    });
                }());
                </script>
            </section>
        </div>
        <?php
    }

    private function slot_row($index, $slot, $days) {
        ?>
        <tr>
            <td>
                <select name="swsb_slots[<?php echo esc_attr($index); ?>][day]">
                    <?php foreach ($days as $day_key => $day_label) : ?>
                        <option value="<?php echo esc_attr($day_key); ?>" <?php selected($slot['day'], $day_key); ?>><?php echo esc_html($day_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="swsb_slots[<?php echo esc_attr($index); ?>][slot]" value="<?php echo esc_attr($slot['slot']); ?>" placeholder="9:00 AM"></td>
            <td><input type="number" name="swsb_slots[<?php echo esc_attr($index); ?>][capacity]" value="<?php echo esc_attr($slot['capacity']); ?>" min="1"></td>
            <td><input type="checkbox" name="swsb_slots[<?php echo esc_attr($index); ?>][enabled]" value="yes" <?php checked($slot['enabled'], 'yes'); ?>></td>
            <td><button type="button" class="button swsb-remove-slot"><?php esc_html_e('Remove', 'smart-woocommerce-slot-booking'); ?></button></td>
        </tr>
        <?php
    }

    private function attribute_row($index, $attribute, $is_duration = false) {
        $id = !empty($attribute['id']) ? $attribute['id'] : 'attr_' . $index;
        ?>
        <tr class="swsb-attribute-row" data-attribute-id="<?php echo esc_attr($id); ?>">
            <td>
                <input type="hidden" name="swsb_attributes[<?php echo esc_attr($index); ?>][id]" class="swsb-attribute-id-input" value="<?php echo esc_attr($id); ?>">
                <input type="text" name="swsb_attributes[<?php echo esc_attr($index); ?>][name]" class="swsb-attribute-name-input" value="<?php echo esc_attr($attribute['name'] ?? ''); ?>" placeholder="Rental Duration" required style="width: 100%; max-width: 400px;">
            </td>
            <td style="text-align: center; vertical-align: middle;">
                <input type="radio" name="_swsb_duration_attribute_id" value="<?php echo esc_attr($id); ?>" class="swsb-duration-attr-radio" <?php checked($is_duration); ?>>
            </td>
            <td>
                <button type="button" class="button swsb-edit-attribute-variations" data-attribute-id="<?php echo esc_attr($id); ?>"><?php esc_html_e('Edit Variations', 'smart-woocommerce-slot-booking'); ?></button>
                <button type="button" class="button swsb-remove-attribute"><?php esc_html_e('Remove', 'smart-woocommerce-slot-booking'); ?></button>
            </td>
        </tr>
        <?php
    }

    private function variation_row($index, $variation) {
        $attr_id = $variation['attribute_id'] ?? '';
        ?>
        <tr class="swsb-variation-row" data-attribute-id="<?php echo esc_attr($attr_id); ?>">
            <td>
                <input type="hidden" name="swsb_variations[<?php echo esc_attr($index); ?>][attribute_id]" class="swsb-variation-attr-id-input" value="<?php echo esc_attr($attr_id); ?>">
                <input type="text" name="swsb_variations[<?php echo esc_attr($index); ?>][label]" value="<?php echo esc_attr($variation['label'] ?? ''); ?>" placeholder="Weekend Package" required style="width: 100%; max-width: 250px;">
            </td>
            <td class="swsb-col-duration"><input type="text" name="swsb_variations[<?php echo esc_attr($index); ?>][value]" value="<?php echo esc_attr($variation['value'] ?? ''); ?>" placeholder="2" style="width: 70px;"></td>
            <td><input type="number" name="swsb_variations[<?php echo esc_attr($index); ?>][price]" value="<?php echo esc_attr($variation['price'] ?? 0); ?>" min="0" step="0.01" required style="width: 90px;"></td>
            <td><input type="checkbox" name="swsb_variations[<?php echo esc_attr($index); ?>][enabled]" value="yes" <?php checked($variation['enabled'] ?? 'yes', 'yes'); ?>></td>
            <td><input type="number" name="swsb_variations[<?php echo esc_attr($index); ?>][sort]" value="<?php echo esc_attr($variation['sort'] ?? 0); ?>" style="width: 70px;"></td>
            <td>
                <button type="button" class="button swsb-remove-variation"><?php esc_html_e('Remove', 'smart-woocommerce-slot-booking'); ?></button>
            </td>
        </tr>
        <?php
    }

    private function weekdays() {
        return [
            'all' => __('All days', 'smart-woocommerce-slot-booking'),
            'mon' => __('Monday', 'smart-woocommerce-slot-booking'),
            'tue' => __('Tuesday', 'smart-woocommerce-slot-booking'),
            'wed' => __('Wednesday', 'smart-woocommerce-slot-booking'),
            'thu' => __('Thursday', 'smart-woocommerce-slot-booking'),
            'fri' => __('Friday', 'smart-woocommerce-slot-booking'),
            'sat' => __('Saturday', 'smart-woocommerce-slot-booking'),
            'sun' => __('Sunday', 'smart-woocommerce-slot-booking'),
        ];
    }

    private function half_hour_slot_options() {
        $slots = [];
        for ($minutes = 0; $minutes < 1440; $minutes += 30) {
            $hour = (int) floor($minutes / 60);
            $minute = $minutes % 60;
            $period = $hour >= 12 ? 'PM' : 'AM';
            $display_hour = $hour % 12;
            if (0 === $display_hour) {
                $display_hour = 12;
            }
            $slots[] = sprintf('%d:%02d %s', $display_hour, $minute, $period);
        }

        return $slots;
    }

    public function save_product_settings($product_id) {
        $fields = [
            '_swsb_button_text',
            '_swsb_duration',
            '_swsb_max_per_slot',
            '_swsb_disable_weekends',
            '_swsb_disabled_dates',
            '_swsb_buffer_days',
            '_swsb_advance_limit',
            '_swsb_deposit_type',
            '_swsb_deposit_amount',
            '_swsb_remaining_text',
            '_swsb_summary_notes',
            '_swsb_event_type',
        ];

        update_post_meta($product_id, '_swsb_enabled', isset($_POST['_swsb_enabled']) ? 'yes' : 'no');
        update_post_meta($product_id, '_swsb_deposit_enabled', isset($_POST['_swsb_deposit_enabled']) ? 'yes' : 'no');

        foreach ($fields as $field) {
            $value = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
            update_post_meta($product_id, $field, sanitize_textarea_field($value));
        }

        // Save Attributes
        $attributes = [];
        if (!empty($_POST['swsb_attributes']) && is_array($_POST['swsb_attributes'])) {
            foreach (wp_unslash($_POST['swsb_attributes']) as $attr) {
                if (empty($attr['name'])) {
                    continue;
                }
                $attributes[] = [
                    'id'   => sanitize_text_field($attr['id']),
                    'name' => sanitize_text_field($attr['name']),
                ];
            }
        }
        update_post_meta($product_id, '_swsb_attributes', $attributes);

        // Save Duration Attribute ID
        $dur_attr_id = isset($_POST['_swsb_duration_attribute_id']) ? sanitize_text_field(wp_unslash($_POST['_swsb_duration_attribute_id'])) : '';
        update_post_meta($product_id, '_swsb_duration_attribute_id', $dur_attr_id);

        // Save Variations
        $variations = [];
        if (!empty($_POST['swsb_variations']) && is_array($_POST['swsb_variations'])) {
            foreach (wp_unslash($_POST['swsb_variations']) as $var) {
                if (empty($var['label'])) {
                    continue;
                }
                $variations[] = [
                    'attribute_id' => sanitize_text_field($var['attribute_id']),
                    'label'        => sanitize_text_field($var['label']),
                    'value'        => sanitize_text_field($var['value'] ?? ''),
                    'price'        => max(0.0, floatval($var['price'] ?? 0)),
                    'enabled'      => !empty($var['enabled']) ? 'yes' : 'no',
                    'sort'         => intval($var['sort'] ?? 0),
                ];
            }
            usort($variations, function($a, $b) {
                return $a['sort'] - $b['sort'];
            });
        }
        update_post_meta($product_id, '_swsb_variations', $variations);

        $slots = [];
        if (!empty($_POST['swsb_slots']) && is_array($_POST['swsb_slots'])) {
            foreach (wp_unslash($_POST['swsb_slots']) as $slot) {
                if (empty($slot['slot'])) {
                    continue;
                }

                $slots[] = [
                    'day' => sanitize_key($slot['day'] ?? 'all'),
                    'slot' => sanitize_text_field($slot['slot']),
                    'capacity' => max(1, absint($slot['capacity'] ?? 1)),
                    'enabled' => !empty($slot['enabled']) ? 'yes' : 'no',
                ];
            }
        }

        update_post_meta($product_id, '_swsb_slots', $slots);
    }

    private function get_product_settings($product_id) {
        $attributes = get_post_meta($product_id, '_swsb_attributes', true);
        $duration_attribute_id = get_post_meta($product_id, '_swsb_duration_attribute_id', true);
        $vars = get_post_meta($product_id, '_swsb_variations', true);

        // Migration Fallback
        if (empty($attributes)) {
            $old_attr_name = get_post_meta($product_id, '_swsb_attribute_name', true) ?: get_post_meta($product_id, '_swsb_duration_attribute_name', true) ?: '';
            if (!empty($old_attr_name)) {
                $attributes = [
                    [
                        'id'   => 'attr_default',
                        'name' => $old_attr_name,
                    ]
                ];
                $duration_attribute_id = 'attr_default';
            }
        }

        if (empty($vars)) {
            $vars = get_post_meta($product_id, '_swsb_duration_variations', true);
            if (is_array($vars) && !empty($vars)) {
                foreach ($vars as &$v) {
                    $v['attribute_id'] = 'attr_default';
                    if (isset($v['days']) && !isset($v['value'])) {
                        $v['value'] = $v['days'];
                    }
                }
            }
        }

        if (empty($attributes)) {
            $attributes = [];
        }
        if (empty($vars)) {
            $vars = [];
        }
        if (empty($duration_attribute_id) && !empty($attributes)) {
            $duration_attribute_id = $attributes[0]['id'];
        }

        return [
            'enabled' => get_post_meta($product_id, '_swsb_enabled', true) ?: 'no',
            'button_text' => get_post_meta($product_id, '_swsb_button_text', true) ?: __('Book Now', 'smart-woocommerce-slot-booking'),
            'duration' => get_post_meta($product_id, '_swsb_duration', true) ?: '',
            'max_per_slot' => get_post_meta($product_id, '_swsb_max_per_slot', true) ?: '1',
            'disable_weekends' => get_post_meta($product_id, '_swsb_disable_weekends', true) ?: 'no',
            'disabled_dates' => get_post_meta($product_id, '_swsb_disabled_dates', true) ?: '',
            'buffer_days' => get_post_meta($product_id, '_swsb_buffer_days', true) ?: '0',
            'advance_limit' => get_post_meta($product_id, '_swsb_advance_limit', true) ?: '365',
            'deposit_enabled' => get_post_meta($product_id, '_swsb_deposit_enabled', true) ?: 'no',
            'deposit_type' => get_post_meta($product_id, '_swsb_deposit_type', true) ?: 'fixed',
            'deposit_amount' => get_post_meta($product_id, '_swsb_deposit_amount', true) ?: '0',
            'remaining_text' => get_post_meta($product_id, '_swsb_remaining_text', true) ?: __('Remaining balance due later', 'smart-woocommerce-slot-booking'),
            'summary_notes' => get_post_meta($product_id, '_swsb_summary_notes', true) ?: '',
            'event_type' => get_post_meta($product_id, '_swsb_event_type', true) ?: 'single',
            'attributes' => $attributes,
            'duration_attribute_id' => $duration_attribute_id,
            'variations' => $vars,
        ];
    }

    private function get_product_slots($product_id) {
        $slots = get_post_meta($product_id, '_swsb_slots', true);

        if (is_array($slots) && !empty($slots)) {
            return $slots;
        }

        return [
            ['day' => 'all', 'slot' => '7:00 AM', 'capacity' => '1', 'enabled' => 'yes'],
            ['day' => 'all', 'slot' => '7:30 AM', 'capacity' => '1', 'enabled' => 'yes'],
            ['day' => 'all', 'slot' => '8:00 AM', 'capacity' => '1', 'enabled' => 'yes'],
            ['day' => 'all', 'slot' => '8:30 AM', 'capacity' => '1', 'enabled' => 'yes'],
        ];
    }

    private function is_booking_enabled($product_id) {
        return 'yes' === get_post_meta($product_id, '_swsb_enabled', true);
    }

    public function booking_button_text($text, $product) {
        if ($product && $this->is_booking_enabled($product->get_id())) {
            return get_post_meta($product->get_id(), '_swsb_button_text', true) ?: __('Book Now', 'smart-woocommerce-slot-booking');
        }

        return $text;
    }

    public function booking_sold_individually($sold_individually, $product) {
        if ($product && $this->is_booking_enabled($product->get_id())) {
            return true;
        }

        return $sold_individually;
    }

    public function booking_add_to_cart_quantity($quantity, $product_id) {
        if ($this->is_booking_enabled($product_id)) {
            return 1;
        }

        return $quantity;
    }

    public function booking_ui() {
        global $product;

        if (!$product || !$this->is_booking_enabled($product->get_id())) {
            return;
        }

        $this->enqueue_frontend_assets();
        $this->inline_frontend_styles();

        $product_id = $product->get_id();
        $settings = $this->get_product_settings($product_id);
        $business_timezone = 'America/New_York';
        // Filter out disabled variations and keep enabled ones
        $enabled_variations = [];
        if (!empty($settings['variations'])) {
            foreach ($settings['variations'] as $v) {
                if ('yes' === ($v['enabled'] ?? 'yes')) {
                    $enabled_variations[] = $v;
                }
            }
        }
        $data = [
            'productId' => $product_id,
            'productName' => $product->get_name(),
            'price' => (float) wc_get_price_to_display($product),
            'duration' => $settings['duration'],
            'notes' => wpautop($settings['summary_notes']),
            'depositEnabled' => 'yes' === $settings['deposit_enabled'],
            'depositType' => $settings['deposit_type'],
            'depositAmount' => (float) $settings['deposit_amount'],
            'remainingText' => $settings['remaining_text'],
            'disableWeekends' => 'yes' === $settings['disable_weekends'],
            'disabledDates' => array_filter(array_map('trim', explode(',', $settings['disabled_dates']))),
            'bufferDays' => absint($settings['buffer_days']),
            'advanceLimit' => absint($settings['advance_limit']),
            'siteTimezone' => $business_timezone,
            'eventType' => $settings['event_type'],
            'attributes' => $settings['attributes'],
            'durationAttributeId' => $settings['duration_attribute_id'],
            'variations' => $enabled_variations,
        ];
        ?>
        <div class="swsb-booking" data-swsb='<?php echo esc_attr(wp_json_encode($data)); ?>' style="background:#fbfbf7;border:1px solid #ecece5;border-radius:8px;margin:24px 0;padding:22px;color:#151711;box-sizing:border-box;width:100%;max-width:100%;">
            <span class="swsb-version-badge" style="display:inline-flex;margin-bottom:10px;padding:4px 9px;border-radius:999px;background:#0b8f5a;color:#fff;font-size:11px;font-weight:700;letter-spacing:.02em;text-transform:uppercase;">WooBookify v<?php echo esc_html(WBDS_VERSION); ?></span>
            <div class="swsb-heading" style="border-bottom:1px solid #d9d9d2;margin-bottom:18px;padding-bottom:10px;">
                <h3 style="margin:0 0 6px;color:#151711;font-size:24px;font-weight:700;line-height:1.2;"><?php esc_html_e('Select a Date and Time', 'smart-woocommerce-slot-booking'); ?></h3>
                <div class="swsb-timezone" style="font-size:14px;color:#31342d;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <label for="swsb-timezone-select" style="font-weight:500;"><?php esc_html_e('Time zone:', 'smart-woocommerce-slot-booking'); ?></label>
                    <select id="swsb-timezone-select" data-swsb-timezone-select name="swsb_booking_timezone" style="border:0;background:transparent;color:#151711;font-weight:500;max-width:340px;padding:3px 22px 3px 0;">
                        <option value="<?php echo esc_attr($business_timezone); ?>" selected><?php echo esc_html($this->timezone_display_name($business_timezone)); ?></option>
                        <option value="" data-swsb-local-timezone-option><?php esc_html_e('Your local time: detecting...', 'smart-woocommerce-slot-booking'); ?></option>
                    </select>
                </div>
            </div>

            <div class="swsb-layout" style="display:grid;grid-template-columns:minmax(240px,1fr);gap:24px;">
                <div class="swsb-calendar-panel" style="min-width:0;">
                    <?php if ('multi' === $settings['event_type'] && !empty($settings['attributes']) && !empty($enabled_variations)) : ?>
                        <?php foreach ($settings['attributes'] as $attr) : ?>
                            <?php
                            $attr_vars = [];
                            foreach ($enabled_variations as $v) {
                                if ($v['attribute_id'] === $attr['id']) {
                                    $attr_vars[] = $v;
                                }
                            }
                            if (empty($attr_vars)) {
                                continue;
                            }
                            $is_duration_attr = ($attr['id'] === $settings['duration_attribute_id']);
                            ?>
                            <div class="swsb-days-needed-section" data-swsb-attribute-id="<?php echo esc_attr($attr['id']); ?>" style="margin-bottom: 24px;">
                                <h4 style="margin: 0 0 12px; font-size: 17px; font-weight: 600; color: #151711;"><?php echo esc_html($attr['name']); ?></h4>
                                <div class="swsb-days-needed-options" style="display: flex; gap: 12px; flex-wrap: wrap;">
                                    <?php foreach ($attr_vars as $index => $v) : ?>
                                        <?php $is_first = (0 === $index); ?>
                                        <?php
                                        // Only output data-swsb-days if this is the designated duration attribute and has a value
                                        $days_count = '';
                                        if ($is_duration_attr && !empty($v['value']) && is_numeric($v['value'])) {
                                            $days_count = max(1, intval($v['value']));
                                        }
                                        ?>
                                        <label class="swsb-choice swsb-day-choice <?php echo $is_first ? 'is-selected' : ''; ?>" <?php echo $days_count !== '' ? 'data-swsb-days="' . esc_attr($days_count) . '"' : ''; ?> data-swsb-variation-label="<?php echo esc_attr($v['label']); ?>" style="flex: 1; min-width: 120px; position: relative; min-height: 48px; display: flex; align-items: center; justify-content: center; border: 2px solid <?php echo $is_first ? '#0b8f5a' : '#d5d5ce'; ?>; border-radius: 10px; background: <?php echo $is_first ? 'linear-gradient(135deg, #0b8f5a, #08784c)' : '#fff'; ?>; color: <?php echo $is_first ? '#fff' : '#0b8f5a'; ?>; cursor: pointer; font-weight: 800; box-shadow: <?php echo $is_first ? '0 14px 32px rgba(11,143,90,.34), 0 0 0 4px rgba(223,255,47,.58)' : '0 2px 8px rgba(0,0,0,.04)'; ?>; transition: all 0.18s ease; text-align: center; padding: 4px 8px;">
                                            <input type="radio" name="swsb_attribute_<?php echo esc_attr($attr['id']); ?>" value="<?php echo esc_attr($index); ?>" <?php checked($is_first); ?> data-swsb-attribute-id="<?php echo esc_attr($attr['id']); ?>" data-swsb-variation-index="<?php echo esc_attr($index); ?>" style="position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0.001; cursor: pointer; margin: 0; z-index: 2;">
                                            <span style="position: relative; z-index: 1; pointer-events: none;"><?php echo esc_html($v['label']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div class="swsb-validation-notice" style="display:none;margin-bottom:14px;padding:12px;border:1px solid #cc0000;background:#fff2f2;color:#cc0000;border-radius:8px;font-weight:600;font-size:14px;"></div>
                    <?php $this->render_server_calendar($settings); ?>
                </div>

                <div class="swsb-time-panel" style="min-width:0;">
                    <h4 data-swsb-availability-title style="margin:0 0 14px;color:#151711;font-size:17px;font-weight:600;"><?php esc_html_e('Select Time Slot', 'smart-woocommerce-slot-booking'); ?></h4>
                    <?php $this->render_server_slots($product_id); ?>
                </div>

                <aside class="swsb-summary" style="border-top:1px solid #d9d9d2;padding-top:18px;">
                    <h3 style="margin:0 0 14px;color:#151711;font-size:24px;font-weight:800;line-height:1.2;"><?php esc_html_e('Booking Summary', 'smart-woocommerce-slot-booking'); ?></h3>
                    <div style="border:1px solid #e0e4dc;border-radius:12px;background:#fff;padding:16px;box-shadow:0 8px 24px rgba(0,0,0,.04);">
                    <p class="swsb-product-name" style="margin:0 0 14px;font-size:18px;font-weight:800;color:#0b8f5a;"><?php echo esc_html($product->get_name()); ?></p>
                    <div style="display:grid;gap:10px;">
                        <?php if ('multi' === $settings['event_type'] && !empty($settings['attributes'])) : ?>
                            <?php foreach ($settings['attributes'] as $attr) : ?>
                                <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;">
                                    <strong><span><?php echo esc_html($attr['name']); ?></span></strong>
                                    <span data-swsb-summary-attribute-val="<?php echo esc_attr($attr['id']); ?>" style="text-align:right;">-</span>
                                </div>
                            <?php endforeach; ?>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Start Date', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-start-date style="text-align:right;"><?php esc_html_e('Choose from calendar', 'smart-woocommerce-slot-booking'); ?></span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('End Date', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-end-date style="text-align:right;"><?php esc_html_e('Choose from calendar', 'smart-woocommerce-slot-booking'); ?></span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Selected Dates', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-selected-dates style="text-align:right;"><?php esc_html_e('Choose from calendar', 'smart-woocommerce-slot-booking'); ?></span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Start Time', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-start-time style="text-align:right;"><?php esc_html_e('Choose a slot', 'smart-woocommerce-slot-booking'); ?></span></div>
                        <?php else : ?>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Date', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-date style="text-align:right;"><?php esc_html_e('Choose from calendar', 'smart-woocommerce-slot-booking'); ?></span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Time', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-time style="text-align:right;"><?php esc_html_e('Choose a slot', 'smart-woocommerce-slot-booking'); ?></span></div>
                        <?php endif; ?>
                        <?php if ($settings['duration']) : ?>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('End Time', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-end-time style="text-align:right;">-</span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #eef0eb;padding-bottom:8px;"><strong><?php esc_html_e('Duration', 'smart-woocommerce-slot-booking'); ?></strong> <span><?php echo esc_html($settings['duration']); ?></span></div>
                        <?php endif; ?>
                        <div style="display:flex;justify-content:space-between;gap:12px;font-size:18px;"><strong><?php esc_html_e('Total', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-price style="font-weight:900;"><?php echo wp_kses_post(wc_price(wc_get_price_to_display($product))); ?></span></div>
                    </div>
                    <?php if ('yes' === $settings['deposit_enabled']) : ?>
                        <div class="swsb-payment-options" style="display:grid;gap:10px;margin:16px 0 12px;">
                            <label class="swsb-payment-card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0;border:2px solid #0b8f5a;border-radius:10px;padding:11px 12px;background:#f4fff1;cursor:pointer;"><span><input type="radio" name="swsb_payment_type" value="full" checked> <?php esc_html_e('Pay Full Amount', 'smart-woocommerce-slot-booking'); ?></span><strong data-swsb-full-amount></strong></label>
                            <label class="swsb-payment-card" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin:0;border:2px solid #d6dcd2;border-radius:10px;padding:11px 12px;background:#fff;cursor:pointer;"><span><input type="radio" name="swsb_payment_type" value="deposit"> <?php esc_html_e('Pay Deposit Only', 'smart-woocommerce-slot-booking'); ?></span><strong data-swsb-deposit-option></strong></label>
                        </div>
                        <div style="display:grid;gap:10px;border-top:1px solid #eef0eb;padding-top:12px;">
                            <div style="display:flex;justify-content:space-between;gap:12px;"><strong><?php esc_html_e('Due Today', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-deposit style="font-weight:700;color:#0b8f5a;">-</span></div>
                            <div style="display:flex;justify-content:space-between;gap:12px;"><strong><?php esc_html_e('Remaining Balance', 'smart-woocommerce-slot-booking'); ?></strong> <span data-swsb-summary-remaining style="font-weight:700;">-</span></div>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($settings['summary_notes'])) : ?>
                        <div class="swsb-summary-notes" style="margin-top:14px;padding:12px;border-radius:10px;background:#f8f9f6;font-size:14px;line-height:1.4;color:#60645c;"><?php echo wp_kses_post(wpautop($settings['summary_notes'])); ?></div>
                    <?php endif; ?>
                    </div>
                </aside>

            </div>
        </div>
        <?php
        $this->inline_frontend_script();
        $this->inline_direct_interaction_script($product_id);
    }

    private function inline_direct_interaction_script($product_id) {
        $business_timezone = 'America/New_York';
        $business_now = new DateTime('now', new DateTimeZone($business_timezone));
        $business_today = $business_now->format('Y-m-d');
        $business_now_minutes = ((int) $business_now->format('G') * 60) + (int) $business_now->format('i');
        ?>
        <script>
        (function(){
            var booking = document.currentScript;
            while (booking && !booking.classList?.contains('swsb-booking')) {
                booking = booking.previousElementSibling;
            }
            if (!booking) {
                booking = document.querySelector('.swsb-booking[data-swsb]');
            }
            if (!booking) return;

                        var form = booking.closest('form.cart') || booking.closest('form') || document;
            var dateCards = Array.prototype.slice.call(booking.querySelectorAll('[data-swsb-date-label]'));
            var slotCards = Array.prototype.slice.call(booking.querySelectorAll('[data-swsb-slot-label]'));
            var dayButtons = Array.prototype.slice.call(booking.querySelectorAll('[data-swsb-days]'));
            var validationNotice = booking.querySelector('.swsb-validation-notice');
            var qty = form.querySelector('input.qty');
            var price = <?php echo wp_json_encode((float) wc_get_price_to_display(wc_get_product($product_id))); ?>;
            var currency = <?php echo wp_json_encode(function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$'); ?>;
            var timezoneSelect = form.querySelector('[data-swsb-timezone-select]');
            var localTimezoneOption = timezoneSelect ? timezoneSelect.querySelector('[data-swsb-local-timezone-option]') : null;
            var depositEnabled = <?php echo wp_json_encode('yes' === get_post_meta($product_id, '_swsb_deposit_enabled', true)); ?>;
            var depositType = <?php echo wp_json_encode(get_post_meta($product_id, '_swsb_deposit_type', true) ?: 'fixed'); ?>;
            var depositAmountSetting = <?php echo wp_json_encode((float) (get_post_meta($product_id, '_swsb_deposit_amount', true) ?: 0)); ?>;
                    if (input && input.disabled) return;
                    if (input) input.checked = true;
                    refresh();
                }, true);
                if (input) {
                    input.addEventListener('change', refresh);
                    input.addEventListener('click', refresh);
                }
            });

            dayButtons.forEach(function (button) {
                var input = button.querySelector('input[name="swsb_days_needed"]');
                function chooseDays() {
                    refresh();
                }
                button.addEventListener('click', function() {
                    if (input) input.checked = true;
                    chooseDays();
                });
                if (input) {
                    input.addEventListener('change', chooseDays);
                }
            });

            if (qty) {
                qty.addEventListener('input', refresh);
                qty.addEventListener('change', refresh);
            }

            Array.prototype.slice.call(form.querySelectorAll('input[name="swsb_payment_type"]')).forEach(function(input){
                input.addEventListener('change', refresh);
            });
var prevMonth = booking.querySelector('[data-swsb-prev-month]');
            var nextMonth = booking.querySelector('[data-swsb-next-month]');
            if (prevMonth) prevMonth.addEventListener('click', function(){ showMonth(currentMonth - 1); });
            if (nextMonth) nextMonth.addEventListener('click', function(){ showMonth(currentMonth + 1); });

            var timezone = '';
            try { timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch(e) {}
            if (timezoneSelect && timezone) {
                if (localTimezoneOption) {
                    localTimezoneOption.value = timezone;
                    localTimezoneOption.textContent = localTimeLabel(timezone);
                }
                timezoneSelect.addEventListener('change', refresh);
            }

            syncQuantityControls();
            showMonth(0);
            refresh();
        }());
        </script>
        <?php
    }

    private function timezone_display_name($timezone) {
        try {
            $date = new DateTime('now', new DateTimeZone($timezone));
            $abbr = $date->format('T');

            if ('America/New_York' === $timezone) {
                $is_dst = '1' === $date->format('I');
                return ($is_dst ? 'Eastern Daylight Time' : 'Eastern Standard Time') . ' (' . $abbr . ')';
            }

            return str_replace('_', ' ', $timezone) . ' (' . $abbr . ')';
        } catch (Exception $e) {
            return $timezone;
        }
    }

    private function render_server_calendar($settings) {
        $today = strtotime(current_time('Y-m-d'));
        $disabled_dates = array_filter(array_map('trim', explode(',', $settings['disabled_dates'])));
        $min = strtotime('today +' . absint($settings['buffer_days']) . ' days');
        $max = strtotime('today +' . absint($settings['advance_limit']) . ' days');
        $months_to_render = 6;

        echo '<div class="swsb-calendar-nav" style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;">';
        echo '<button type="button" data-swsb-prev-month style="width:38px;height:38px;border:1px solid #d6dcd2;border-radius:999px;background:#fff;color:#151711;font-size:22px;font-weight:900;cursor:pointer;">&lsaquo;</button>';
        echo '<strong data-swsb-visible-month style="font-size:18px;"></strong>';
        echo '<button type="button" data-swsb-next-month style="width:38px;height:38px;border:1px solid #d6dcd2;border-radius:999px;background:#fff;color:#151711;font-size:22px;font-weight:900;cursor:pointer;">&rsaquo;</button>';
        echo '</div>';

        for ($month_offset = 0; $month_offset < $months_to_render; $month_offset++) {
            $first_day = strtotime('first day of +' . $month_offset . ' month', $today);
            $year = (int) gmdate('Y', $first_day);
            $month = (int) gmdate('n', $first_day);
            $days_in_month = (int) gmdate('t', $first_day);
            $start_weekday = (int) gmdate('w', $first_day);

            echo '<div class="swsb-month-panel" data-swsb-month-index="' . esc_attr($month_offset) . '" data-swsb-month-title="' . esc_attr(gmdate('F Y', $first_day)) . '" style="' . ($month_offset ? 'display:none;' : '') . '">';
            echo '<div style="display:grid;grid-template-columns:repeat(7,minmax(30px,1fr));gap:6px;margin-bottom:8px;text-align:center;font-size:13px;font-weight:700;color:#31342d;">';
            foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $day_label) {
                echo '<span>' . esc_html($day_label) . '</span>';
            }
            echo '</div>';
            echo '<div style="display:grid;grid-template-columns:repeat(7,minmax(30px,1fr));gap:8px;">';

            for ($blank = 0; $blank < $start_weekday; $blank++) {
                echo '<span></span>';
            }

            for ($day = 1; $day <= $days_in_month; $day++) {
                $date_value = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $timestamp = strtotime($date_value);
                $disabled = $timestamp < $min || $timestamp > $max || in_array($date_value, $disabled_dates, true);

                if ('yes' === $settings['disable_weekends'] && in_array((int) gmdate('w', $timestamp), [0, 6], true)) {
                    $disabled = true;
                }

                if ($disabled) {
                    echo '<span style="min-height:42px;display:flex;align-items:center;justify-content:center;border-radius:9px;color:#b8b8b8;background:#f4f4f1;">' . esc_html($day) . '</span>';
                    continue;
                }

                echo '<label class="swsb-choice swsb-date-choice" data-swsb-date-label="' . esc_attr($date_value) . '" style="position:relative;min-height:48px;width:100%;display:flex;align-items:center;justify-content:center;border:2px solid #d5d5ce;border-radius:10px;background:#fff;cursor:pointer;font-weight:800;color:#0b8f5a;box-shadow:0 2px 8px rgba(0,0,0,.04);transition:background .18s ease,border-color .18s ease,box-shadow .18s ease,transform .18s ease,color .18s ease;">';
                echo '<input required type="radio" name="swsb_booking_date" value="' . esc_attr($date_value) . '" style="position:absolute;inset:0;width:100%;height:100%;opacity:.001;cursor:pointer;margin:0;z-index:2;">';
                echo '<span style="position:relative;z-index:1;pointer-events:none;">' . esc_html($day) . '</span>';
                echo '</label>';
            }

            echo '</div></div>';
        }
    }

    private function render_server_slots($product_id) {
        $seen = [];

        echo '<div class="swsb-slots" data-swsb-slots style="display:grid;grid-template-columns:repeat(2,minmax(110px,1fr));gap:10px;">';
        foreach ($this->get_product_slots($product_id) as $slot) {
            if ('yes' !== $slot['enabled']) {
                continue;
            }

            $label = $this->format_slot_label($slot['slot']);
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            echo '<label class="swsb-choice swsb-slot-choice" data-swsb-slot-label="' . esc_attr($label) . '" style="position:relative;min-height:56px;width:100%;display:flex;align-items:center;justify-content:center;text-align:center;border:2px solid #8f948b;border-radius:12px;background:#fff;color:#151711;cursor:pointer;font-weight:800;box-shadow:0 2px 10px rgba(0,0,0,.05);transition:background .18s ease,border-color .18s ease,box-shadow .18s ease,transform .18s ease,color .18s ease;">';
            echo '<input required type="radio" name="swsb_booking_time" value="' . esc_attr($label) . '" style="position:absolute;inset:0;width:100%;height:100%;opacity:.001;cursor:pointer;margin:0;z-index:2;">';
            echo '<span data-swsb-slot-text style="position:relative;z-index:1;pointer-events:none;">' . esc_html($label) . '</span>';
            echo '</label>';
        }

        if (!$seen) {
            echo '<p style="grid-column:1/-1;margin:0;color:#71756d;">' . esc_html__('No time slots configured yet.', 'smart-woocommerce-slot-booking') . '</p>';
        }

        echo '</div>';
    }

    public function ajax_get_slots() {
        check_ajax_referer('swsb_frontend_nonce', 'nonce');

        $product_id = absint($_POST['product_id'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));

        if (!$product_id || !$date || !$this->is_booking_enabled($product_id)) {
            wp_send_json_error(['message' => __('Invalid booking request.', 'smart-woocommerce-slot-booking')]);
        }

        $slots = $this->available_slots_for_date($product_id, $date);
        wp_send_json_success(['slots' => $slots]);
    }

    private function available_slots_for_date($product_id, $date) {
        $timestamp = strtotime($date);
        $day_key = strtolower(gmdate('D', $timestamp));
        $slots = [];

        foreach ($this->get_product_slots($product_id) as $slot) {
            if ('yes' !== $slot['enabled']) {
                continue;
            }

            if (!in_array($slot['day'], ['all', $day_key], true)) {
                continue;
            }

            $label = $this->format_slot_label($slot['slot']);
            if ($this->is_past_business_slot($date, $label)) {
                continue;
            }

            $capacity = max(1, absint($slot['capacity']));
            $booked = $this->booked_quantity($product_id, $date, $label);

            if ($booked >= $capacity) {
                continue;
            }

            $slots[] = [
                'label' => $label,
                'capacity' => $capacity,
                'remaining' => max(0, $capacity - $booked),
            ];
        }

        return $slots;
    }

    private function booked_quantity($product_id, $date, $slot) {
        global $wpdb;

        $table = self::bookings_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(quantity), 0) FROM {$table} WHERE product_id = %d AND booking_date = %s AND booking_slot = %s AND status NOT IN ('cancelled', 'refunded')",
            $product_id,
            $date,
            $slot
        ));
    }

    private function format_slot_label($slot) {
        if ('24' === get_option('swsb_time_format', '12')) {
            $timestamp = strtotime($slot);
            return $timestamp ? gmdate('H:i', $timestamp) : $slot;
        }

        $timestamp = strtotime($slot);
        return $timestamp ? gmdate('g:i A', $timestamp) : $slot;
    }

    private function slot_minutes($slot) {
        $slot = trim((string) $slot);

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM)$/i', $slot, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
            $period = strtoupper($matches[3]);

            if ('AM' === $period && 12 === $hour) {
                $hour = 0;
            }

            if ('PM' === $period && 12 !== $hour) {
                $hour += 12;
            }

            return ($hour * 60) + $minute;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $slot, $matches)) {
            return ((int) $matches[1] * 60) + (int) $matches[2];
        }

        return null;
    }

    private function is_past_business_slot($date, $slot) {
        $timezone = new DateTimeZone('America/New_York');
        $now = new DateTime('now', $timezone);

        if ($date !== $now->format('Y-m-d')) {
            return false;
        }

        $slot_minutes = $this->slot_minutes($slot);

        if (null === $slot_minutes) {
            return false;
        }

        $now_minutes = ((int) $now->format('G') * 60) + (int) $now->format('i');

        return $slot_minutes <= $now_minutes;
    }

    public function validate_booking($passed, $product_id, $quantity, $variation_id = 0) {
        if (!$this->is_booking_enabled($product_id)) {
            return $passed;
        }

        // For variable products, verify that a variation has been selected
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variable')) {
            if (!$variation_id) {
                wc_add_notice(__('Please select a product variation.', 'smart-woocommerce-slot-booking'), 'error');
                return false;
            }
        }

        $quantity = 1;
        $date = sanitize_text_field(wp_unslash($_POST['swsb_booking_date'] ?? ''));
        $slot = sanitize_text_field(wp_unslash($_POST['swsb_booking_time'] ?? ''));

        if (!$date) {
            wc_add_notice(__('Please select a booking date.', 'smart-woocommerce-slot-booking'), 'error');
            return false;
        }

        if (!$slot) {
            wc_add_notice(__('Please select a booking time.', 'smart-woocommerce-slot-booking'), 'error');
            return false;
        }

        $settings = $this->get_product_settings($product_id);
        $days = 1;
        if ('multi' === $settings['event_type'] && !empty($settings['attributes'])) {
            $duration_attr_id = $settings['duration_attribute_id'] ?? '';
            foreach ($settings['attributes'] as $attr) {
                if ($attr['id'] === $duration_attr_id) {
                    $input_name = 'swsb_attribute_' . $attr['id'];
                    $var_index = isset($_POST[$input_name]) ? absint($_POST[$input_name]) : 0;
                    $attr_vars = [];
                    if (!empty($settings['variations'])) {
                        foreach ($settings['variations'] as $v) {
                            if ($v['attribute_id'] === $attr['id'] && 'yes' === ($v['enabled'] ?? 'yes')) {
                                $attr_vars[] = $v;
                            }
                        }
                    }
                    $matching_var = isset($attr_vars[$var_index]) ? $attr_vars[$var_index] : null;
                    if ($matching_var && !empty($matching_var['value']) && is_numeric($matching_var['value'])) {
                        $days = max(1, intval($matching_var['value']));
                    }
                    break;
                }
            }
        }

        $consecutive_dates = [];
        $start_time = strtotime($date);
        for ($i = 0; $i < $days; $i++) {
            $consecutive_dates[] = gmdate('Y-m-d', strtotime("+$i day", $start_time));
        }

        $min = strtotime('today +' . absint($settings['buffer_days']) . ' days');
        $max = strtotime('today +' . absint($settings['advance_limit']) . ' days');
        $disabled_dates = array_filter(array_map('trim', explode(',', $settings['disabled_dates'])));

        foreach ($consecutive_dates as $d) {
            $date_time = strtotime($d);
            if (!$date_time || $date_time < $min || $date_time > $max || in_array($d, $disabled_dates, true)) {
                wc_add_notice(__('Selected rental period is unavailable. Please choose another start date.', 'smart-woocommerce-slot-booking'), 'error');
                return false;
            }

            if ('yes' === $settings['disable_weekends'] && in_array((int) gmdate('w', $date_time), [0, 6], true)) {
                wc_add_notice(__('Selected rental period is unavailable. Please choose another start date.', 'smart-woocommerce-slot-booking'), 'error');
                return false;
            }

            if ($this->is_past_business_slot($d, $slot)) {
                wc_add_notice(__('Selected rental period is unavailable. Please choose another start date.', 'smart-woocommerce-slot-booking'), 'error');
                return false;
            }

            $slots = $this->available_slots_for_date($product_id, $d);
            $matching_slot = null;

            foreach ($slots as $available_slot) {
                if ($available_slot['label'] === $slot) {
                    $matching_slot = $available_slot;
                    break;
                }
            }

            if (!$matching_slot || $quantity > $matching_slot['remaining']) {
                wc_add_notice(__('Selected rental period is unavailable. Please choose another start date.', 'smart-woocommerce-slot-booking'), 'error');
                return false;
            }
        }

        return $passed;
    }

    public function save_cart_data($cart_item_data, $product_id, $variation_id = 0) {
        if (!$this->is_booking_enabled($product_id)) {
            return $cart_item_data;
        }

        $settings = $this->get_product_settings($product_id);
        
        // Retrieve variation or parent product price and properties
        $target_product_id = ($variation_id > 0) ? $variation_id : $product_id;
        $product = wc_get_product($target_product_id);
        $price = $product ? (float) $product->get_price() : 0;

        $payment_type = sanitize_key(wp_unslash($_POST['swsb_payment_type'] ?? 'full'));
        if (!in_array($payment_type, ['full', 'deposit'], true)) {
            $payment_type = 'full';
        }

        $timezone = sanitize_text_field(wp_unslash($_POST['swsb_booking_timezone'] ?? 'America/New_York'));
        if (!$timezone || !in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'America/New_York';
        }

        $days = 1;
        $total_rental_amount = $price;
        $selected_variations_summary = [];

        if ('multi' === $settings['event_type'] && !empty($settings['attributes'])) {
            $sum_days = 0;
            $has_numeric_val = false;
            $sum_price = 0;
            $duration_attr_id = $settings['duration_attribute_id'] ?? '';

            foreach ($settings['attributes'] as $attr) {
                $input_name = 'swsb_attribute_' . $attr['id'];
                $var_index = isset($_POST[$input_name]) ? absint($_POST[$input_name]) : 0;
                
                $attr_vars = [];
                if (!empty($settings['variations'])) {
                    foreach ($settings['variations'] as $v) {
                        if ($v['attribute_id'] === $attr['id'] && 'yes' === ($v['enabled'] ?? 'yes')) {
                            $attr_vars[] = $v;
                        }
                    }
                }
                
                $matching_var = isset($attr_vars[$var_index]) ? $attr_vars[$var_index] : null;
                if ($matching_var) {
                    if ($attr['id'] === $duration_attr_id && !empty($matching_var['value']) && is_numeric($matching_var['value'])) {
                        $sum_days = intval($matching_var['value']);
                        $has_numeric_val = true;
                    }
                    $sum_price += floatval($matching_var['price']);
                    $selected_variations_summary[] = $attr['name'] . ': ' . $matching_var['label'];
                }
            }
            
            $days = $has_numeric_val ? max(1, $sum_days) : 1;
            if ($sum_price > 0) {
                $total_rental_amount = $sum_price;
            }
        }
        
        $rental_duration_label = !empty($selected_variations_summary) ? implode(', ', $selected_variations_summary) : '1 Day';

        $deposit = 0;
        if ('yes' === $settings['deposit_enabled']) {
            $deposit = $total_rental_amount / 2;
        } else {
            $deposit = $total_rental_amount;
        }

        $remaining = 'deposit' === $payment_type ? max(0, $total_rental_amount - $deposit) : 0;
        $start_date = sanitize_text_field(wp_unslash($_POST['swsb_booking_date'] ?? ''));

        $consecutive_dates = [];
        $start_time = strtotime($start_date);
        for ($i = 0; $i < $days; $i++) {
            $consecutive_dates[] = gmdate('Y-m-d', strtotime("+$i day", $start_time));
        }

        // Capture Variation Metadata
        $variation_name = '';
        $variation_attributes_str = '';
        if ($variation_id > 0 && $product) {
            $variation_name = $product->get_name();
            // Format selected attributes
            $attributes = $product->get_attributes();
            $formatted_attributes = [];
            foreach ($attributes as $key => $val) {
                $label = wc_attribute_label($key);
                $formatted_attributes[] = $label . ': ' . $val;
            }
            $variation_attributes_str = implode(', ', $formatted_attributes);
        }

        $cart_item_data['swsb_booking'] = [
            'attribute_name' => __('Selected Options', 'smart-woocommerce-slot-booking'),
            'date' => $start_date,
            'slot' => sanitize_text_field(wp_unslash($_POST['swsb_booking_time'] ?? '')),
            'duration' => $settings['duration'],
            'payment_type' => $payment_type,
            'deposit_amount' => $deposit,
            'remaining_amount' => $remaining,
            'notes' => $settings['summary_notes'],
            'timezone' => $timezone,
            'original_price' => $price,
            'rental_duration' => $rental_duration_label,
            'start_date' => $start_date,
            'end_date' => end($consecutive_dates),
            'selected_dates' => implode(', ', $consecutive_dates),
            'daily_rate' => $price,
            'total_rental_amount' => $total_rental_amount,
            'variation_id' => $variation_id,
            'variation_name' => $variation_name,
            'variation_attributes' => $variation_attributes_str,
            'variation_price' => $price,
        ];
        $cart_item_data['swsb_unique_key'] = md5(wp_json_encode($cart_item_data['swsb_booking']) . microtime());

        return $cart_item_data;
    }

    public function display_cart_data($item_data, $cart_item) {
        if (empty($cart_item['swsb_booking'])) {
            return $item_data;
        }

        $booking = $cart_item['swsb_booking'];
        $product_id = $cart_item['product_id'];
        $settings = $this->get_product_settings($product_id);
        $is_multi = 'multi' === $settings['event_type'];
        
        $payment_type = isset($booking['payment_type']) && in_array($booking['payment_type'], ['full', 'deposit'], true) ? $booking['payment_type'] : 'full';
        $timezone = !empty($booking['timezone']) ? $booking['timezone'] : 'America/New_York';

        if ($is_multi) {
            $attr_label = !empty($booking['attribute_name']) ? $booking['attribute_name'] : __('Rental Duration', 'smart-woocommerce-slot-booking');
            $item_data[] = ['key' => $attr_label, 'value' => esc_html($booking['rental_duration'] ?? '')];
            $item_data[] = ['key' => __('Start Date', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['start_date'] ?? '')];
            $item_data[] = ['key' => __('End Date', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['end_date'] ?? '')];
            $item_data[] = ['key' => __('Selected Dates', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['selected_dates'] ?? '')];
            $item_data[] = ['key' => __('Booking Time', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['slot'] ?? '')];
        } else {
            $item_data[] = ['key' => __('Booking Date', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['date'] ?? '')];
            $item_data[] = ['key' => __('Booking Time', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['slot'] ?? '')];
        }

        if (!empty($booking['duration'])) {
            $item_data[] = ['key' => __('Duration', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['duration'])];
        }

        // Variation details
        if (!empty($booking['variation_id'])) {
            $item_data[] = ['key' => __('Variation ID', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['variation_id'])];
            $item_data[] = ['key' => __('Variation Name', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['variation_name'])];
            if (!empty($booking['variation_attributes'])) {
                $item_data[] = ['key' => __('Selected Attributes', 'smart-woocommerce-slot-booking'), 'value' => esc_html($booking['variation_attributes'])];
            }
            $item_data[] = ['key' => __('Variation Price', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['variation_price'])];
        }

        if ($is_multi) {
            $item_data[] = ['key' => __('Daily Rate', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['daily_rate'])];
            $item_data[] = ['key' => __('Total Rental Amount', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['total_rental_amount'])];
        } else {
            $item_data[] = ['key' => __('Total Amount', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['total_rental_amount'] ?? $booking['original_price'])];
        }

        $item_data[] = ['key' => __('Payment Type', 'smart-woocommerce-slot-booking'), 'value' => 'deposit' === $payment_type ? __('Deposit only', 'smart-woocommerce-slot-booking') : __('Full amount', 'smart-woocommerce-slot-booking')];

        if ('deposit' === $payment_type) {
            $item_data[] = ['key' => __('Deposit Amount Today', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['deposit_amount'])];
            $item_data[] = ['key' => __('Remaining Amount', 'smart-woocommerce-slot-booking'), 'value' => wc_price($booking['remaining_amount'])];
        }

        if (!empty($timezone)) {
            $item_data[] = ['key' => __('Customer Timezone', 'smart-woocommerce-slot-booking'), 'value' => esc_html($this->timezone_display_name($timezone))];
        }

        if (!empty($booking['notes'])) {
            $item_data[] = ['key' => __('Booking Notes', 'smart-woocommerce-slot-booking'), 'value' => wp_kses_post(nl2br($booking['notes']))];
        }

        return $item_data;
    }

    public function apply_deposit_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item['swsb_booking'])) {
                continue;
            }

            $booking = $cart_item['swsb_booking'];
            if ('deposit' === $booking['payment_type']) {
                $cart_item['data']->set_price((float) $booking['deposit_amount']);
            } else {
                $cart_item['data']->set_price((float) $booking['total_rental_amount']);
            }
        }
    }

    private function calculate_deposit($price, $settings) {
        if ('yes' !== $settings['deposit_enabled']) {
            return 0;
        }

        $amount = (float) $settings['deposit_amount'];
        if ('percent' === $settings['deposit_type']) {
            return round($price * ($amount / 100), wc_get_price_decimals());
        }

        return min($price, $amount);
    }
    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['swsb_booking'])) {
            return;
        }

        $booking = $values['swsb_booking'];
        $quantity = isset($values['quantity']) ? absint($values['quantity']) : 1;
        $item->add_meta_data('booking_date', $booking['date']);
        $item->add_meta_data('booking_slot', $booking['slot']);
        $item->add_meta_data('booking_duration', $booking['duration']);
        $item->add_meta_data('booking_quantity', $quantity);
        $item->add_meta_data('booking_payment_type', $booking['payment_type']);
        $item->add_meta_data('booking_timezone', $booking['timezone']);
        $item->add_meta_data('booking_deposit_amount', wc_format_decimal($booking['deposit_amount']));
        $item->add_meta_data('booking_remaining_amount', wc_format_decimal($booking['remaining_amount'] * $quantity));
        $item->add_meta_data('booking_notes', $booking['notes']);

        // Add range rental metadata
        $item->add_meta_data('booking_attribute_name', $booking['attribute_name'] ?? '');
        $item->add_meta_data('booking_rental_duration', $booking['rental_duration'] ?? '');
        $item->add_meta_data('booking_start_date', $booking['start_date'] ?? '');
        $item->add_meta_data('booking_end_date', $booking['end_date'] ?? '');
        $item->add_meta_data('booking_selected_dates', $booking['selected_dates'] ?? '');
        $item->add_meta_data('booking_daily_rate', wc_format_decimal($booking['daily_rate'] ?? 0));
        $item->add_meta_data('booking_total_rental_amount', wc_format_decimal($booking['total_rental_amount'] ?? 0));

        // Add variation metadata
        if (!empty($booking['variation_id'])) {
            $item->add_meta_data('booking_variation_id', $booking['variation_id']);
            $item->add_meta_data('booking_variation_name', $booking['variation_name']);
            $item->add_meta_data('booking_selected_attributes', $booking['variation_attributes']);
            $item->add_meta_data('booking_variation_price', wc_format_decimal($booking['variation_price']));
        }
    }


    public function create_booking_records($order_id, $posted_data, $order) {
        global $wpdb;

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }

        if (!$order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $date = $item->get_meta('booking_date');
            $slot = $item->get_meta('booking_slot');

            if (!$date || !$slot) {
                continue;
            }

            $selected_dates_str = $item->get_meta('booking_selected_dates');
            $dates = [];
            if ($selected_dates_str) {
                $dates = array_filter(array_map('trim', explode(',', $selected_dates_str)));
            }
            if (empty($dates)) {
                $dates[] = $date;
            }

            foreach ($dates as $single_date) {
                $wpdb->insert(self::bookings_table(), [
                    'order_id' => $order_id,
                    'product_id' => $item->get_product_id(),
                    'user_id' => $order->get_user_id(),
                    'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'customer_email' => $order->get_billing_email(),
                    'customer_phone' => $order->get_billing_phone(),
                    'booking_date' => $single_date,
                    'booking_slot' => $slot,
                    'quantity' => absint($item->get_meta('booking_quantity')) ?: $item->get_quantity(),
                    'duration' => $item->get_meta('booking_duration'),
                    'payment_type' => $item->get_meta('booking_payment_type'),
                    'deposit_amount' => (float) $item->get_meta('booking_deposit_amount'),
                    'remaining_amount' => (float) $item->get_meta('booking_remaining_amount'),
                    'notes' => $item->get_meta('booking_notes'),
                    'status' => in_array($order->get_status(), ['processing', 'completed'], true) ? 'confirmed' : 'pending',
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ]);
            }
        }
    }


    public function email_order_booking_meta($order, $sent_to_admin, $plain_text, $email) {
        if ('yes' !== get_option('swsb_emails_enabled', 'yes')) {
            return;
        }

        $rows = [];
        foreach ($order->get_items() as $item) {
            if (!$item->get_meta('booking_date')) {
                continue;
            }

            $rows[] = [
                'product' => $item->get_name(),
                'date' => $item->get_meta('booking_date'),
                'slot' => $item->get_meta('booking_slot'),
                'quantity' => $item->get_meta('booking_quantity'),
                'payment' => $item->get_meta('booking_payment_type'),
                'deposit' => $item->get_meta('booking_deposit_amount'),
                'remaining' => $item->get_meta('booking_remaining_amount'),
                'notes' => $item->get_meta('booking_notes'),
                'attribute_name' => $item->get_meta('booking_attribute_name'),
                'rental_duration' => $item->get_meta('booking_rental_duration'),
                'start_date' => $item->get_meta('booking_start_date'),
                'end_date' => $item->get_meta('booking_end_date'),
                'selected_dates' => $item->get_meta('booking_selected_dates'),
                'daily_rate' => $item->get_meta('booking_daily_rate'),
                'total_rental_amount' => $item->get_meta('booking_total_rental_amount'),
                'variation_id' => $item->get_meta('booking_variation_id'),
                'variation_name' => $item->get_meta('booking_variation_name'),
                'variation_attributes' => $item->get_meta('booking_selected_attributes'),
                'variation_price' => $item->get_meta('booking_variation_price'),
            ];
        }

        if (!$rows) {
            return;
        }

        echo '<h2>' . esc_html__('Booking Confirmation', 'smart-woocommerce-slot-booking') . '</h2>';
        foreach ($rows as $row) {
            echo '<p><strong>' . esc_html($row['product']) . '</strong><br>';
            if ($row['rental_duration']) {
                $attr_label = $row['attribute_name'] ? $row['attribute_name'] : esc_html__('Rental Duration', 'smart-woocommerce-slot-booking');
                if (substr($attr_label, -1) !== ':') {
                    $attr_label .= ':';
                }
                echo esc_html($attr_label) . ' ' . esc_html($row['rental_duration']) . '<br>';
                echo esc_html__('Start Date:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['start_date']) . '<br>';
                echo esc_html__('End Date:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['end_date']) . '<br>';
                echo esc_html__('Selected Dates:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['selected_dates']) . '<br>';
            } else {
                echo esc_html__('Date:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['date']) . '<br>';
            }
            echo esc_html__('Time:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['slot']) . '<br>';
            if ($row['variation_id']) {
                echo esc_html__('Variation ID:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['variation_id']) . '<br>';
                echo esc_html__('Variation Name:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['variation_name']) . '<br>';
                if ($row['variation_attributes']) {
                    echo esc_html__('Selected Attributes:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['variation_attributes']) . '<br>';
                }
            }
            echo esc_html__('Quantity:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['quantity']) . '<br>';
            if ($row['rental_duration']) {
                echo esc_html__('Daily Rate:', 'smart-woocommerce-slot-booking') . ' ' . wc_price($row['daily_rate']) . '<br>';
                echo esc_html__('Total Rental Amount:', 'smart-woocommerce-slot-booking') . ' ' . wc_price($row['total_rental_amount']) . '<br>';
            }
            echo esc_html__('Payment:', 'smart-woocommerce-slot-booking') . ' ' . esc_html($row['payment'] === 'deposit' ? __('Deposit only', 'smart-woocommerce-slot-booking') : __('Full amount', 'smart-woocommerce-slot-booking')) . '<br>';
            if ($row['payment'] === 'deposit') {
                echo esc_html__('Deposit Paid:', 'smart-woocommerce-slot-booking') . ' ' . wc_price($row['deposit']) . '<br>';
                echo esc_html__('Remaining Balance:', 'smart-woocommerce-slot-booking') . ' ' . wc_price($row['remaining']) . '</p>';
            } else {
                echo esc_html__('Total Paid:', 'smart-woocommerce-slot-booking') . ' ' . wc_price($row['deposit']) . '</p>';
            }
            if ($row['notes']) {
                echo wp_kses_post(wpautop($row['notes']));
            }
        }
    }


public function email_from_name($name) {
        return get_option('swsb_email_sender_name', $name) ?: $name;
    }

    public function email_from_address($email) {
        return get_option('swsb_email_sender_email', $email) ?: $email;
    }

    public function admin_menu() {
        add_menu_page(__('Bookings', 'smart-woocommerce-slot-booking'), __('Bookings', 'smart-woocommerce-slot-booking'), 'manage_woocommerce', 'swsb-bookings', [$this, 'bookings_page'], 'dashicons-calendar-alt', 56);
        add_submenu_page('swsb-bookings', __('Email Settings', 'smart-woocommerce-slot-booking'), __('Email Settings', 'smart-woocommerce-slot-booking'), 'manage_woocommerce', 'swsb-settings', [$this, 'settings_page']);
    }

    public function register_settings() {
        register_setting('swsb_settings', 'swsb_time_format', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('swsb_settings', 'swsb_emails_enabled', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('swsb_settings', 'swsb_email_sender_name', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('swsb_settings', 'swsb_email_sender_email', ['sanitize_callback' => 'sanitize_email']);
        register_setting('swsb_settings', 'swsb_email_logo', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('swsb_settings', 'swsb_email_accent_color', ['sanitize_callback' => 'sanitize_hex_color']);
        register_setting('swsb_settings', 'swsb_email_template', ['sanitize_callback' => 'wp_kses_post']);
    }

    public function bookings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view bookings.', 'smart-woocommerce-slot-booking'));
        }

        global $wpdb;

        $where = 'WHERE 1=1';
        $params = [];
        $filters = [
            'booking_date' => sanitize_text_field(wp_unslash($_GET['booking_date'] ?? '')),
            'product_id' => absint($_GET['product_id'] ?? 0),
            'status' => sanitize_key($_GET['status'] ?? ''),
            'payment_type' => sanitize_key($_GET['payment_type'] ?? ''),
            'customer' => sanitize_text_field(wp_unslash($_GET['customer'] ?? '')),
        ];

        if ($filters['booking_date']) {
            $where .= ' AND booking_date = %s';
            $params[] = $filters['booking_date'];
        }
        if ($filters['product_id']) {
            $where .= ' AND product_id = %d';
            $params[] = $filters['product_id'];
        }
        if ($filters['status']) {
            $where .= ' AND status = %s';
            $params[] = $filters['status'];
        }
        if ($filters['payment_type']) {
            $where .= ' AND payment_type = %s';
            $params[] = $filters['payment_type'];
        }
        if ($filters['customer']) {
            $where .= ' AND (customer_name LIKE %s OR customer_email LIKE %s)';
            $params[] = '%' . $wpdb->esc_like($filters['customer']) . '%';
            $params[] = '%' . $wpdb->esc_like($filters['customer']) . '%';
        }

        $sql = "SELECT * FROM " . self::bookings_table() . " {$where} ORDER BY booking_date DESC, id DESC LIMIT 200";
        $bookings = $params ? $wpdb->get_results($wpdb->prepare($sql, $params)) : $wpdb->get_results($sql);
        ?>
        <div class="wrap swsb-bookings-admin">
            <h1><?php esc_html_e('Bookings', 'smart-woocommerce-slot-booking'); ?></h1>
            <form method="get" class="swsb-filter-bar">
                <input type="hidden" name="page" value="swsb-bookings">
                <input type="date" name="booking_date" value="<?php echo esc_attr($filters['booking_date']); ?>">
                <input type="number" name="product_id" value="<?php echo esc_attr($filters['product_id'] ?: ''); ?>" placeholder="<?php esc_attr_e('Product ID', 'smart-woocommerce-slot-booking'); ?>">
                <select name="status">
                    <option value=""><?php esc_html_e('All statuses', 'smart-woocommerce-slot-booking'); ?></option>
                    <?php foreach (['pending', 'confirmed', 'completed', 'cancelled', 'refunded'] as $status) : ?>
                        <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'], $status); ?>><?php echo esc_html(ucfirst($status)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="payment_type">
                    <option value=""><?php esc_html_e('All payment types', 'smart-woocommerce-slot-booking'); ?></option>
                    <option value="full" <?php selected($filters['payment_type'], 'full'); ?>><?php esc_html_e('Full', 'smart-woocommerce-slot-booking'); ?></option>
                    <option value="deposit" <?php selected($filters['payment_type'], 'deposit'); ?>><?php esc_html_e('Deposit', 'smart-woocommerce-slot-booking'); ?></option>
                </select>
                <input type="search" name="customer" value="<?php echo esc_attr($filters['customer']); ?>" placeholder="<?php esc_attr_e('Customer', 'smart-woocommerce-slot-booking'); ?>">
                <button class="button button-primary"><?php esc_html_e('Filter', 'smart-woocommerce-slot-booking'); ?></button>
                <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=swsb_export_bookings')); ?>"><?php esc_html_e('Export CSV', 'smart-woocommerce-slot-booking'); ?></a>
            </form>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Booking ID', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Order ID', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Customer', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Product', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Booking Date', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Time Slot', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Quantity', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Payment', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Remaining', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Status', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Created', 'smart-woocommerce-slot-booking'); ?></th>
                        <th><?php esc_html_e('Actions', 'smart-woocommerce-slot-booking'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($bookings) : ?>
                        <?php foreach ($bookings as $booking) : ?>
                            <tr>
                                <td>#<?php echo esc_html($booking->id); ?></td>
                                <td><a href="<?php echo esc_url(admin_url('post.php?post=' . absint($booking->order_id) . '&action=edit')); ?>">#<?php echo esc_html($booking->order_id); ?></a></td>
                                <td><?php echo esc_html($booking->customer_name); ?><br><a href="mailto:<?php echo esc_attr($booking->customer_email); ?>"><?php echo esc_html($booking->customer_email); ?></a><br><?php echo esc_html($booking->customer_phone); ?></td>
                                <td>
                                    <?php 
                                    echo esc_html(get_the_title($booking->product_id)); 
                                    $order = wc_get_order($booking->order_id);
                                    if ($order) {
                                        foreach ($order->get_items() as $item) {
                                            if ($item->get_product_id() == $booking->product_id) {
                                                $var_id = $item->get_meta('booking_variation_id');
                                                $var_name = $item->get_meta('booking_variation_name');
                                                $var_attrs = $item->get_meta('booking_selected_attributes');
                                                if ($var_id) {
                                                    echo '<br><small style="color:#60645c;">' . sprintf(esc_html__('Variation: %s (ID: %d)', 'smart-woocommerce-slot-booking'), esc_html($var_name), absint($var_id)) . '</small>';
                                                    if ($var_attrs) {
                                                        echo '<br><small style="color:#71756d;">' . esc_html($var_attrs) . '</small>';
                                                    }
                                                }
                                                break;
                                            }
                                        }
                                    }
                                    ?>
                                </td>
                                <td><?php echo esc_html($booking->booking_date); ?></td>
                                <td><?php echo esc_html($booking->booking_slot); ?></td>
                                <td><?php echo esc_html($booking->quantity); ?></td>
                                <td><?php echo esc_html(ucfirst($booking->payment_type)); ?><br><?php echo wp_kses_post(wc_price($booking->deposit_amount)); ?></td>
                                <td><?php echo wp_kses_post(wc_price($booking->remaining_amount)); ?></td>
                                <td><?php echo esc_html(ucfirst($booking->status)); ?></td>
                                <td><?php echo esc_html($booking->created_at); ?></td>
                                <td>
                                    <a class="button" href="<?php echo esc_url(admin_url('post.php?post=' . absint($booking->order_id) . '&action=edit')); ?>"><?php esc_html_e('View Order', 'smart-woocommerce-slot-booking'); ?></a>
                                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=swsb_booking_status&booking_id=' . absint($booking->id) . '&status=cancelled'), 'swsb_booking_status_' . absint($booking->id))); ?>"><?php esc_html_e('Cancel', 'smart-woocommerce-slot-booking'); ?></a>
                                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=swsb_booking_status&booking_id=' . absint($booking->id) . '&status=confirmed'), 'swsb_booking_status_' . absint($booking->id))); ?>"><?php esc_html_e('Confirm', 'smart-woocommerce-slot-booking'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="12"><?php esc_html_e('No bookings found.', 'smart-woocommerce-slot-booking'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Booking Email Settings', 'smart-woocommerce-slot-booking'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('swsb_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="swsb_time_format"><?php esc_html_e('Time Format', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td>
                            <select name="swsb_time_format" id="swsb_time_format">
                                <option value="12" <?php selected(get_option('swsb_time_format', '12'), '12'); ?>><?php esc_html_e('12-hour clock', 'smart-woocommerce-slot-booking'); ?></option>
                                <option value="24" <?php selected(get_option('swsb_time_format', '12'), '24'); ?>><?php esc_html_e('24-hour clock', 'smart-woocommerce-slot-booking'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Emails', 'smart-woocommerce-slot-booking'); ?></th>
                        <td><input type="hidden" name="swsb_emails_enabled" value="no"><label><input type="checkbox" name="swsb_emails_enabled" value="yes" <?php checked(get_option('swsb_emails_enabled', 'yes'), 'yes'); ?>> <?php esc_html_e('Enable booking email section in WooCommerce emails', 'smart-woocommerce-slot-booking'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="swsb_email_sender_name"><?php esc_html_e('Sender Name', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td><input type="text" class="regular-text" id="swsb_email_sender_name" name="swsb_email_sender_name" value="<?php echo esc_attr(get_option('swsb_email_sender_name')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="swsb_email_sender_email"><?php esc_html_e('Sender Email', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td><input type="email" class="regular-text" id="swsb_email_sender_email" name="swsb_email_sender_email" value="<?php echo esc_attr(get_option('swsb_email_sender_email')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="swsb_email_logo"><?php esc_html_e('Logo URL', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td><input type="url" class="regular-text" id="swsb_email_logo" name="swsb_email_logo" value="<?php echo esc_attr(get_option('swsb_email_logo')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="swsb_email_accent_color"><?php esc_html_e('Accent Color', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td><input type="text" class="regular-text" id="swsb_email_accent_color" name="swsb_email_accent_color" value="<?php echo esc_attr(get_option('swsb_email_accent_color', '#dfff2f')); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="swsb_email_template"><?php esc_html_e('Template Notes', 'smart-woocommerce-slot-booking'); ?></label></th>
                        <td><textarea class="large-text" rows="6" id="swsb_email_template" name="swsb_email_template"><?php echo esc_textarea(get_option('swsb_email_template')); ?></textarea></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function export_bookings_csv() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'smart-woocommerce-slot-booking'));
        }

        global $wpdb;
        $bookings = $wpdb->get_results("SELECT * FROM " . self::bookings_table() . " ORDER BY created_at DESC", ARRAY_A);

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename=bookings.csv');

        $output = fopen('php://output', 'w');
        if (!empty($bookings)) {
            fputcsv($output, array_keys($bookings[0]));
            foreach ($bookings as $booking) {
                fputcsv($output, $booking);
            }
        }
        fclose($output);
        exit;
    }

    public function update_booking_status() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Permission denied.', 'smart-woocommerce-slot-booking'));
        }

        $booking_id = absint($_GET['booking_id'] ?? 0);
        $status = sanitize_key($_GET['status'] ?? '');
        $allowed = ['pending', 'confirmed', 'completed', 'cancelled', 'refunded'];

        if (!$booking_id || !in_array($status, $allowed, true)) {
            wp_die(esc_html__('Invalid booking action.', 'smart-woocommerce-slot-booking'));
        }

        check_admin_referer('swsb_booking_status_' . $booking_id);

        global $wpdb;
        $wpdb->update(self::bookings_table(), [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], ['id' => $booking_id], ['%s', '%s'], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=swsb-bookings'));
        exit;
    }
}

register_activation_hook(__FILE__, ['WooBookify_Digital_Suncity_Plugin', 'activate']);
WooBookify_Digital_Suncity_Plugin::instance();

