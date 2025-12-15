<?php
/**
 * @file: ImportRequestHandler.php
 * @description: Handler for import request operations
 * @dependencies: Admin\Services\SelectedContentImportService, Admin\Services\FullSiteImportService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;

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
	 * Constructor.
	 *
	 * @param SelectedContentImportService|null $selected_import_service Selected content import service.
	 * @param FullSiteImportService|null        $full_import_service     Full site import service.
	 * @since 1.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
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

}

