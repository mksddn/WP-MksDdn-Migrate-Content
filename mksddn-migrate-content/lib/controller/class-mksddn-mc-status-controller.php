<?php
/**
 * Status controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Status controller class
 */
class MksDdn_MC_Status_Controller {

	/**
	 * Get current status
	 *
	 * @return void
	 */
	public static function status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mksddn-migrate-content' ) ) );
		}

		// Verify nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mksddn_mc_nonce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mksddn-migrate-content' ) ) );
		}

		$status = MksDdn_MC_Status::get();

		wp_send_json_success( $status );
	}
}

