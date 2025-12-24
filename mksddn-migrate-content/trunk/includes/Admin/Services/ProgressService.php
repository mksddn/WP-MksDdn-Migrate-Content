<?php
/**
 * @file: ProgressService.php
 * @description: Service for managing progress bar updates
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Contracts\ProgressServiceInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for managing progress bar updates.
 *
 * @since 1.0.0
 */
class ProgressService implements ProgressServiceInterface {

	/**
	 * Render progress container HTML.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_container(): void {
		echo '<div id="mksddn-mc-progress" class="mksddn-mc-progress" aria-hidden="true">';
		echo '<div class="mksddn-mc-progress__bar"><span></span></div>';
		echo '<p class="mksddn-mc-progress__label"></p>';
		echo '</div>';
	}

	/**
	 * Render JavaScript helper for progress updates.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_javascript(): void {
		// JavaScript is now enqueued via wp_enqueue_script() in AdminPageController::enqueue_assets().
		// This method is kept for backward compatibility but no longer renders scripts.
	}

	/**
	 * Update progress bar.
	 *
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 * @since 1.0.0
	 */
	public function update( int $percent, string $message ): void {
		// Use wp_add_inline_script for dynamic progress updates.
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			sprintf(
				'if(window.mksddnMcProgress){window.mksddnMcProgress.set(%1$d, %2$s);}',
				absint( $percent ),
				wp_json_encode( $message )
			)
		);

		$this->flush_buffers();
	}

	/**
	 * Flush output buffers.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function flush_buffers(): void {
		if ( function_exists( 'ob_flush' ) ) {
			@ob_flush();
		}

		if ( function_exists( 'flush' ) ) {
			@flush();
		}
	}
}

