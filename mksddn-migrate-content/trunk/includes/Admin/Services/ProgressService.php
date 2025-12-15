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
		echo '<style>
		#mksddn-mc-progress{margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#fff;display:none;}
		#mksddn-mc-progress[aria-hidden="false"]{display:block;}
		#mksddn-mc-progress .mksddn-mc-progress__bar{width:100%;height:12px;background:#f0f0f0;border-radius:999px;overflow:hidden;margin-bottom:0.5rem;}
		#mksddn-mc-progress .mksddn-mc-progress__bar span{display:block;height:100%;width:0%;background:#2c7be5;transition:width .3s ease;}
		#mksddn-mc-progress .mksddn-mc-progress__label{margin:0;font-size:13px;color:#444;}
		</style>';

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
		echo '<script>
		window.mksddnMcProgress = (function(){
			const container = document.getElementById("mksddn-mc-progress");
			if(!container){return null;}
			const bar = container.querySelector(".mksddn-mc-progress__bar span");
			const label = container.querySelector(".mksddn-mc-progress__label");
			return {
				set(percent, text){
					if(!bar){return;}
					container.setAttribute("aria-hidden","false");
					const clamped = Math.max(0, Math.min(100, percent));
					bar.style.width = clamped + "%";
					if(label){ label.textContent = text || ""; }
				},
				hide(){
					if(!bar){return;}
					container.setAttribute("aria-hidden","true");
					bar.style.width = "0%";
					if(label){ label.textContent = ""; }
				}
			}
		})();
		</script>';
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
		printf(
			'<script>window.mksddnMcProgress && window.mksddnMcProgress.set(%1$d, %2$s);</script>',
			absint( $percent ),
			wp_json_encode( $message )
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

