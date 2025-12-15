<?php
/**
 * @file: ProgressServiceInterface.php
 * @description: Contract for progress service operations
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for progress service operations.
 *
 * @since 1.0.0
 */
interface ProgressServiceInterface {

	/**
	 * Render progress container HTML.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_container(): void;

	/**
	 * Render JavaScript helper for progress updates.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_javascript(): void;

	/**
	 * Update progress bar.
	 *
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 * @since 1.0.0
	 */
	public function update( int $percent, string $message ): void;
}

