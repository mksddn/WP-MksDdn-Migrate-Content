<?php
/**
 * @file: ImportRequestHandler.php
 * @description: Handler for import request operations
 * @dependencies: Admin\Services\SelectedContentImportService, Admin\Services\FullSiteImportService, Admin\Services\ThemeImportService, Admin\Services\UnifiedImportOrchestrator, Admin\Services\PreflightReportStore, Admin\Services\NotificationService, Support\ImportArtifactCleanup
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\PreflightReportStore;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Admin\Services\ThemeImportService;
use MksDdn\MigrateContent\Admin\Services\UnifiedImportOrchestrator;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\NotificationServiceInterface;
use MksDdn\MigrateContent\Support\ImportArtifactCleanup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for import request operations.
 *
 * @since 1.0.0
 */
class ImportRequestHandler implements ImportRequestHandlerInterface {

	/**
	 * Selected content import service.
	 *
	 * @var SelectedContentImportService
	 */
	private SelectedContentImportService $selected_import_service;

	/**
	 * Full site import service.
	 *
	 * @var FullSiteImportService
	 */
	private FullSiteImportService $full_import_service;

	/**
	 * Unified import orchestrator.
	 *
	 * @var UnifiedImportOrchestrator
	 */
	private UnifiedImportOrchestrator $orchestrator;

	/**
	 * Theme import service.
	 *
	 * @var ThemeImportService
	 */
	private ThemeImportService $theme_import_service;

	/**
	 * Preflight report store.
	 *
	 * @var PreflightReportStore
	 */
	private PreflightReportStore $preflight_report_store;

	/**
	 * Notification service.
	 *
	 * @var NotificationServiceInterface
	 */
	private NotificationServiceInterface $notifications;

	/**
	 * Constructor.
	 *
	 * @param SelectedContentImportService|null  $selected_import_service Selected content import service.
	 * @param FullSiteImportService|null         $full_import_service     Full site import service.
	 * @param ImportTypeDetector|null            $type_detector           Import type detector.
	 * @param UnifiedImportOrchestrator|null     $orchestrator            Unified import orchestrator.
	 * @param ThemeImportService|null            $theme_import_service    Theme import service.
	 * @param PreflightReportStore|null          $preflight_report_store  Preflight report store.
	 * @param NotificationServiceInterface|null  $notifications           Notification service.
	 * @since 1.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null,
		?ImportTypeDetector $type_detector = null,
		?UnifiedImportOrchestrator $orchestrator = null,
		?ThemeImportService $theme_import_service = null,
		?PreflightReportStore $preflight_report_store = null,
		?NotificationServiceInterface $notifications = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
		$this->orchestrator             = $orchestrator ?? new UnifiedImportOrchestrator(
			$this->selected_import_service,
			$this->full_import_service
		);
		$this->theme_import_service     = $theme_import_service ?? new ThemeImportService();
		$this->preflight_report_store   = $preflight_report_store ?? new PreflightReportStore();
		$this->notifications            = $notifications ?? new NotificationService();
	}

	/**
	 * Handle selected content import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_import(): void {
		$this->selected_import_service->import();
	}

	/**
	 * Handle full site import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_import(): void {
		$this->full_import_service->import();
	}

	/**
	 * Handle theme import.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	public function handle_theme_import(): void {
		$this->theme_import_service->import();
	}

	/**
	 * Handle unified import with automatic type detection.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_unified_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_unified_import' );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		// Extract request data.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$request_data = array(
			'preflight_report_id' => isset( $_POST['preflight_report_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preflight_report_id'] ) ) : '',
			'chunk_job_id'        => isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '',
			'chunk_original_name' => isset( $_POST['chunk_original_name'] ) ? sanitize_file_name( wp_unslash( (string) $_POST['chunk_original_name'] ) ) : '',
			'server_file'         => isset( $_POST['server_file'] ) ? sanitize_text_field( wp_unslash( $_POST['server_file'] ) ) : '',
			'import_source'       => isset( $_POST['import_source'] ) ? sanitize_text_field( wp_unslash( $_POST['import_source'] ) ) : '',
		);

		// Process unified import through orchestrator.
		$this->orchestrator->process( $request_data );
	}

	/**
	 * Dismiss a preflight report and keep the archive under imports/ for reuse.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	public function handle_dismiss_preflight_report(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$report_id = isset( $_POST['preflight_report_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preflight_report_id'] ) ) : '';
		check_admin_referer( 'mksddn_mc_dismiss_preflight_' . $report_id );

		if ( '' === $report_id ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Preflight report identifier is missing.', 'mksddn-migrate-content' ) );
		}

		$user_id = (int) get_current_user_id();
		$bucket  = $this->preflight_report_store->get_bucket_for_user( $report_id, $user_id );
		if ( ! $bucket ) {
			$this->notifications->redirect_with_notice(
				'error',
				__( 'Preflight report not found or has expired.', 'mksddn-migrate-content' )
			);
		}

		$kept = false;
		if ( ! empty( $bucket['import_handle'] ) && is_array( $bucket['import_handle'] ) ) {
			$kept = ImportArtifactCleanup::persist_import_handle_for_reuse( $bucket['import_handle'] );
		}

		$this->preflight_report_store->delete_for_user( $report_id, $user_id );

		if ( $kept ) {
			$this->notifications->redirect_with_notice(
				'success',
				__( 'Preflight report dismissed. The backup was kept under Server files for reuse.', 'mksddn-migrate-content' )
			);
		}

		$this->notifications->redirect_with_notice(
			'error',
			__( 'Preflight report dismissed, but the backup could not be moved to Server files. Re-upload the archive if you need it again.', 'mksddn-migrate-content' )
		);
	}

}

