<?php
/**
 * Main plugin class.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class MksDdn_Migrate_Content {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Instance of this class.
	 *
	 * @var MksDdn_Migrate_Content
	 */
	private static $instance = null;

	/**
	 * Get instance of this class.
	 *
	 * @return MksDdn_Migrate_Content
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
	}

	/**
	 * Load required dependencies.
	 */
	private function load_dependencies() {
		require_once MKSDDN_MC_PLUGIN_DIR . 'includes/class-export-handler.php';
		require_once MKSDDN_MC_PLUGIN_DIR . 'includes/class-import-handler.php';
		require_once MKSDDN_MC_PLUGIN_DIR . 'includes/class-options-helper.php';

		if ( is_admin() ) {
			require_once MKSDDN_MC_PLUGIN_DIR . 'admin/class-export-import-admin.php';
		}
	}

	/**
	 * Define locale for internationalization.
	 */
	private function set_locale() {
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain(
			'mksddn-migrate-content',
			false,
			dirname( MKSDDN_MC_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Register admin hooks.
	 */
	private function define_admin_hooks() {
		if ( ! is_admin() ) {
			return;
		}

		$admin = new MksDdn_MC_Export_Import_Admin();
		add_action( 'admin_menu', array( $admin, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_mksddn_mc_export', array( $admin, 'handle_export_ajax' ) );
		add_action( 'wp_ajax_mksddn_mc_import', array( $admin, 'handle_import_ajax' ) );
		add_action( 'wp_ajax_mksddn_mc_get_history', array( $admin, 'handle_get_history_ajax' ) );
	}

	/**
	 * Register public hooks.
	 */
	private function define_public_hooks() {
		// Public hooks if needed.
	}

	/**
	 * Run the plugin.
	 */
	public function run() {
		// Plugin initialization.
	}

	/**
	 * Activation hook.
	 */
	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Create necessary database tables or options.
		$options_helper = new MksDdn_MC_Options_Helper();
		$options_helper->init_default_options();

		// Flush rewrite rules if needed.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		// Cleanup if needed.
		flush_rewrite_rules();
	}
}

