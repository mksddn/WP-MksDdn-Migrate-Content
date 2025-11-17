<?php
/**
 * Export download
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export download class
 */
class MksDdn_MC_Export_Download {

	/**
	 * Execute download
	 *
	 * @param array $params Export parameters
	 * @return void
	 */
	public static function execute( $params ) {
		if ( empty( $params['archive_path'] ) ) {
			wp_die( __( 'Archive file not found.', 'mksddn-migrate-content' ) );
		}

		$archive_path = $params['archive_path'];

		if ( ! file_exists( $archive_path ) ) {
			wp_die( __( 'Archive file does not exist.', 'mksddn-migrate-content' ) );
		}

		// Set headers for download
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $archive_path ) . '"' );
		header( 'Content-Length: ' . filesize( $archive_path ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output file
		readfile( $archive_path );
		exit;
	}
}

