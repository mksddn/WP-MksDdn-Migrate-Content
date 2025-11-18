<?php
/**
 * Import options
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import options class
 */
class MksDdn_MC_Import_Options {

	/**
	 * Execute options import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing options...', 'mksddn-migrate-content' ) );

		// Options are imported through database
		// This class can be used for additional options processing if needed

		// Update site URL and home URL if needed
		if ( isset( $params['package']['wordpress'] ) ) {
			$package = $params['package'];
			$old_url = isset( $package['wordpress']['url'] ) ? $package['wordpress']['url'] : '';
			$new_url = site_url();

			if ( ! empty( $old_url ) && $old_url !== $new_url ) {
				// URL replacement will be handled in database import
				MksDdn_MC_Status::info( sprintf( __( 'URL will be replaced: %s -> %s', 'mksddn-migrate-content' ), $old_url, $new_url ) );
			}
		}

		MksDdn_MC_Status::info( __( 'Options imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

