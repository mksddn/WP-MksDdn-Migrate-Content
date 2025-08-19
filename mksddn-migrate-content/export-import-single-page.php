<?php
/*
Plugin Name: Export and Import Single Page with ACF
Description: Export and import a single page or options page along with its ACF fields in JSON format.
Version: 1.1
Author: mksddn
*/

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Load components
require_once __DIR__ . '/includes/class-export-import-admin.php';
require_once __DIR__ . '/includes/class-export-handler.php';
require_once __DIR__ . '/includes/class-import-handler.php';
require_once __DIR__ . '/includes/class-options-helper.php';

// Initialize plugin
add_action('init', function(): void {
    new Export_Import_Admin();
});
