<?php
/**
 * Export enumerate media
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export enumerate media class
 */
class MksDdn_MC_Export_Enumerate_Media {

	/**
	 * Execute media enumeration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Enumerating media files...', 'mksddn-migrate-content' ) );

		$media_files = array();
		$upload_dir = wp_upload_dir();

		if ( ! is_dir( $upload_dir['basedir'] ) ) {
			$params['media_files'] = array();
			$params['total_media_files_count'] = 0;
			return $params;
		}

		// Get all files in uploads directory
		$iterator = new MksDdn_MC_Recursive_Directory_Iterator( $upload_dir['basedir'] );

		foreach ( new RecursiveIteratorIterator( $iterator ) as $file ) {
			if ( $file->isFile() ) {
				$relative_path = str_replace( $upload_dir['basedir'] . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$media_files[] = $relative_path;
			}
		}

		$params['media_files'] = $media_files;
		$params['total_media_files_count'] = count( $media_files );

		return $params;
	}
}

