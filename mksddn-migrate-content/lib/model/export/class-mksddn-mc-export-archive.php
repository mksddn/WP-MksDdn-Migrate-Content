<?php
/**
 * Export archive creation
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export archive class
 */
class MksDdn_MC_Export_Archive {

	/**
	 * Execute archive creation
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Creating archive file...', 'mksddn-migrate-content' ) );

		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		$archive_path = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . $params['archive'];

		// Create backups directory if not exists
		if ( ! is_dir( MKSDDN_MC_BACKUPS_PATH ) ) {
			MksDdn_MC_Directory::create( MKSDDN_MC_BACKUPS_PATH );
		}

		// Create compressor
		$compressor = new MksDdn_MC_Compressor( $archive_path, true );

		// Add all files from storage to archive
		$compressor->add_directory( $storage_path, '' );

		// Finalize archive
		$compressor->finalize();

		// Set archive path in params
		$params['archive_path'] = $archive_path;

		return $params;
	}
}

