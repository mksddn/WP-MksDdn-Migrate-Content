<?php
/**
 * @file: ThemePreviewRequestHandlerInterface.php
 * @description: Contract for theme preview request handling
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2026-02-21
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for theme preview request handling.
 *
 * @since 2.1.0
 */
interface ThemePreviewRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle cancel theme preview.
	 *
	 * @return void
	 */
	public function handle_cancel_preview(): void;
}

