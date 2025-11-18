<?php
/**
 * Import content
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import content class
 */
class MksDdn_MC_Import_Content {

	/**
	 * Execute content import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing content files...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			return $params;
		}

		$extract_path = $params['extract_path'];
		$content_source = $extract_path . DIRECTORY_SEPARATOR . 'content';
		$content_dest = WP_CONTENT_DIR;

		if ( is_dir( $content_source ) ) {
			// Copy content files
			$files = glob( $content_source . DIRECTORY_SEPARATOR . '*' );
			$processed = 0;
			$total = count( $files );

			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					$relative_path = str_replace( $content_source . DIRECTORY_SEPARATOR, '', $file );
					$dest_file = $content_dest . DIRECTORY_SEPARATOR . $relative_path;

					$dest_dir = dirname( $dest_file );
					if ( ! is_dir( $dest_dir ) ) {
						MksDdn_MC_Directory::create( $dest_dir );
					}

					MksDdn_MC_File::copy( $file, $dest_file );
				}

				$processed++;
				if ( $processed % 50 === 0 ) {
					$progress = (int) ( ( $processed / $total ) * 100 );
					MksDdn_MC_Status::progress( $progress );
				}
			}
		}

		MksDdn_MC_Status::info( __( 'Content files imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

