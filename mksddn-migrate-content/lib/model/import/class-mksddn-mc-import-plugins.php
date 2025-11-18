<?php
/**
 * Import plugins
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import plugins class
 */
class MksDdn_MC_Import_Plugins {

	/**
	 * Execute plugins import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing plugins...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			return $params;
		}

		$extract_path = $params['extract_path'];
		$plugins_source = $extract_path . DIRECTORY_SEPARATOR . 'plugins';
		$plugins_dest = WP_PLUGIN_DIR;

		if ( is_dir( $plugins_source ) ) {
			// Read plugins list
			$plugins_list_file = $extract_path . DIRECTORY_SEPARATOR . MKSDDN_MC_PLUGINS_LIST_NAME;
			$plugins_list = array();

			if ( file_exists( $plugins_list_file ) ) {
				$plugins_data = MksDdn_MC_File::read( $plugins_list_file );
				$plugins_list = json_decode( $plugins_data, true );
			}

			// Copy plugins
			$plugins = glob( $plugins_source . DIRECTORY_SEPARATOR . '*' );
			foreach ( $plugins as $plugin_dir ) {
				if ( is_dir( $plugin_dir ) ) {
					$plugin_slug = basename( $plugin_dir );
					$dest_dir = $plugins_dest . DIRECTORY_SEPARATOR . $plugin_slug;

					// Skip if plugin already exists
					if ( is_dir( $dest_dir ) ) {
						continue;
					}

					MksDdn_MC_Directory::copy( $plugin_dir, $dest_dir );
				}
			}
		}

		MksDdn_MC_Status::info( __( 'Plugins imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

