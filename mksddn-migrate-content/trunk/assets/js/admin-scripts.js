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
	 * Initialize AJAX search functionality for select elements.
	 */
	function initSelectSearch() {
		const searchInputs = document.querySelectorAll('.mksddn-mc-search-input');
		
		if (searchInputs.length === 0) {
			return;
		}

		// Check if AJAX config is available.
		if (!window.mksddnMcSearch || !window.mksddnMcSearch.ajaxUrl || !window.mksddnMcSearch.nonce) {
			console.warn('MksDdn Migrate Content: AJAX search configuration not found.');
			return;
		}

		searchInputs.forEach(function(searchInput) {
			const targetId = searchInput.getAttribute('data-target');
			const postType = searchInput.getAttribute('data-post-type');
			
			if (!targetId || !postType) {
				return;
			}

			const select = document.getElementById(targetId);
			const hiddenInput = document.querySelector('.mksddn-mc-selected-ids[data-post-type="' + postType + '"]');
			
			if (!select || !hiddenInput) {
				return;
			}

			let searchTimeout = null;
			let currentRequest = null;
			const selectedIds = new Set();
			const selectedOptions = new Map(); // Store selected options: id => {id, label}

			// Debounce function.
			function debounce(func, wait) {
				return function() {
					const context = this;
					const args = arguments;
					clearTimeout(searchTimeout);
					searchTimeout = setTimeout(function() {
						func.apply(context, args);
					}, wait);
				};
			}

			// Cancel current request if exists.
			function cancelCurrentRequest() {
				if (currentRequest && currentRequest.readyState !== 4) {
					currentRequest.abort();
				}
			}

			// Update hidden input with selected IDs.
			function updateHiddenInput() {
				const idsArray = Array.from(selectedIds).filter(function(id) {
					return id && id !== '';
				});
				hiddenInput.value = idsArray.join(',');
			}

			// Add selected option to select if not present.
			function ensureSelectedOption(id, label) {
				const existingOption = select.querySelector('option[value="' + id + '"]');
				if (!existingOption) {
					const option = document.createElement('option');
					option.value = id;
					option.textContent = label;
					option.selected = true;
					select.appendChild(option);
				}
			}

			// Load posts from server.
			function loadPosts(searchTerm, page) {
				cancelCurrentRequest();

				// Show loading state.
				select.innerHTML = '<option value="" disabled>' + window.mksddnMcSearch.i18n.loading + '</option>';

				const formData = new FormData();
				formData.append('action', 'mksddn_mc_search_posts');
				formData.append('nonce', window.mksddnMcSearch.nonce);
				formData.append('post_type', postType);
				formData.append('search', searchTerm || '');
				formData.append('page', page || 1);

				currentRequest = new XMLHttpRequest();
				currentRequest.open('POST', window.mksddnMcSearch.ajaxUrl, true);
				
				currentRequest.onload = function() {
					if (currentRequest.status === 200) {
						try {
							const response = JSON.parse(currentRequest.responseText);
							
							if (response.success && response.data && response.data.posts) {
								// Clear select except selected options.
								select.innerHTML = '';
								
								// Add selected options first.
								selectedOptions.forEach(function(opt) {
									ensureSelectedOption(opt.id, opt.label);
								});

								// Add search results (skip if already selected).
								response.data.posts.forEach(function(post) {
									if (!selectedIds.has(String(post.id))) {
										const option = document.createElement('option');
										option.value = post.id;
										option.textContent = post.label;
										select.appendChild(option);
									}
								});

								// Show message if no results.
								if (response.data.posts.length === 0 && selectedIds.size === 0) {
									const option = document.createElement('option');
									option.value = '';
									option.disabled = true;
									option.textContent = window.mksddnMcSearch.i18n.noResults;
									select.appendChild(option);
								}
					} else {
						const errorMessage = response.data && response.data.message ? response.data.message : window.mksddnMcSearch.i18n.error;
						console.error('MksDdn Migrate Content: AJAX request returned error', {
							status: currentRequest.status,
							response: response
						});
						select.innerHTML = '<option value="" disabled>' + errorMessage + '</option>';
					}
				} catch (e) {
					console.error('MksDdn Migrate Content: Error parsing AJAX response', {
						error: e,
						responseText: currentRequest.responseText,
						status: currentRequest.status
					});
					select.innerHTML = '<option value="" disabled>' + window.mksddnMcSearch.i18n.error + '</option>';
				}
			} else {
				console.error('MksDdn Migrate Content: AJAX request failed with status', {
					status: currentRequest.status,
					statusText: currentRequest.statusText
				});
				select.innerHTML = '<option value="" disabled>' + window.mksddnMcSearch.i18n.error + '</option>';
			}
					currentRequest = null;
				};

				currentRequest.onerror = function() {
					console.error('MksDdn Migrate Content: AJAX request failed', {
						status: currentRequest.status,
						statusText: currentRequest.statusText,
						url: window.mksddnMcSearch.ajaxUrl
					});
					select.innerHTML = '<option value="" disabled>' + window.mksddnMcSearch.i18n.error + '</option>';
					currentRequest = null;
				};

				currentRequest.send(formData);
			}

			// Handle search input.
			const debouncedSearch = debounce(function() {
				const searchTerm = searchInput.value.trim();
				if (searchTerm.length >= 2 || searchTerm.length === 0) {
					loadPosts(searchTerm, 1);
				} else if (searchTerm.length === 1) {
					select.innerHTML = '<option value="" disabled>' + window.mksddnMcSearch.i18n.typeMore + '</option>';
				}
			}, 300);

			// Handle selection change.
			select.addEventListener('change', function() {
				const options = select.selectedOptions;
				selectedIds.clear();
				selectedOptions.clear();

				for (let i = 0; i < options.length; i++) {
					const option = options[i];
					if (option.value && !option.disabled) {
						selectedIds.add(option.value);
						selectedOptions.set(option.value, {
							id: option.value,
							label: option.textContent
						});
					}
				}

				updateHiddenInput();
			});

			// Handle search input events.
			searchInput.addEventListener('input', debouncedSearch);
			searchInput.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					debouncedSearch();
				}
			});

			// Initial load if search is empty (load first page).
			if (searchInput.value.trim().length === 0) {
				loadPosts('', 1);
			}
		});
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
		const forms = document.querySelectorAll('.mksddn-mc-preflight-import-form, .mksddn-mc-user-plan');

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
						i18n.importProcessing || 'Server is processing the archive...'
					);
				}
			});
		});
	}

	// Initialize progress bar and select search when DOM is ready.
	function init() {
		window.mksddnMcProgress = initProgressBar();
		initSelectSearch();
		initUserPlanToggle();
		initFinalImportSubmitHandler();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

