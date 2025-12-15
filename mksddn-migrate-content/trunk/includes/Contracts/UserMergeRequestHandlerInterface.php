<?php
/**
 * @file: UserMergeRequestHandlerInterface.php
 * @description: Interface for user merge request handler
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for user merge request handler.
 *
 * @since 1.0.0
 */
interface UserMergeRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle cancel user preview.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_cancel_preview(): void;
}

