<?php
/**
 * @file: ImportRequestHandler.php
 * @description: Handler for import request operations
 * @dependencies: Admin\Services\SelectedContentImportService, Admin\Services\FullSiteImportService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Admin\Services\UnifiedImportOrchestrator;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Support\MimeTypeHelper;
use WP_Error;

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
	 * Constructor.
	 *
	 * @param SelectedContentImportService|null $selected_import_service Selected content import service.
	 * @param FullSiteImportService|null        $full_import_service     Full site import service.
	 * @param ImportTypeDetector|null           $type_detector           Import type detector.
	 * @param UnifiedImportOrchestrator|null    $orchestrator            Unified import orchestrator.
	 * @since 1.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null,
		?ImportTypeDetector $type_detector = null,
		?UnifiedImportOrchestrator $orchestrator = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
		$this->orchestrator             = $orchestrator ?? new UnifiedImportOrchestrator(
			$this->selected_import_service,
			$this->full_import_service
		);
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
	 * Handle unified import with automatic type detection.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_unified_import(): void {
		// Start output buffering early to catch any accidental output.
		if ( ! ob_get_level() ) {
			ob_start();
		}

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
			'chunk_job_id' => isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '',
			'server_file'  => isset( $_POST['server_file'] ) ? sanitize_text_field( wp_unslash( $_POST['server_file'] ) ) : '',
		);

		// Process unified import through orchestrator.
		$this->orchestrator->process( $request_data );
	}

}

