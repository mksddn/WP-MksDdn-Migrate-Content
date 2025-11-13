<?php
/**
 * Plugin Name: MksDdn Migrate Content
 * Plugin URI: https://github.com/mksddn/wp-mksddn-migrate-content
 * Description: Professional WordPress migration plugin for exporting and importing sites with selective content support.
 * Version: 1.0.0
 * Author: mksddn
 * Author URI: https://github.com/mksddn
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mksddn-migrate-content
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 *
 * @package MksDdn_Migrate_Content
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Current plugin version.
 */
define( 'MKSDDN_MC_VERSION', '1.0.0' );
define( 'MKSDDN_MC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MKSDDN_MC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MKSDDN_MC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation and deactivation hooks.
 */
register_activation_hook( __FILE__, array( 'MksDdn_Migrate_Content', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MksDdn_Migrate_Content', 'deactivate' ) );

/**
 * Core plugin class.
 */
require_once MKSDDN_MC_PLUGIN_DIR . 'includes/class-mksddn-migrate-content.php';

/**
 * Initialize the plugin.
 */
function mksddn_mc_init() {
	$plugin = MksDdn_Migrate_Content::get_instance();
	$plugin->run();
}
mksddn_mc_init();

