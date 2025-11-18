<?php
/**
 * Import media
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import media class
 */
class MksDdn_MC_Import_Media {

	/**
	 * Execute media import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing media files...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			return $params;
		}

		$extract_path = $params['extract_path'];
		$upload_dir = wp_upload_dir();
		$media_source = $extract_path . DIRECTORY_SEPARATOR . 'uploads';
		$media_dest = $upload_dir['basedir'];

		if ( is_dir( $media_source ) ) {
			// Copy media files
			MksDdn_MC_Directory::copy( $media_source, $media_dest );
		}

		MksDdn_MC_Status::info( __( 'Media files imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

