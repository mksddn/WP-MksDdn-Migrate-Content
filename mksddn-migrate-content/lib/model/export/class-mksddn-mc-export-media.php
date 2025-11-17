<?php
/**
 * Export media
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export media class
 */
class MksDdn_MC_Export_Media {

	/**
	 * Execute media export
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];

		MksDdn_MC_Status::info( __( 'Exporting media files...', 'mksddn-migrate-content' ) );

		// Enumerate media if not done
		if ( empty( $params['media_files'] ) ) {
			$params = MksDdn_MC_Export_Enumerate_Media::execute( $params );
		}

		$media_files = $params['media_files'];
		$upload_dir = wp_upload_dir();
		$media_list_file = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_MEDIA_LIST_NAME;

		// Save media list
		$media_list = implode( "\n", $media_files );
		MksDdn_MC_File::write( $media_list_file, $media_list );

		// Copy media files to storage
		$media_storage = $storage_path . DIRECTORY_SEPARATOR . 'uploads';
		MksDdn_MC_Directory::create( $media_storage );

		$processed = 0;
		$total = count( $media_files );

		foreach ( $media_files as $relative_path ) {
			$source_file = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . $relative_path;
			$dest_file = $media_storage . DIRECTORY_SEPARATOR . $relative_path;

			if ( file_exists( $source_file ) ) {
				$dest_dir = dirname( $dest_file );
				if ( ! is_dir( $dest_dir ) ) {
					MksDdn_MC_Directory::create( $dest_dir );
				}
				MksDdn_MC_File::copy( $source_file, $dest_file );
			}

			$processed++;
			if ( $processed % 100 === 0 ) {
				$progress = (int) ( ( $processed / $total ) * 100 );
				MksDdn_MC_Status::progress( $progress );
			}
		}

		MksDdn_MC_Status::info( __( 'Media files exported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

