<?php
/**
 * Admin interface class.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin interface class.
 */
class MksDdn_MC_Export_Import_Admin {

	/**
	 * Add admin menu.
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-migrate-content',
			array( $this, 'render_main_page' ),
			'dashicons-database-export',
			30
		);

		add_submenu_page(
			'mksddn-migrate-content',
			__( 'Export', 'mksddn-migrate-content' ),
			__( 'Export', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-mc-export',
			array( $this, 'render_export_page' )
		);

		add_submenu_page(
			'mksddn-migrate-content',
			__( 'Import', 'mksddn-migrate-content' ),
			__( 'Import', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-mc-import',
			array( $this, 'render_import_page' )
		);

		add_submenu_page(
			'mksddn-migrate-content',
			__( 'History', 'mksddn-migrate-content' ),
			__( 'History', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-mc-history',
			array( $this, 'render_history_page' )
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current page hook.
	 */
	public function enqueue_styles( $hook ) {
		if ( strpos( $hook, 'mksddn-mc' ) === false && strpos( $hook, 'mksddn-migrate-content' ) === false ) {
			return;
		}

		wp_enqueue_style(
			'mksddn-mc-admin',
			MKSDDN_MC_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			MKSDDN_MC_VERSION
		);
	}

	/**
	 * Enqueue admin scripts.
	 *
	 * @param string $hook Current page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'mksddn-mc' ) === false && strpos( $hook, 'mksddn-migrate-content' ) === false ) {
			return;
		}

		wp_enqueue_script(
			'mksddn-mc-admin',
			MKSDDN_MC_PLUGIN_URL . 'admin/js/admin.js',
			array( 'jquery' ),
			MKSDDN_MC_VERSION,
			true
		);

		wp_localize_script(
			'mksddn-mc-admin',
			'mksddnMcAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'mksddn_mc_admin_nonce' ),
				'i18n'     => array(
					'exporting'        => __( 'Exporting...', 'mksddn-migrate-content' ),
					'importing'        => __( 'Importing...', 'mksddn-migrate-content' ),
					'success'          => __( 'Success!', 'mksddn-migrate-content' ),
					'error'            => __( 'Error occurred.', 'mksddn-migrate-content' ),
					'selectFile'       => __( 'Please select a file.', 'mksddn-migrate-content' ),
					'download'         => __( 'Download', 'mksddn-migrate-content' ),
					'selectPostType'   => __( 'Please select at least one post type.', 'mksddn-migrate-content' ),
					'selectPost'       => __( 'Please select at least one post to export.', 'mksddn-migrate-content' ),
					'loadingPosts'     => __( 'Loading posts...', 'mksddn-migrate-content' ),
					'noPostsFound'     => __( 'No posts found for selected post types.', 'mksddn-migrate-content' ),
					'errorLoadingPosts' => __( 'Error loading posts.', 'mksddn-migrate-content' ),
				),
			)
		);
	}

	/**
	 * Render main page.
	 */
	public function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		$system_status = $this->get_system_status();
		include MKSDDN_MC_PLUGIN_DIR . 'admin/views/main-page.php';
	}

	/**
	 * Render export page.
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		include MKSDDN_MC_PLUGIN_DIR . 'admin/views/export-page.php';
	}

	/**
	 * Render import page.
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		include MKSDDN_MC_PLUGIN_DIR . 'admin/views/import-page.php';
	}

	/**
	 * Render history page.
	 */
	public function render_history_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		$export_history = MksDdn_MC_Options_Helper::get_history( 'export', 50 );
		$import_history = MksDdn_MC_Options_Helper::get_history( 'import', 50 );

		include MKSDDN_MC_PLUGIN_DIR . 'admin/views/history-page.php';
	}

	/**
	 * Get system status.
	 *
	 * @return array
	 */
	private function get_system_status() {
		global $wpdb;

		return array(
			'php_version'     => PHP_VERSION,
			'wp_version'      => get_bloginfo( 'version' ),
			'memory_limit'    => ini_get( 'memory_limit' ),
			'max_upload_size' => size_format( wp_max_upload_size() ),
			'upload_dir_writable' => wp_is_writable( wp_upload_dir()['basedir'] ),
			'db_version'      => $wpdb->db_version(),
		);
	}

	/**
	 * Handle export AJAX request.
	 */
	public function handle_export_ajax() {
		check_ajax_referer( 'mksddn_mc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mksddn-migrate-content' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'full';
		$args = array();

		if ( 'selective' === $type ) {
			$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array();
			$slugs      = isset( $_POST['slugs'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['slugs'] ) ) : array();

			$args = array(
				'post_types' => $post_types,
				'slugs'      => $slugs,
			);
		}

		$export_handler = new MksDdn_MC_Export_Handler();
		$export_data = $export_handler->export( $type, $args );

		if ( is_wp_error( $export_data ) ) {
			wp_send_json_error( array( 'message' => $export_data->get_error_message() ) );
		}

		$file_path = $export_handler->create_export_file( $export_data );

		if ( is_wp_error( $file_path ) ) {
			wp_send_json_error( array( 'message' => $file_path->get_error_message() ) );
		}

		MksDdn_MC_Options_Helper::add_history_entry( 'export', array(
			'type'      => $type,
			'file_path' => $file_path,
			'file_size' => filesize( $file_path ),
		) );

		wp_send_json_success( array(
			'message'   => __( 'Export completed successfully.', 'mksddn-migrate-content' ),
			'file_path' => $file_path,
			'file_url'  => str_replace( wp_upload_dir()['basedir'], wp_upload_dir()['baseurl'], $file_path ),
		) );
	}

	/**
	 * Handle import AJAX request.
	 */
	public function handle_import_ajax() {
		check_ajax_referer( 'mksddn_mc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mksddn-migrate-content' ) ) );
		}

		if ( ! isset( $_FILES['import_file'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'mksddn-migrate-content' ) ) );
		}

		$file = $_FILES['import_file'];

		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			wp_send_json_error( array( 'message' => __( 'File upload error.', 'mksddn-migrate-content' ) ) );
		}

		$upload_overrides = array( 'test_form' => false );
		$uploaded_file = wp_handle_upload( $file, $upload_overrides );

		if ( isset( $uploaded_file['error'] ) ) {
			wp_send_json_error( array( 'message' => $uploaded_file['error'] ) );
		}

		$import_handler = new MksDdn_MC_Import_Handler();
		$result = $import_handler->import_from_file( $uploaded_file['file'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		MksDdn_MC_Options_Helper::add_history_entry( 'import', array(
			'file_path' => $uploaded_file['file'],
			'file_size' => filesize( $uploaded_file['file'] ),
			'result'    => $result,
		) );

		wp_send_json_success( array(
			'message' => __( 'Import completed successfully.', 'mksddn-migrate-content' ),
			'result'  => $result,
		) );
	}

	/**
	 * Handle get history AJAX request.
	 */
	public function handle_get_history_ajax() {
		check_ajax_referer( 'mksddn_mc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mksddn-migrate-content' ) ) );
		}

		$type = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : 'export';
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 20;

		$history = MksDdn_MC_Options_Helper::get_history( $type, $limit );

		wp_send_json_success( array( 'history' => $history ) );
	}

	/**
	 * Handle get posts AJAX request.
	 */
	public function handle_get_posts_ajax() {
		check_ajax_referer( 'mksddn_mc_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mksddn-migrate-content' ) ) );
		}

		$post_types = isset( $_POST['post_types'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['post_types'] ) ) : array();

		if ( empty( $post_types ) ) {
			wp_send_json_error( array( 'message' => __( 'No post types selected.', 'mksddn-migrate-content' ) ) );
		}

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		$posts_data = array();
		foreach ( $posts as $post ) {
			$posts_data[] = array(
				'ID'    => $post->ID,
				'title' => $post->post_title,
				'slug'  => $post->post_name,
				'type'  => $post->post_type,
			);
		}

		wp_send_json_success( array( 'posts' => $posts_data ) );
	}
}

