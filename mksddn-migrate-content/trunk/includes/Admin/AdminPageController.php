<?php
/**
 * @file: AdminPageController.php
 * @description: Main controller for admin page, coordinates handlers, views and services
 * @dependencies: All Admin components
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin;

use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Contracts\ExportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\NotificationServiceInterface;
use MksDdn\MigrateContent\Contracts\ProgressServiceInterface;
use MksDdn\MigrateContent\Contracts\RecoveryRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ScheduleRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\UserMergeRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\UserPreviewStoreInterface;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Support\FilenameBuilder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main controller for admin page.
 *
 * @since 1.0.0
 */
class AdminPageController {

	/**
	 * View instance.
	 *
	 * @var AdminPageView
	 */
	private AdminPageView $view;

	/**
	 * Export handler.
	 *
	 * @var ExportRequestHandlerInterface
	 */
	private ExportRequestHandlerInterface $export_handler;

	/**
	 * Import handler.
	 *
	 * @var ImportRequestHandlerInterface
	 */
	private ImportRequestHandlerInterface $import_handler;

	/**
	 * Schedule handler.
	 *
	 * @var ScheduleRequestHandlerInterface
	 */
	private ScheduleRequestHandlerInterface $schedule_handler;

	/**
	 * Recovery handler.
	 *
	 * @var RecoveryRequestHandlerInterface
	 */
	private RecoveryRequestHandlerInterface $recovery_handler;

	/**
	 * User merge handler.
	 *
	 * @var UserMergeRequestHandlerInterface
	 */
	private UserMergeRequestHandlerInterface $user_merge_handler;

	/**
	 * Notification service.
	 *
	 * @var NotificationServiceInterface
	 */
	private NotificationServiceInterface $notifications;

	/**
	 * Progress service.
	 *
	 * @var ProgressServiceInterface
	 */
	private ProgressServiceInterface $progress;

	/**
	 * User preview store.
	 *
	 * @var UserPreviewStoreInterface
	 */
	private UserPreviewStoreInterface $preview_store;

	/**
	 * Server backup scanner.
	 *
	 * @var ServerBackupScanner
	 */
	private ServerBackupScanner $server_scanner;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainer $container Service container.
	 * @since 1.0.0
	 */
	public function __construct( ServiceContainer $container ) {
		$this->view              = $container->get( AdminPageView::class );
		$this->export_handler    = $container->get( ExportRequestHandlerInterface::class );
		$this->import_handler    = $container->get( ImportRequestHandlerInterface::class );
		$this->schedule_handler  = $container->get( ScheduleRequestHandlerInterface::class );
		$this->recovery_handler  = $container->get( RecoveryRequestHandlerInterface::class );
		$this->user_merge_handler = $container->get( UserMergeRequestHandlerInterface::class );
		$this->notifications     = $container->get( NotificationServiceInterface::class );
		$this->progress          = $container->get( ProgressServiceInterface::class );
		$this->preview_store     = $container->get( UserPreviewStoreInterface::class );
		$this->server_scanner    = $container->get( ServerBackupScanner::class );
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mksddn_mc_export_selected', array( $this->export_handler, 'handle_selected_export' ) );
		add_action( 'admin_post_mksddn_mc_export_full', array( $this->export_handler, 'handle_full_export' ) );
		add_action( 'admin_post_mksddn_mc_import_full', array( $this->import_handler, 'handle_full_import' ) );
		add_action( 'admin_post_mksddn_mc_rollback_snapshot', array( $this->recovery_handler, 'handle_rollback' ) );
		add_action( 'admin_post_mksddn_mc_delete_snapshot', array( $this->recovery_handler, 'handle_delete' ) );
		add_action( 'admin_post_mksddn_mc_cancel_user_preview', array( $this->user_merge_handler, 'handle_cancel_preview' ) );
		add_action( 'admin_post_mksddn_mc_schedule_save', array( $this->schedule_handler, 'handle_save' ) );
		add_action( 'admin_post_mksddn_mc_schedule_run', array( $this->schedule_handler, 'handle_run_now' ) );
		add_action( 'admin_post_mksddn_mc_download_scheduled', array( $this->schedule_handler, 'handle_download' ) );
		add_action( 'admin_post_mksddn_mc_delete_scheduled', array( $this->schedule_handler, 'handle_delete' ) );
		add_action( 'wp_ajax_mksddn_mc_get_server_backups', array( $this, 'handle_ajax_get_server_backups' ) );
	}

