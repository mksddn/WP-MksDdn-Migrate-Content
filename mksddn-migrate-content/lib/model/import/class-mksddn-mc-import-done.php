<?php
/**
 * Import done
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import done class
 */
class MksDdn_MC_Import_Done {

	/**
	 * Execute completion
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::done( __( 'Import completed successfully.', 'mksddn-migrate-content' ) );

		// Flush rewrite rules
		flush_rewrite_rules();

		// Clear cache if possible
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}

		return $params;
	}
}

