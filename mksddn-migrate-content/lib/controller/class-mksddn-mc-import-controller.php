<?php
/**
 * Import controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import controller class
 */
class MksDdn_MC_Import_Controller {

	/**
	 * Render import page
	 *
	 * @return void
	 */
	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		MksDdn_MC_Template::render( 'import/index' );
	}

	/**
	 * Execute import process
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function import( $params = array() ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) );
		}

		// Verify nonce
		if ( ! isset( $params['nonce'] ) || ! wp_verify_nonce( $params['nonce'], 'mksddn_mc_import' ) ) {
			wp_die( __( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Set params
		if ( empty( $params ) ) {
			$params = array_merge( $_GET, $_POST );
			
			// Try to get params from transient if available
			$stored_params = get_transient( 'mksddn_mc_import_params' );
			if ( $stored_params && is_array( $stored_params ) ) {
				$params = array_merge( $stored_params, $params );
			}
		}

		// Set priority
		if ( ! isset( $params['priority'] ) ) {
			$params['priority'] = 5;
		}

		try {
			// Import process steps
			$steps = array(
				array( 'priority' => 5, 'class' => 'MksDdn_MC_Import_Upload', 'method' => 'execute' ),
				array( 'priority' => 10, 'class' => 'MksDdn_MC_Import_Check_Encryption', 'method' => 'execute' ),
				array( 'priority' => 15, 'class' => 'MksDdn_MC_Import_Validate', 'method' => 'execute' ),
				array( 'priority' => 20, 'class' => 'MksDdn_MC_Import_Compatibility', 'method' => 'execute' ),
				array( 'priority' => 30, 'class' => 'MksDdn_MC_Import_Enumerate', 'method' => 'execute' ),
				array( 'priority' => 40, 'class' => 'MksDdn_MC_Import_Confirm', 'method' => 'execute' ),
				array( 'priority' => 50, 'class' => 'MksDdn_MC_Import_Database', 'method' => 'execute' ),
				array( 'priority' => 55, 'class' => 'MksDdn_MC_Import_Options', 'method' => 'execute' ),
				array( 'priority' => 60, 'class' => 'MksDdn_MC_Import_Media', 'method' => 'execute' ),
				array( 'priority' => 70, 'class' => 'MksDdn_MC_Import_Content', 'method' => 'execute' ),
				array( 'priority' => 75, 'class' => 'MksDdn_MC_Import_MU_Plugins', 'method' => 'execute' ),
				array( 'priority' => 80, 'class' => 'MksDdn_MC_Import_Plugins', 'method' => 'execute' ),
				array( 'priority' => 90, 'class' => 'MksDdn_MC_Import_Themes', 'method' => 'execute' ),
				array( 'priority' => 95, 'class' => 'MksDdn_MC_Import_Users', 'method' => 'execute' ),
				array( 'priority' => 100, 'class' => 'MksDdn_MC_Import_Permalinks', 'method' => 'execute' ),
				array( 'priority' => 110, 'class' => 'MksDdn_MC_Import_Done', 'method' => 'execute' ),
				array( 'priority' => 120, 'class' => 'MksDdn_MC_Import_Clean', 'method' => 'execute' ),
			);

			// Execute steps
			foreach ( $steps as $step ) {
				if ( (int) $params['priority'] === $step['priority'] ) {
					if ( class_exists( $step['class'] ) && method_exists( $step['class'], $step['method'] ) ) {
						$params = call_user_func( array( $step['class'], $step['method'] ), $params );

						// Check if confirmation is required
						if ( isset( $params['requires_confirmation'] ) && $params['requires_confirmation'] ) {
							wp_send_json_success( array( 'requires_confirmation' => true, 'params' => $params ) );
							exit;
						}
					}
					break;
				}
			}

			// Check if completed
			$completed = true;
			$current_priority = (int) $params['priority'];
			$next_priority = null;

			foreach ( $steps as $step ) {
				if ( $step['priority'] > $current_priority ) {
					$next_priority = $step['priority'];
					$completed = false;
					break;
				}
			}

			// Continue to next step if not completed
			if ( ! $completed && $next_priority ) {
				$params['priority'] = $next_priority;
				$params['completed'] = false;
				
				// Store params in transient for next step
				set_transient( 'mksddn_mc_import_params', $params, 3600 );
				
				self::continue_import( $params );
				wp_send_json_success( $params );
				exit;
			}

			// Import completed
			if ( $completed ) {
				MksDdn_MC_Status::done( __( 'Import completed successfully.', 'mksddn-migrate-content' ) );
				$params['completed'] = true;
				wp_send_json_success( $params );
				exit;
			}

		} catch ( Exception $e ) {
			MksDdn_MC_Status::error( __( 'Import failed', 'mksddn-migrate-content' ), $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
			exit;
		}

		wp_send_json_success( $params );
		exit;
	}

	/**
	 * Continue import process
	 *
	 * @param array $params Import parameters
	 * @return void
	 */
	private static function continue_import( $params ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php?action=mksddn_mc_import' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => $params,
			)
		);
	}
}

