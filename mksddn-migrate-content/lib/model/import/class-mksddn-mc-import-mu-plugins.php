<?php
/**
 * Import MU plugins
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import MU plugins class
 */
class MksDdn_MC_Import_MU_Plugins {

	/**
	 * Execute MU plugins import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing MU plugins...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			return $params;
		}

		$extract_path = $params['extract_path'];
		$mu_plugins_source = $extract_path . DIRECTORY_SEPARATOR . 'mu-plugins';
		$mu_plugins_dest = WPMU_PLUGIN_DIR;

		if ( is_dir( $mu_plugins_source ) ) {
			// Create MU plugins directory if not exists
			if ( ! is_dir( $mu_plugins_dest ) ) {
				MksDdn_MC_Directory::create( $mu_plugins_dest );
			}

			// Copy MU plugins
			$mu_plugins = glob( $mu_plugins_source . DIRECTORY_SEPARATOR . '*' );
			foreach ( $mu_plugins as $mu_plugin ) {
				if ( is_file( $mu_plugin ) ) {
					$plugin_name = basename( $mu_plugin );
					$dest_file = $mu_plugins_dest . DIRECTORY_SEPARATOR . $plugin_name;
					MksDdn_MC_File::copy( $mu_plugin, $dest_file );
				} elseif ( is_dir( $mu_plugin ) ) {
					$plugin_dir = basename( $mu_plugin );
					$dest_dir = $mu_plugins_dest . DIRECTORY_SEPARATOR . $plugin_dir;
					MksDdn_MC_Directory::copy( $mu_plugin, $dest_dir );
				}
			}
		}

		MksDdn_MC_Status::info( __( 'MU plugins imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

