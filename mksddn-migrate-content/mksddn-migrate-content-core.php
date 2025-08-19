<?php

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


