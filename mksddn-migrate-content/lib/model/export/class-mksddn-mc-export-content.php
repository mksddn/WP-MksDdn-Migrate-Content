<?php
/**
 * Export content
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export content class
 */
class MksDdn_MC_Export_Content {

	/**
	 * Execute content export
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];

		MksDdn_MC_Status::info( __( 'Exporting content files...', 'mksddn-migrate-content' ) );

		// Enumerate content if not done
		if ( empty( $params['content_files'] ) ) {
			$params = MksDdn_MC_Export_Enumerate_Content::execute( $params );
		}

		$content_files = $params['content_files'];
		$content_dir = WP_CONTENT_DIR;
		$content_list_file = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_CONTENT_LIST_NAME;

		// Save content list
		$content_list = implode( "\n", $content_files );
		MksDdn_MC_File::write( $content_list_file, $content_list );

		// Copy content files to storage
		$content_storage = $storage_path . DIRECTORY_SEPARATOR . 'content';
		MksDdn_MC_Directory::create( $content_storage );

		$processed = 0;
		$total = count( $content_files );

		foreach ( $content_files as $relative_path ) {
			$source_file = $content_dir . DIRECTORY_SEPARATOR . $relative_path;
			$dest_file = $content_storage . DIRECTORY_SEPARATOR . $relative_path;

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

		MksDdn_MC_Status::info( __( 'Content files exported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

