<?php
/**
 * Import confirm
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import confirm class
 */
class MksDdn_MC_Import_Confirm {

	/**
	 * Execute confirmation
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Preparing import...', 'mksddn-migrate-content' ) );

		// Check if confirmation is required
		if ( ! isset( $params['confirmed'] ) || ! $params['confirmed'] ) {
			// Return confirmation request
			$params['requires_confirmation'] = true;
			return $params;
		}

		// Import confirmed
		$params['requires_confirmation'] = false;

		return $params;
	}
}

