<?php
/**
 * Import compatibility check
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import compatibility class
 */
class MksDdn_MC_Import_Compatibility {

	/**
	 * Execute compatibility check
	 *
	 * @param array $params Import parameters
	 * @return array
	 * @throws Exception
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

		// Check package compatibility if available
		if ( isset( $params['package'] ) && is_array( $params['package'] ) ) {
			$package = $params['package'];

			// Check WordPress version compatibility
			if ( isset( $package['wordpress']['version'] ) ) {
				$export_wp_version = $package['wordpress']['version'];
				$current_wp_version = $wp_version;

				// Check if current WordPress version is compatible
				if ( version_compare( $current_wp_version, '6.2', '<' ) ) {
					throw new Exception( __( 'WordPress 6.2 or higher is required for import.', 'mksddn-migrate-content' ) );
				}

				if ( version_compare( $current_wp_version, $export_wp_version, '<' ) ) {
					MksDdn_MC_Status::info( sprintf( __( 'Warning: Archive was created with WordPress %s, current version is %s.', 'mksddn-migrate-content' ), $export_wp_version, $current_wp_version ) );
				}
			}

			// Check PHP version compatibility
			if ( isset( $package['php']['version'] ) ) {
				$export_php_version = $package['php']['version'];
				if ( version_compare( PHP_VERSION, $export_php_version, '<' ) ) {
					MksDdn_MC_Status::info( sprintf( __( 'Warning: Archive was created with PHP %s, current version is %s.', 'mksddn-migrate-content' ), $export_php_version, PHP_VERSION ) );
				}
			}
		}

		// Check disk space
		$free_space = disk_free_space( WP_CONTENT_DIR );
		$required_space = self::estimate_required_space( $params );

		if ( $free_space < ( $required_space * MKSDDN_MC_DISK_SPACE_FACTOR ) ) {
			throw new Exception( __( 'Insufficient disk space for import.', 'mksddn-migrate-content' ) );
		}

		return $params;
	}

	/**
	 * Estimate required disk space
	 *
	 * @param array $params Import parameters
	 * @return int Bytes
	 */
	private static function estimate_required_space( $params ) {
		$size = 0;

		if ( ! empty( $params['archive_path'] ) ) {
			$size += filesize( $params['archive_path'] );
		}

		return $size;
	}
}

