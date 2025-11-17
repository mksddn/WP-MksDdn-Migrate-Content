<?php
/**
 * Export plugins
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export plugins class
 */
class MksDdn_MC_Export_Plugins {

	/**
	 * Execute plugins export
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];

		MksDdn_MC_Status::info( __( 'Exporting plugins...', 'mksddn-migrate-content' ) );

		// Enumerate plugins if not done
		if ( empty( $params['plugins'] ) ) {
			$params = MksDdn_MC_Export_Enumerate_Plugins::execute( $params );
		}

		$plugins = $params['plugins'];
		$plugins_dir = WP_PLUGIN_DIR;
		$plugins_list_file = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_PLUGINS_LIST_NAME;

		// Save plugins list
		$plugins_data = wp_json_encode( $plugins, JSON_PRETTY_PRINT );
		MksDdn_MC_File::write( $plugins_list_file, $plugins_data );

		// Copy plugins to storage
		$plugins_storage = $storage_path . DIRECTORY_SEPARATOR . 'plugins';
		MksDdn_MC_Directory::create( $plugins_storage );

		$processed = 0;
		$total = count( $plugins );

		foreach ( $plugins as $plugin ) {
			$plugin_slug = dirname( $plugin['file'] );
			$source_dir = $plugins_dir . DIRECTORY_SEPARATOR . $plugin_slug;
			$dest_dir = $plugins_storage . DIRECTORY_SEPARATOR . $plugin_slug;

			if ( is_dir( $source_dir ) ) {
				MksDdn_MC_Directory::copy( $source_dir, $dest_dir );
			}

			$processed++;
			if ( $processed % 10 === 0 ) {
				$progress = (int) ( ( $processed / $total ) * 100 );
				MksDdn_MC_Status::progress( $progress );
			}
		}

		MksDdn_MC_Status::info( __( 'Plugins exported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

