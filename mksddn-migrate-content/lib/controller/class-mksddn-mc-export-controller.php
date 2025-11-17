<?php
/**
 * Export controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export controller class
 */
class MksDdn_MC_Export_Controller {

	/**
	 * Render export page
	 *
	 * @return void
	 */
	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		MksDdn_MC_Template::render( 'export/index' );
	}

	/**
	 * Execute export process
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function export( $params = array() ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) );
		}

		// Verify nonce
		if ( ! isset( $params['nonce'] ) || ! wp_verify_nonce( $params['nonce'], 'mksddn_mc_export' ) ) {
			wp_die( __( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Set params
		if ( empty( $params ) ) {
			$params = array_merge( $_GET, $_POST );
		}

		// Set priority
		if ( ! isset( $params['priority'] ) ) {
			$params['priority'] = 5;
		}

		try {
			// Export process steps
			$steps = array(
				array( 'priority' => 5, 'class' => 'MksDdn_MC_Export_Compatibility', 'method' => 'execute' ),
				array( 'priority' => 10, 'class' => 'MksDdn_MC_Export_Init', 'method' => 'execute' ),
				array( 'priority' => 20, 'class' => 'MksDdn_MC_Export_Config', 'method' => 'execute' ),
				array( 'priority' => 30, 'class' => 'MksDdn_MC_Export_Config_File', 'method' => 'execute' ),
				array( 'priority' => 40, 'class' => 'MksDdn_MC_Export_Database', 'method' => 'execute' ),
				array( 'priority' => 50, 'class' => 'MksDdn_MC_Export_Database_File', 'method' => 'execute' ),
				array( 'priority' => 60, 'class' => 'MksDdn_MC_Export_Media', 'method' => 'execute' ),
				array( 'priority' => 70, 'class' => 'MksDdn_MC_Export_Content', 'method' => 'execute' ),
				array( 'priority' => 80, 'class' => 'MksDdn_MC_Export_Plugins', 'method' => 'execute' ),
				array( 'priority' => 90, 'class' => 'MksDdn_MC_Export_Themes', 'method' => 'execute' ),
				array( 'priority' => 100, 'class' => 'MksDdn_MC_Export_Archive', 'method' => 'execute' ),
				array( 'priority' => 110, 'class' => 'MksDdn_MC_Export_Clean', 'method' => 'execute' ),
			);

			// Execute steps
			foreach ( $steps as $step ) {
				if ( (int) $params['priority'] === $step['priority'] ) {
					if ( class_exists( $step['class'] ) && method_exists( $step['class'], $step['method'] ) ) {
						$params = call_user_func( array( $step['class'], $step['method'] ), $params );
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
				self::continue_export( $params );
				wp_send_json_success( $params );
				exit;
			}

			// Export completed
			if ( $completed ) {
				MksDdn_MC_Status::done( __( 'Export completed successfully.', 'mksddn-migrate-content' ) );
				$params['completed'] = true;
				wp_send_json_success( $params );
				exit;
			}

		} catch ( Exception $e ) {
			MksDdn_MC_Status::error( __( 'Export failed', 'mksddn-migrate-content' ), $e->getMessage() );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
			exit;
		}

		wp_send_json_success( $params );
		exit;
	}

	/**
	 * Continue export process
	 *
	 * @param array $params Export parameters
	 * @return void
	 */
	private static function continue_export( $params ) {
		wp_remote_post(
			admin_url( 'admin-ajax.php?action=mksddn_mc_export' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => $params,
			)
		);
	}

	/**
	 * Download archive
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) );
		}

		// Verify nonce
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'mksddn_mc_download' ) ) {
			wp_die( __( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		$archive = isset( $_GET['archive'] ) ? sanitize_file_name( $_GET['archive'] ) : '';

		if ( empty( $archive ) ) {
			wp_die( __( 'Archive file not specified.', 'mksddn-migrate-content' ) );
		}

		$archive_path = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . $archive;

		if ( ! file_exists( $archive_path ) ) {
			wp_die( __( 'Archive file not found.', 'mksddn-migrate-content' ) );
		}

		MksDdn_MC_Export_Download::execute( array( 'archive_path' => $archive_path ) );
	}
}

