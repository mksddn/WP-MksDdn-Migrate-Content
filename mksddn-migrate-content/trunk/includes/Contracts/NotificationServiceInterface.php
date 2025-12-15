<?php
/**
 * @file: NotificationServiceInterface.php
 * @description: Contract for notification service operations
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for notification service operations.
 *
 * @since 1.0.0
 */
interface NotificationServiceInterface {

	/**
	 * Show success notice.
	 *
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_success( string $message ): void;

	/**
	 * Show error notice.
	 *
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_error( string $message ): void;

	/**
	 * Show inline notice.
	 *
	 * @param string $type    Notice type: error|success|warning|info.
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_inline_notice( string $type, string $message ): void;

	/**
	 * Redirect with status notice.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional message.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_with_notice( string $status, ?string $message = null ): void;

	/**
	 * Redirect with full status.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional error message.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_full_status( string $status, ?string $message = null ): void;

	/**
	 * Render status notices from query parameters.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_status_notices(): void;
}

