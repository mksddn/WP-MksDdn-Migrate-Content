<?php
/**
 * @file: ScheduleRequestHandlerInterface.php
 * @description: Interface for schedule request handler
 * @dependencies: Contracts\RequestHandlerInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for schedule request handler.
 *
 * @since 1.0.0
 */
interface ScheduleRequestHandlerInterface extends RequestHandlerInterface {

	/**
	 * Handle schedule save.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_save(): void;

	/**
	 * Handle schedule run now.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_run_now(): void;

	/**
	 * Handle download scheduled backup.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_download(): void;

	/**
	 * Handle delete scheduled backup.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_delete(): void;
}

