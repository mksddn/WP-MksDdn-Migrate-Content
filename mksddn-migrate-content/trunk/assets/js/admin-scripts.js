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
	 * Poll import status via REST API.
	 *
	 * @param {string} historyId History entry ID.
	 */
	function pollImportStatus(historyId) {
		if (!historyId || !window.mksddnMcProgress) {
			console.warn('MksDdn Migrate: Missing historyId or progress bar');
			return;
		}

		// Show progress bar immediately.
		window.mksddnMcProgress.set(0, 'Starting import...');

		const restUrl = window.wpApiSettings?.root + 'mksddn/v1/import/status';
		const nonce = window.wpApiSettings?.nonce;

		if (!restUrl || !nonce) {
			console.error('MksDdn Migrate: REST API settings not found', window.wpApiSettings);
			return;
		}

		console.log('MksDdn Migrate: Starting polling for history ID:', historyId);

		let errorCount = 0;
		const maxErrors = 30;

		function poll() {
			const url = restUrl + '?history_id=' + encodeURIComponent(historyId);
			console.log('MksDdn Migrate: Polling', url);

			fetch(url, {
				headers: {
					'X-WP-Nonce': nonce
				}
			})
			.then(function(response) {
				console.log('MksDdn Migrate: Response status', response.status);
				if (!response.ok) {
					errorCount++;
					console.error('MksDdn Migrate: HTTP error', response.status, 'attempt', errorCount);
					if (errorCount > maxErrors) {
						window.mksddnMcProgress.set(100, 'Redirecting...');
						setTimeout(function() {
							const url = new URL(window.location);
							url.searchParams.delete('mksddn_mc_import_status');
							window.location.href = url.toString();
						}, 500);
						return;
					}
					setTimeout(poll, 2000);
					throw new Error('HTTP error');
				}
				return response.json();
			})
			.then(function(data) {
				if (!data) {
					console.warn('MksDdn Migrate: Empty response data');
					return;
				}
				console.log('MksDdn Migrate: Status data', data);
				errorCount = 0;
				const progress = data.progress || {};
				const percent = progress.percent || 0;
				const message = progress.message || 'Processing...';
				
				window.mksddnMcProgress.set(percent, message);

				if (data.status === 'running') {
					setTimeout(poll, 1000);
				} else {
					console.log('MksDdn Migrate: Import completed with status', data.status);
					window.mksddnMcProgress.set(100, 'Complete!');
					// Remove the status parameter from URL to prevent re-polling on page reload.
					setTimeout(function() {
						const url = new URL(window.location);
						url.searchParams.delete('mksddn_mc_import_status');
						window.location.href = url.toString();
					}, 1000);
				}
			})
			.catch(function(error) {
				console.error('MksDdn Migrate: Fetch error', error);
				if (errorCount < maxErrors) {
					errorCount++;
					setTimeout(poll, 2000);
				} else {
					window.mksddnMcProgress.set(100, 'Redirecting...');
					setTimeout(function() {
						const url = new URL(window.location);
						url.searchParams.delete('mksddn_mc_import_status');
						window.location.href = url.toString();
					}, 500);
				}
			});
		}

		poll();
	}

	/**
	 * Check for import status parameter on page load.
	 */
	function checkImportStatus() {
		const urlParams = new URLSearchParams(window.location.search);
		const historyId = urlParams.get('mksddn_mc_import_status');
		
		if (historyId) {
			pollImportStatus(historyId);
		}
	}

	// Initialize progress bar when DOM is ready.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			window.mksddnMcProgress = initProgressBar();
			checkImportStatus();
		});
	} else {
		window.mksddnMcProgress = initProgressBar();
		checkImportStatus();
	}
})();

