<?php
/**
 * Export config file
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export config file class
 */
class MksDdn_MC_Export_Config_File {

	/**
	 * Execute config file creation
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		$config_file  = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_PACKAGE_NAME;

		$config = isset( $params['config'] ) ? $params['config'] : array();
		$content = wp_json_encode( $config, JSON_PRETTY_PRINT );

		MksDdn_MC_File::write( $config_file, $content );

		return $params;
	}
}

