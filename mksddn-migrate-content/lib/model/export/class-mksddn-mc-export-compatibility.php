<?php
/**
 * Export compatibility check
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export compatibility class
 */
class MksDdn_MC_Export_Compatibility {

	/**
	 * Execute compatibility check
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Checking compatibility...', 'mksddn-migrate-content' ) );

		// Check PHP version
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			throw new Exception( __( 'PHP 7.4 or higher is required.', 'mksddn-migrate-content' ) );
		}

		// Check WordPress version
		global $wp_version;
		if ( version_compare( $wp_version, '6.2', '<' ) ) {
			throw new Exception( __( 'WordPress 6.2 or higher is required.', 'mksddn-migrate-content' ) );
		}

		// Check disk space
		$free_space = disk_free_space( MKSDDN_MC_BACKUPS_PATH );
		$required_space = self::estimate_required_space();

		if ( $free_space < ( $required_space * MKSDDN_MC_DISK_SPACE_FACTOR + MKSDDN_MC_DISK_SPACE_EXTRA ) ) {
			throw new Exception( __( 'Insufficient disk space for export.', 'mksddn-migrate-content' ) );
		}

		return $params;
	}

	/**
	 * Estimate required disk space
	 *
	 * @return int Bytes
	 */
	private static function estimate_required_space() {
		$size = 0;

		// Database size
		global $wpdb;
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );
		foreach ( $tables as $table ) {
			$size += $table['Data_length'] + $table['Index_length'];
		}

		// Uploads directory size
		$upload_dir = wp_upload_dir();
		if ( is_dir( $upload_dir['basedir'] ) ) {
			$size += MksDdn_MC_Directory::size( $upload_dir['basedir'] );
		}

		return $size;
	}
}

