/**
 * Admin scripts for MksDdn Migrate Content plugin.
 *
 * @package MksDdn_Migrate_Content
 */

(function() {
	'use strict';

	/**
	 * Initialize progress bar functionality.
	 */
	function initProgressBar() {
		const container = document.getElementById('mksddn-mc-progress');
		if (!container) {
			return null;
		}

		const bar = container.querySelector('.mksddn-mc-progress__bar span');
		const label = container.querySelector('.mksddn-mc-progress__label');

		return {
			set: function(percent, text) {
				if (!bar) {
					return;
				}
				container.setAttribute('aria-hidden', 'false');
				const clamped = Math.max(0, Math.min(100, percent));
				bar.style.width = clamped + '%';
				if (label) {
					label.textContent = text || '';
				}
			},
			hide: function() {
				if (!bar) {
					return;
				}
				container.setAttribute('aria-hidden', 'true');
				bar.style.width = '0%';
				if (label) {
					label.textContent = '';
				}
			}
		};
	}

	// Initialize progress bar when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			window.mksddnMcProgress = initProgressBar();
		});
	} else {
		window.mksddnMcProgress = initProgressBar();
	}
})();

