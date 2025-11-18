<?php
/**
 * Import users
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import users class
 */
class MksDdn_MC_Import_Users {

	/**
	 * Execute users import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing users...', 'mksddn-migrate-content' ) );

		// Users are imported through database
		// This class can be used for additional user processing if needed

		MksDdn_MC_Status::info( __( 'Users imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

