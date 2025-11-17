<?php
/**
 * Main controller
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Main controller class
 */
class MksDdn_MC_Main_Controller {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize plugin
	 *
	 * @return void
	 */
	private function init() {
		// Activation hook
		register_activation_hook( MKSDDN_MC_PLUGIN_BASENAME, array( $this, 'activate' ) );

		// Deactivation hook
		register_deactivation_hook( MKSDDN_MC_PLUGIN_BASENAME, array( $this, 'deactivate' ) );

		// Admin init
		add_action( 'admin_init', array( $this, 'admin_init' ) );

		// Admin menu
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );

		// Admin enqueue scripts
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	/**
	 * Plugin activation
	 *
	 * @return void
	 */
	public function activate() {
		// Create storage directory
		if ( ! is_dir( MKSDDN_MC_STORAGE_PATH ) ) {
			wp_mkdir_p( MKSDDN_MC_STORAGE_PATH );
		}

		// Create backups directory
		if ( ! is_dir( MKSDDN_MC_BACKUPS_PATH ) ) {
			wp_mkdir_p( MKSDDN_MC_BACKUPS_PATH );
		}

		// Create protection files
		$this->create_protection_files();
	}

	/**
	 * Plugin deactivation
	 *
	 * @return void
	 */
	public function deactivate() {
		// Cleanup if needed
	}

	/**
	 * Admin init
	 *
	 * @return void
	 */
	public function admin_init() {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Initialize storage
		$this->init_storage();

		// Register AJAX handlers
		add_action( 'wp_ajax_mksddn_mc_export', array( 'MksDdn_MC_Export_Controller', 'export' ) );
		add_action( 'wp_ajax_mksddn_mc_download', array( 'MksDdn_MC_Export_Controller', 'download' ) );
		add_action( 'wp_ajax_mksddn_mc_status', array( 'MksDdn_MC_Status_Controller', 'status' ) );
	}

	/**
	 * Admin menu
	 *
	 * @return void
	 */
	public function admin_menu() {
		add_menu_page(
			__( 'MksDdn Migrate Content', 'mksddn-migrate-content' ),
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-mc',
			array( $this, 'render_main_page' ),
			'dashicons-database-export',
			80
		);

		add_submenu_page(
			'mksddn-mc',
			__( 'Export', 'mksddn-migrate-content' ),
			__( 'Export', 'mksddn-migrate-content' ),
			'manage_options',
			'mksddn-mc-export',
			array( 'MksDdn_MC_Export_Controller', 'index' )
		);
	}

	/**
	 * Admin enqueue scripts
	 *
	 * @param string $hook Current page hook
	 * @return void
	 */
	public function admin_enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'mksddn-mc' ) === false ) {
			return;
		}

		// Enqueue styles
		wp_enqueue_style(
			'mksddn-mc-admin',
			MKSDDN_MC_URL . '/assets/css/admin.css',
			array(),
			MKSDDN_MC_VERSION
		);

		// Enqueue scripts
		wp_enqueue_script(
			'mksddn-mc-admin',
			MKSDDN_MC_URL . '/assets/js/admin.js',
			array( 'jquery' ),
			MKSDDN_MC_VERSION,
			true
		);

		// Localize script
		wp_localize_script(
			'mksddn-mc-admin',
			'mksddnMc',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mksddn_mc_nonce' ),
			)
		);
	}

	/**
	 * Render main page
	 *
	 * @return void
	 */
	public function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have sufficient permissions to access this page.', 'mksddn-migrate-content' ) );
		}

		include MKSDDN_MC_TEMPLATES_PATH . '/main/index.php';
	}

	/**
	 * Initialize storage
	 *
	 * @return void
	 */
	private function init_storage() {
		if ( ! is_dir( MKSDDN_MC_STORAGE_PATH ) ) {
			wp_mkdir_p( MKSDDN_MC_STORAGE_PATH );
		}

		if ( ! is_dir( MKSDDN_MC_BACKUPS_PATH ) ) {
			wp_mkdir_p( MKSDDN_MC_BACKUPS_PATH );
		}

		$this->create_protection_files();
	}

	/**
	 * Create protection files
	 *
	 * @return void
	 */
	private function create_protection_files() {
		// Create index.php in storage
		if ( ! file_exists( MKSDDN_MC_STORAGE_INDEX_PHP ) ) {
			file_put_contents( MKSDDN_MC_STORAGE_INDEX_PHP, '<?php // Silence is golden' );
		}

		// Create index.html in storage
		if ( ! file_exists( MKSDDN_MC_STORAGE_INDEX_HTML ) ) {
			file_put_contents( MKSDDN_MC_STORAGE_INDEX_HTML, '' );
		}

		// Create index.php in backups
		if ( ! file_exists( MKSDDN_MC_BACKUPS_INDEX_PHP ) ) {
			file_put_contents( MKSDDN_MC_BACKUPS_INDEX_PHP, '<?php // Silence is golden' );
		}

		// Create index.html in backups
		if ( ! file_exists( MKSDDN_MC_BACKUPS_INDEX_HTML ) ) {
			file_put_contents( MKSDDN_MC_BACKUPS_INDEX_HTML, '' );
		}
	}
}

