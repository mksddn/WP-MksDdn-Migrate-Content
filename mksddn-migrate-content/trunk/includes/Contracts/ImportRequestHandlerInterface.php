<?php
/**
 * @file: ImportRequestHandlerInterface.php
 * @description: Interface for import request handler
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for import request handler.
 *
 * @since 1.0.0
 */
interface ImportRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle selected content import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_import(): void;

	/**
	 * Handle full site import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_import(): void;
}

