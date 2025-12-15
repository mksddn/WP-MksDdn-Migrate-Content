<?php
/**
 * @file: ExportRequestHandlerInterface.php
 * @description: Interface for export request handler
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for export request handler.
 *
 * @since 1.0.0
 */
interface ExportRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle selected content export.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_export(): void;

	/**
	 * Handle full site export.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_export(): void;
}

