<?php
/**
 * Import permalinks
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import permalinks class
 */
class MksDdn_MC_Import_Permalinks {

	/**
	 * Execute permalinks setup
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Setting up permalinks...', 'mksddn-migrate-content' ) );

		// Flush rewrite rules
		flush_rewrite_rules();

		MksDdn_MC_Status::info( __( 'Permalinks configured.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