	/**
	 * Register admin menu page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			'manage_options',
			PluginConfig::text_domain(),
			array( $this, 'render_admin_page' ),
			'dashicons-migrate',
			20
		);
	}

	/**
	 * Render admin page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_admin_page(): void {
		// WordPress already checks 'manage_options' capability when registering the menu page.
		// However, if user is viewing import progress page (has mksddn_mc_import_status parameter),
		// we allow access even if session expired, as import continues in background.
		$has_import_status = ! empty( $_GET['mksddn_mc_import_status'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		
		if ( ! current_user_can( 'manage_options' ) ) {
			if ( $has_import_status ) {
				$this->render_import_progress_page();
				return;
			}
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'mksddn-migrate-content' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Migrate Content', 'mksddn-migrate-content' ) . '</h1>';

		$pending_user_preview = $this->maybe_load_user_preview();
		$this->notifications->render_status_notices();
		$this->progress->render_container();

		$this->view->render_styles();
		$this->view->render_full_site_section( $pending_user_preview );
		$this->view->render_selected_content_section();
		$this->view->render_history_section();
		$this->view->render_automation_section();

		$this->import_handler->handle_selected_import();

		echo '</div>';
		$this->progress->render_javascript();
	}

	/**
	 * Render minimal import progress page for expired sessions.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_import_progress_page(): void {
		echo '<!DOCTYPE html><html><head><title>' . esc_html__( 'Import Progress', 'mksddn-migrate-content' ) . '</title>';
		wp_head();
		echo '</head><body class="wp-admin wp-core-ui">';
		echo '<div class="wrap" style="max-width: 800px; margin: 50px auto;">';
		echo '<h1>' . esc_html__( 'Import Progress', 'mksddn-migrate-content' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your import is running in the background. This page will update automatically.', 'mksddn-migrate-content' ) . '</p>';
		$this->progress->render_container();
		$this->progress->render_javascript();
		echo '</div>';
		wp_footer();
		echo '</body></html>';
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . PluginConfig::text_domain() !== $hook ) {
			return;
		}

		// Enqueue admin styles.
		wp_enqueue_style(
			'mksddn-mc-admin-styles',
			PluginConfig::assets_url() . 'css/admin-styles.css',
			array(),
			PluginConfig::version()
		);

		// Enqueue admin scripts.
		wp_enqueue_script(
			'mksddn-mc-admin-scripts',
			PluginConfig::assets_url() . 'js/admin-scripts.js',
			array(),
			PluginConfig::version(),
			true
		);

		// Localize script with REST API settings.
		wp_localize_script(
			'mksddn-mc-admin-scripts',
			'wpApiSettings',
			array(
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Enqueue server file selector script.
		wp_enqueue_script(
			'mksddn-server-file-selector',
			PluginConfig::assets_url() . 'js/server-file-selector.js',
			array(),
			PluginConfig::version(),
			true
		);

		wp_localize_script(
			'mksddn-server-file-selector',
			'mksddnServerFileSelector',
			array(
				'ajaxAction' => 'mksddn_mc_get_server_backups',
				'nonce'     => wp_create_nonce( 'mksddn_mc_admin' ),
				'i18n'      => array(
					'loading'     => __( 'Loading...', 'mksddn-migrate-content' ),
					'selectFile'  => __( 'Select a file...', 'mksddn-migrate-content' ),
					'noFiles'     => __( 'No backup files found', 'mksddn-migrate-content' ),
					'loadError'   => __( 'Error loading files', 'mksddn-migrate-content' ),
					'pleaseSelect' => __( 'Please select a file from the server.', 'mksddn-migrate-content' ),
				),
			)
		);

		if ( ! PluginConfig::is_chunked_disabled() ) {
			wp_enqueue_script(
				'mksddn-chunk-transfer',
				PluginConfig::assets_url() . 'js/chunk-transfer.js',
				array(),
				PluginConfig::version(),
				true
			);

			$default_chunk = PluginConfig::chunk_size();
			wp_localize_script(
				'mksddn-chunk-transfer',
				'mksddnChunk',
				array(
					'restUrl'                => esc_url_raw( rest_url( 'mksddn/v1/' ) ),
					'nonce'                  => wp_create_nonce( 'wp_rest' ),
					'chunkSize'              => 5 * 1024 * 1024,
					'uploadChunkSize'        => $default_chunk,
					'downloadFilename'       => FilenameBuilder::build( 'full-site', 'wpbkp' ),
					'defaultChunkSizeLabel'  => size_format( $default_chunk, 2 ),
					'i18n'                   => array(
						/* translators: %d: upload progress percent. */
						'uploading'          => __( 'Uploading chunks… %d%', 'mksddn-migrate-content' ),
						'uploadError'        => __( 'Chunked upload failed. Please try again.', 'mksddn-migrate-content' ),
						/* translators: 1: selected label, 2: chunk size label. */
						'importSelected'     => __( 'Selected %1$s (planned chunk %2$s).', 'mksddn-migrate-content' ),
						/* translators: %d: upload progress percent. */
						'importBusy'         => __( 'Uploading archive… %d%', 'mksddn-migrate-content' ),
						'importDone'         => __( 'Upload finished. Processing…', 'mksddn-migrate-content' ),
						'importProcessing'   => __( 'Server is processing the archive…', 'mksddn-migrate-content' ),
						'importError'        => __( 'Upload failed. Please retry.', 'mksddn-migrate-content' ),
						/* translators: %s: chunk size label. */
						'chunkInfo'          => __( '· %s chunks', 'mksddn-migrate-content' ),
						'preparing'        => __( 'Preparing download…', 'mksddn-migrate-content' ),
						/* translators: %d: download progress percent. */
						'downloading'      => __( 'Downloading chunks… %d%', 'mksddn-migrate-content' ),
						'downloadComplete' => __( 'Download complete.', 'mksddn-migrate-content' ),
						'downloadError'    => __( 'Chunked download failed. Falling back to direct download.', 'mksddn-migrate-content' ),
						'exportReady'      => __( 'Ready for full export.', 'mksddn-migrate-content' ),
						'exportBusy'       => __( 'Preparing archive…', 'mksddn-migrate-content' ),
						/* translators: %d: streaming progress percent. */
						'exportTransfer'   => __( 'Streaming archive… %d%', 'mksddn-migrate-content' ),
						'exportDone'       => __( 'Archive downloaded.', 'mksddn-migrate-content' ),
						'exportFallback'   => __( 'Falling back to classic download…', 'mksddn-migrate-content' ),
					),
				)
			);
		}
	}

	/**
	 * Load preview context when user selection query is present.
	 *
	 * @return array|null Preview data or null.
	 * @since 1.0.0
	 */
	private function maybe_load_user_preview(): ?array {
		if ( empty( $_GET['mksddn_mc_user_review'] ) ) {
			return null;
		}

		// Check nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mksddn_mc_user_preview' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mksddn-migrate-content' ) );
		}

		$preview_id = sanitize_text_field( wp_unslash( $_GET['mksddn_mc_user_review'] ) );
		$preview    = $this->preview_store->get( $preview_id );

		if ( ! $preview ) {
			$this->notifications->show_inline_notice( 'error', __( 'User selection session expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
			return null;
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->notifications->show_inline_notice( 'error', __( 'You are not allowed to continue this user selection.', 'mksddn-migrate-content' ) );
			return null;
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		if ( empty( $summary['incoming'] ) ) {
			$this->notifications->show_inline_notice( 'error', __( 'User selection data is empty. Restart the import.', 'mksddn-migrate-content' ) );
			return null;
		}

		return array(
			'id'            => $preview_id,
			'original_name' => $preview['original_name'] ?? '',
			'summary'       => $summary,
		);
	}

	/**
	 * Handle AJAX request to get list of server backup files.
	 *
	 * @return void
	 * @since 1.0.1
	 */
	public function handle_ajax_get_server_backups(): void {
		// Verify nonce from POST data.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified below.
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mksddn_mc_admin' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mksddn-migrate-content' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mksddn-migrate-content' ) ) );
		}

		$files = $this->server_scanner->scan();

		if ( is_wp_error( $files ) ) {
			wp_send_json_error( array( 'message' => $files->get_error_message() ) );
		}

		wp_send_json_success( array( 'files' => $files ) );
	}
}

