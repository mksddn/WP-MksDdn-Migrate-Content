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

	/**
	 * Toggle all user import checkboxes on the full-site import review step.
	 */
	function initUserPlanToggle() {
		const selectAllButton = document.querySelector('.mksddn-mc-user-select-all');
		const deselectAllButton = document.querySelector('.mksddn-mc-user-deselect-all');
		const checkboxes = document.querySelectorAll('.mksddn-mc-user-import-checkbox');
		const selectionCount = document.querySelector('.mksddn-mc-user-selection-count');

		if (checkboxes.length === 0) {
			return;
		}

		function getCheckedCount() {
			return Array.from(checkboxes).filter(function(checkbox) {
				return checkbox.checked;
			}).length;
		}

		function updateSelectionState() {
			const checkedCount = getCheckedCount();
			const label = selectionCount ? selectionCount.getAttribute('data-label') : '';

			if (selectionCount && label) {
				selectionCount.textContent = label
					.replace('%1$d', checkedCount)
					.replace('%2$d', checkboxes.length);
			}

			if (selectAllButton) {
				selectAllButton.disabled = checkedCount === checkboxes.length;
			}

			if (deselectAllButton) {
				deselectAllButton.disabled = checkedCount === 0;
			}
		}

		if (selectAllButton) {
			selectAllButton.addEventListener('click', function() {
				checkboxes.forEach(function(checkbox) {
					checkbox.checked = true;
				});
				updateSelectionState();
			});
		}

		if (deselectAllButton) {
			deselectAllButton.addEventListener('click', function() {
				checkboxes.forEach(function(checkbox) {
					checkbox.checked = false;
				});
				updateSelectionState();
			});
		}

		checkboxes.forEach(function(checkbox) {
			checkbox.addEventListener('change', updateSelectionState);
		});

		updateSelectionState();
	}

	/**
	 * Show progress while long-running import forms submit via native POST + redirect.
	 */
	function initFinalImportSubmitHandler() {
		const i18n = (window.mksddnMcAdmin || {}).i18n || {};
		const forms = document.querySelectorAll('.mksddn-mc-preflight-import-form, .mksddn-mc-user-plan, .mksddn-mc-theme-plan');

		forms.forEach(function(form) {
			let busy = false;

			form.addEventListener('submit', function(event) {
				if (busy) {
					event.preventDefault();
					return;
				}

				busy = true;

				const button = form.querySelector('button[type="submit"]');
				if (button) {
					button.disabled = true;
				}

				if (window.mksddnMcProgress && typeof window.mksddnMcProgress.set === 'function') {
					window.mksddnMcProgress.set(
						15,
						i18n.importProcessing || ''
					);
				}
			});
		});
	}

	/**
	 * Toggle Replace/Merge summary blocks on the theme preview screen.
	 */
	function initThemeModeSummaryToggle() {
		const form = document.querySelector('.mksddn-mc-theme-plan');
		if (!form) {
			return;
		}

		const radios = form.querySelectorAll('input[name="import_mode"]');
		const summaries = document.querySelectorAll('.mksddn-mc-theme-mode-summary');
		if (!radios.length || !summaries.length) {
			return;
		}

		function applyMode(mode) {
			summaries.forEach(function(block) {
				const blockMode = block.getAttribute('data-mode') || '';
				block.hidden = blockMode !== mode;
			});
		}

		radios.forEach(function(radio) {
			radio.addEventListener('change', function() {
				if (radio.checked) {
					applyMode(radio.value);
				}
			});
		});

		const checked = form.querySelector('input[name="import_mode"]:checked');
		applyMode(checked ? checked.value : 'replace');
	}

	// Initialize progress bar and import helpers when DOM is ready.
	function init() {
		window.mksddnMcProgress = initProgressBar();
		initUserPlanToggle();
		initFinalImportSubmitHandler();
		initThemeModeSummaryToggle();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

