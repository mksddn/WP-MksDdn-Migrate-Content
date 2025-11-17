<?php
/**
 * Export database file
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export database file class
 */
class MksDdn_MC_Export_Database_File {

	/**
	 * Execute database file creation
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		$database_file = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_DATABASE_NAME;

		MksDdn_MC_Status::info( __( 'Saving database file...', 'mksddn-migrate-content' ) );

		$content = isset( $params['database_content'] ) ? $params['database_content'] : '';

		// Add header
		$header = "-- MksDdn Migrate Content Database Export\n";
		$header .= "-- Export Date: " . current_time( 'mysql' ) . "\n";
		$header .= "-- WordPress Version: " . get_bloginfo( 'version' ) . "\n";
		$header .= "-- PHP Version: " . PHP_VERSION . "\n\n";
		$header .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
		$header .= "SET time_zone = \"+00:00\";\n\n";

		$content = $header . $content;

		MksDdn_MC_File::write( $database_file, $content );

		return $params;
	}
}

