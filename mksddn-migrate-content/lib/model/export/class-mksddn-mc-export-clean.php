<?php
/**
 * Export cleanup
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export cleanup class
 */
class MksDdn_MC_Export_Clean {

	/**
	 * Execute cleanup
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Cleaning up temporary files...', 'mksddn-migrate-content' ) );

		// Clean storage directory
		if ( ! empty( $params['storage'] ) ) {
			$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
			if ( is_dir( $storage_path ) ) {
				MksDdn_MC_Directory::delete( $storage_path );
			}
		}

		// Clean old storage directories
		self::clean_old_storage();

		return $params;
	}

	/**
	 * Clean old storage directories
	 *
	 * @return void
	 */
	private static function clean_old_storage() {
		if ( ! is_dir( MKSDDN_MC_STORAGE_PATH ) ) {
			return;
		}

		$directories = glob( MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . 'export-*' );
		$current_time = time();

		foreach ( $directories as $directory ) {
			if ( is_dir( $directory ) ) {
				$directory_time = filemtime( $directory );
				if ( ( $current_time - $directory_time ) > MKSDDN_MC_MAX_STORAGE_CLEANUP ) {
					MksDdn_MC_Directory::delete( $directory );
				}
			}
		}
	}
}

