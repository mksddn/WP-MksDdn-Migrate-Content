<?php
/**
 * Backups controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Backups controller class
 */
class MksDdn_MC_Backups_Controller {

	/**
	 * Render backups page
	 *
	 * @return void
	 */
	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		$backups = MksDdn_MC_Backups::get_files();
		$downloadable = MksDdn_MC_Backups::are_downloadable();

		MksDdn_MC_Template::render(
			'backups/index',
			array(
				'backups'      => $backups,
				'downloadable' => $downloadable,
			)
		);
	}

	/**
	 * Delete backup
	 *
	 * @param array $params Request parameters
	 * @return void
	 */
	public static function delete( $params = array() ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Verify nonce
		if ( ! isset( $params['nonce'] ) || ! wp_verify_nonce( $params['nonce'], 'mksddn_mc_backups' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Get archive filename
		$archive = isset( $params['archive'] ) ? sanitize_file_name( trim( $params['archive'] ) ) : '';

		if ( empty( $archive ) ) {
			wp_send_json_error( array( 'message' => __( 'Archive filename is required.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Validate filename to prevent path traversal
		if ( mksddn_mc_validate_file( $archive ) !== 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid archive filename.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Use basename to prevent path traversal
		$archive = basename( $archive );

		try {
			MksDdn_MC_Backups::delete_file( $archive );
			MksDdn_MC_Notification::success( __( 'Backup deleted successfully.', 'mksddn-migrate-content' ) );
			wp_send_json_success( array( 'message' => __( 'Backup deleted successfully.', 'mksddn-migrate-content' ) ) );
		} catch ( Exception $e ) {
			MksDdn_MC_Notification::error( $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
		exit;
	}

	/**
	 * Download backup
	 *
	 * @param array $params Request parameters
	 * @return void
	 */
	public static function download( $params = array() ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) );
		}

		// Verify nonce
		if ( ! isset( $params['nonce'] ) || ! wp_verify_nonce( $params['nonce'], 'mksddn_mc_backups' ) ) {
			wp_die( __( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_GET );
		}

		// Get archive filename
		$archive = isset( $params['archive'] ) ? sanitize_file_name( trim( $params['archive'] ) ) : '';

		if ( empty( $archive ) ) {
			wp_die( __( 'Archive filename is required.', 'mksddn-migrate-content' ) );
		}

		// Validate filename to prevent path traversal
		if ( mksddn_mc_validate_file( $archive ) !== 0 ) {
			wp_die( __( 'Invalid archive filename.', 'mksddn-migrate-content' ) );
		}

		// Use basename to prevent path traversal
		$archive = basename( $archive );

		try {
			$file_path = MksDdn_MC_Backups::get_file_path( $archive );

			// Set headers for download
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
			header( 'Content-Length: ' . filesize( $file_path ) );
			header( 'Cache-Control: must-revalidate' );
			header( 'Pragma: public' );

			// Output file
			readfile( $file_path );
			exit;

		} catch ( Exception $e ) {
			wp_die( $e->getMessage() );
		}
	}
}

