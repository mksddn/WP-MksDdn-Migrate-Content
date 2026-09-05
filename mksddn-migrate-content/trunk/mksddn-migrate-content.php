<?php
/**
 * Plugin bootstrap file.
 *
 * @package MksDdn_Migrate_Content
 */

/*
Plugin Name: MksDdn Migrate Content
Plugin URI: https://github.com/mksddn/WP-MksDdn-Migrate-Content
Description: Reliable chunked WordPress migrations via custom .wpbkp archives — full site, selected content, and themes.
Version: 2.7.0
Author: mksddn
Author URI: https://github.com/mksddn
Text Domain: mksddn-migrate-content
Domain Path: /languages
Requires at least: 5.9
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants.
define( 'MKSDDN_MC_VERSION', '2.7.0' );
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
	$php_ok = version_compare( PHP_VERSION, '7.4', '>=' );
	$wp_ok  = isset( $wp_version ) && version_compare( $wp_version, '5.9', '>=' );
	return $php_ok && $wp_ok;
}

// Activation/Deactivation.
register_activation_hook(
	__FILE__,
	function (): void {
		if ( ! mksddn_mc_meets_requirements() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'MksDdn Migrate Content requires WordPress 5.9+ and PHP 7.4+.', 'mksddn-migrate-content' ) );
		}

		// Load autoloader to access plugin classes.
		require_once MKSDDN_MC_DIR . 'includes/autoload.php';

		// Create required directories.
		if ( class_exists( '\MksDdn\MigrateContent\Config\PluginConfig' ) ) {
			$result = \MksDdn\MigrateContent\Config\PluginConfig::create_required_directories();
			if ( is_wp_error( $result ) ) {
				// Log error but don't prevent activation.
				\MksDdn\MigrateContent\Services\PluginLogger::log(
					'Activation error: ' . $result->get_error_message(),
					'activation'
				);
			}
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		require_once MKSDDN_MC_DIR . 'includes/autoload.php';
		if ( class_exists( '\MksDdn\MigrateContent\Support\DeactivationCleanup' ) ) {
			\MksDdn\MigrateContent\Support\DeactivationCleanup::run();
		}
	}
);

// Bootstrap existing functionality.
require_once MKSDDN_MC_DIR . 'mksddn-migrate-content-core.php';
