<?php
/*
Plugin Name: MksDdn Migrate Content
Plugin URI: https://github.com/mksddn/WP-MksDdn-Migrate-Content
Description: Export and import single pages (and more) with metadata and media.
Version: 1.0.0
Author: mksddn
Author URI: https://github.com/mksddn
Text Domain: mksddn-migrate-content
Domain Path: /languages
Requires at least: 6.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

// Constants
define('MKSDDN_MC_VERSION', '1.0.0');
define('MKSDDN_MC_FILE', __FILE__);
define('MKSDDN_MC_DIR', plugin_dir_path(__FILE__));
define('MKSDDN_MC_URL', plugin_dir_url(__FILE__));

// I18n
add_action('plugins_loaded', function (): void {
    load_plugin_textdomain('mksddn-migrate-content', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

// Requirements check
function mksddn_mc_meets_requirements(): bool {
    global $wp_version;
    $php_ok = version_compare(PHP_VERSION, '7.4', '>=');
    $wp_ok  = isset($wp_version) && version_compare($wp_version, '6.2', '>=');
    return $php_ok && $wp_ok;
}

// Activation/Deactivation
register_activation_hook(__FILE__, function (): void {
    if (!mksddn_mc_meets_requirements()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(esc_html__('MksDdn Migrate Content requires WordPress 6.2+ and PHP 7.4+.', 'mksddn-migrate-content'));
    }
});

register_deactivation_hook(__FILE__, function (): void {
    // Reserved for cleanup (e.g., cron unscheduling)
});

// Bootstrap existing functionality
require_once MKSDDN_MC_DIR . 'export-import-single-page.php';


