<?php
/**
 * Plugin bootstrap file.
 *
 * @package MksDdn_Migrate_Content
 */

/*
Plugin Name: MksDdn Migrate Content
Plugin URI: https://github.com/mksddn/WP-MksDdn-Migrate-Content
Description: Export and import single pages (and more) with metadata and media.
Version: 1.2.5
Author: mksddn
Author URI: https://github.com/mksddn
Text Domain: mksddn-migrate-content
Domain Path: /languages
Requires at least: 6.2
Requires PHP: 8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'MKSDDN_MC_VERSION', '1.2.5' );
define( 'MKSDDN_MC_FILE', __FILE__ );
define( 'MKSDDN_MC_DIR', plugin_dir_path( __FILE__ ) );
define( 'MKSDDN_MC_URL', plugin_dir_url( __FILE__ ) );
define( 'MKSDDN_MC_TEXT_DOMAIN', 'mksddn-migrate-content' );
define( 'MKSDDN_MC_BASENAME', plugin_basename( __FILE__ ) );

// I18n: For plugins hosted on WordPress.org, translations are auto-loaded since WP 4.6.

// Requirements check.
/**
 * Verify environment requirements.
 */
function mksddn_mc_meets_requirements(): bool {
	global $wp_version;
	$php_ok = version_compare( PHP_VERSION, '8.0', '>=' );
	$wp_ok  = isset( $wp_version ) && version_compare( $wp_version, '6.2', '>=' );
	return $php_ok && $wp_ok;
}

// Activation/Deactivation.
register_activation_hook(
	__FILE__,
	function (): void {
		if ( ! mksddn_mc_meets_requirements() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'MksDdn Migrate Content requires WordPress 6.2+ and PHP 8.0+.', 'mksddn-migrate-content' ) );
		}

		// Load autoloader to access plugin classes.
		require_once MKSDDN_MC_DIR . 'includes/autoload.php';

		// Create required directories.
		if ( class_exists( '\MksDdn\MigrateContent\Config\PluginConfig' ) ) {
			$result = \MksDdn\MigrateContent\Config\PluginConfig::create_required_directories();
			if ( is_wp_error( $result ) ) {
				// Log error but don't prevent activation.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'MksDdn Migrate Content activation error: ' . $result->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function (): void {
		if ( class_exists( '\MksDdn\MigrateContent\Automation\ScheduleManager' ) ) {
			\MksDdn\MigrateContent\Automation\ScheduleManager::deactivate();
		}
	}
);

// Bootstrap existing functionality.
require_once MKSDDN_MC_DIR . 'mksddn-migrate-content-core.php';
