<?php
/**
 * Export enumerate plugins
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export enumerate plugins class
 */
class MksDdn_MC_Export_Enumerate_Plugins {

	/**
	 * Execute plugins enumeration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Enumerating plugins...', 'mksddn-migrate-content' ) );

		$plugins = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $all_plugins as $plugin_file => $plugin_data ) {
			$plugins[] = array(
				'file'   => $plugin_file,
				'name'   => $plugin_data['Name'],
				'active' => in_array( $plugin_file, $active_plugins, true ),
			);
		}

		$params['plugins'] = $plugins;
		$params['total_plugins_count'] = count( $plugins );

		return $params;
	}
}

