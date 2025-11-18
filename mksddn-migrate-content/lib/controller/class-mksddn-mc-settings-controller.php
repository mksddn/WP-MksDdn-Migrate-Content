<?php
/**
 * Settings controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Settings controller class
 */
class MksDdn_MC_Settings_Controller {

	/**
	 * Render settings page
	 *
	 * @return void
	 */
	public static function index() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		$settings = MksDdn_MC_Settings::get_all();
		$default_excludes = MksDdn_MC_Settings::get_default_excludes();

		MksDdn_MC_Template::render(
			'settings/index',
			array(
				'settings'        => $settings,
				'default_excludes' => $default_excludes,
			)
		);
	}

	/**
	 * Save settings
	 *
	 * @param array $params Request parameters
	 * @return void
	 */
	public static function save( $params = array() ) {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Verify nonce
		if ( ! isset( $params['nonce'] ) || ! wp_verify_nonce( $params['nonce'], 'mksddn_mc_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'mksddn-migrate-content' ) ) );
			exit;
		}

		// Set params
		if ( empty( $params ) ) {
			$params = stripslashes_deep( $_POST );
		}

		// Prepare settings
		$settings = array(
			'exclude_files'        => isset( $params['exclude_files'] ) ? sanitize_textarea_field( $params['exclude_files'] ) : '',
			'exclude_directories'  => isset( $params['exclude_directories'] ) ? sanitize_textarea_field( $params['exclude_directories'] ) : '',
			'exclude_extensions'   => isset( $params['exclude_extensions'] ) ? sanitize_textarea_field( $params['exclude_extensions'] ) : '',
			'exclude_tables'       => isset( $params['exclude_tables'] ) ? sanitize_textarea_field( $params['exclude_tables'] ) : '',
			'import_replace_urls'  => isset( $params['import_replace_urls'] ) ? (bool) $params['import_replace_urls'] : true,
			'import_replace_paths' => isset( $params['import_replace_paths'] ) ? (bool) $params['import_replace_paths'] : true,
			'backups_retention'    => isset( $params['backups_retention'] ) ? absint( $params['backups_retention'] ) : 0,
			'backups_path'         => isset( $params['backups_path'] ) ? sanitize_text_field( $params['backups_path'] ) : MKSDDN_MC_DEFAULT_BACKUPS_PATH,
		);

		// Convert textarea fields to arrays
		$settings['exclude_files'] = ! empty( $settings['exclude_files'] ) ? array_map( 'trim', explode( "\n", $settings['exclude_files'] ) ) : array();
		$settings['exclude_directories'] = ! empty( $settings['exclude_directories'] ) ? array_map( 'trim', explode( "\n", $settings['exclude_directories'] ) ) : array();
		$settings['exclude_extensions'] = ! empty( $settings['exclude_extensions'] ) ? array_map( 'trim', explode( "\n", $settings['exclude_extensions'] ) ) : array();
		$settings['exclude_tables'] = ! empty( $settings['exclude_tables'] ) ? array_map( 'trim', explode( "\n", $settings['exclude_tables'] ) ) : array();

		// Save settings
		if ( MksDdn_MC_Settings::save_all( $settings ) ) {
			MksDdn_MC_Notification::success( __( 'Settings saved successfully.', 'mksddn-migrate-content' ) );
			wp_send_json_success( array( 'message' => __( 'Settings saved successfully.', 'mksddn-migrate-content' ) ) );
		} else {
			MksDdn_MC_Notification::error( __( 'Failed to save settings.', 'mksddn-migrate-content' ) );
			wp_send_json_error( array( 'message' => __( 'Failed to save settings.', 'mksddn-migrate-content' ) ) );
		}
		exit;
	}
}

