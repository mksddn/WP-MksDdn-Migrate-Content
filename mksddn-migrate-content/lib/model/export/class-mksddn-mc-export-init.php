<?php
/**
 * Export initialization
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export initialization class
 */
class MksDdn_MC_Export_Init {

	/**
	 * Execute initialization
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		do_action( 'mksddn_mc_status_export_init', $params );

		// Set progress
		MksDdn_MC_Status::info( __( 'Preparing to export...', 'mksddn-migrate-content' ) );

		// Set archive filename
		if ( empty( $params['archive'] ) ) {
			$params['archive'] = self::generate_archive_filename();
		}

		// Set storage path
		if ( empty( $params['storage'] ) ) {
			$params['storage'] = self::generate_storage_folder();
		}

		// Create storage directory
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		if ( ! is_dir( $storage_path ) ) {
			MksDdn_MC_Directory::create( $storage_path );
		}

		return $params;
	}

	/**
	 * Generate archive filename
	 *
	 * @return string
	 */
	private static function generate_archive_filename() {
		$site_url = parse_url( site_url(), PHP_URL_HOST );
		$site_url = str_replace( array( '.', 'www.' ), array( '-', '' ), $site_url );
		$date     = date( 'Y-m-d-H-i-s' );
		return sprintf( '%s-%s.mksddn', $site_url, $date );
	}

	/**
	 * Generate storage folder name
	 *
	 * @return string
	 */
	private static function generate_storage_folder() {
		return 'export-' . time();
	}
}

