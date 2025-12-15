<?php
/**
 * @file: RecoveryRequestHandlerInterface.php
 * @description: Interface for recovery request handler
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for recovery request handler.
 *
 * @since 1.0.0
 */
interface RecoveryRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle snapshot rollback.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_rollback(): void;

	/**
	 * Handle snapshot delete.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_delete(): void;
}

