<?php
/**
 * @file: AdminPageController.php
 * @description: Main controller for admin page, coordinates handlers, views and services
 * @dependencies: All Admin components
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin;

use MksDdn\MigrateContent\Admin\Handlers\ExportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ImportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\RecoveryRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ScheduleRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\UserMergeRequestHandler;
use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\ProgressService;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Contracts\ExportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\RecoveryRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ScheduleRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\UserMergeRequestHandlerInterface;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Recovery\JobLock;
use MksDdn\MigrateContent\Recovery\SnapshotManager;
use MksDdn\MigrateContent\Support\FilenameBuilder;
use MksDdn\MigrateContent\Users\UserPreviewStore;

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
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Progress service.
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

	/**
	 * User preview store.
	 *
	 * @var UserPreviewStore
	 */
	private UserPreviewStore $preview_store;

	/**
	 * Constructor.
	 *
	 * @param AdminPageView|null                        $view              View instance.
	 * @param ExportRequestHandlerInterface|null        $export_handler    Export handler.
	 * @param ImportRequestHandlerInterface|null        $import_handler    Import handler.
	 * @param ScheduleRequestHandlerInterface|null      $schedule_handler  Schedule handler.
	 * @param RecoveryRequestHandlerInterface|null      $recovery_handler  Recovery handler.
	 * @param UserMergeRequestHandlerInterface|null     $user_merge_handler User merge handler.
	 * @param NotificationService|null                  $notifications    Notification service.
	 * @param ProgressService|null                      $progress          Progress service.
	 * @param UserPreviewStore|null                     $preview_store     User preview store.
	 * @since 1.0.0
	 */
	public function __construct(
		?AdminPageView $view = null,
		?ExportRequestHandlerInterface $export_handler = null,
		?ImportRequestHandlerInterface $import_handler = null,
		?ScheduleRequestHandlerInterface $schedule_handler = null,
		?RecoveryRequestHandlerInterface $recovery_handler = null,
		?UserMergeRequestHandlerInterface $user_merge_handler = null,
		?NotificationService $notifications = null,
		?ProgressService $progress = null,
		?UserPreviewStore $preview_store = null
	) {
		$this->notifications     = $notifications ?? new NotificationService();
		$this->progress          = $progress ?? new ProgressService();
		$this->preview_store     = $preview_store ?? new UserPreviewStore();
		$this->view              = $view ?? new AdminPageView();
		$this->export_handler    = $export_handler ?? new ExportRequestHandler( $this->notifications );
		$this->import_handler    = $import_handler ?? new ImportRequestHandler( null, null, null, null, $this->preview_store, $this->notifications, $this->progress );
		$this->schedule_handler  = $schedule_handler ?? new ScheduleRequestHandler( null, $this->notifications );
		$this->recovery_handler  = $recovery_handler ?? new RecoveryRequestHandler( null, null, null, $this->notifications );
		$this->user_merge_handler = $user_merge_handler ?? new UserMergeRequestHandler( $this->preview_store, $this->notifications );
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preview key arrives via redirect, read-only.
		if ( empty( $_GET['mksddn_mc_user_review'] ) ) {
			return null;
		}

		$preview_id = sanitize_text_field( wp_unslash( $_GET['mksddn_mc_user_review'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
}

