<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/woobookify-digital-suncity.php';

if (class_exists('WooBookify_Digital_Suncity_Plugin')) {
    register_activation_hook(__FILE__, ['WooBookify_Digital_Suncity_Plugin', 'activate']);
}
