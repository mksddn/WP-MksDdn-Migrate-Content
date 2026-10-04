/**
 * Selected Content grid picker (multi-select per post type).
 *
 * @package MksDdn_Migrate_Content
 */

(function () {
	'use strict';

	/**
	 * @param {Function} func
	 * @param {number} wait
	 * @returns {Function}
	 */
	function debounce(func, wait) {
		var timer = null;
		return function () {
			var context = this;
			var args = arguments;
			clearTimeout(timer);
			timer = setTimeout(function () {
				func.apply(context, args);
			}, wait);
		};
	}

	/**
	 * @param {HTMLInputElement} searchInput
	 */
	function initColumn(searchInput) {
		var config = window.mksddnMcContentPicker;
		if (!config || !config.ajaxUrl || !config.nonce) {
			return;
		}

		var i18n = config.i18n || {};
		var actions = config.actions || {};
		var targetId = searchInput.getAttribute('data-target');
		var postType = searchInput.getAttribute('data-post-type');
		if (!targetId || !postType) {
			return;
		}

		var select = document.getElementById(targetId);
		var hiddenInput = document.querySelector('.mksddn-mc-selected-ids[data-post-type="' + postType + '"]');
		if (!select || !hiddenInput) {
			return;
		}

		var column = searchInput.closest('.mksddn-mc-basic-selection');
		var loadMoreButton = column ? column.querySelector('.mksddn-mc-load-more') : null;
		var selectedIds = new Set();
		var selectedOptions = new Map();
		var currentPage = 0;
		var totalPages = 0;
		var currentSearch = '';
		var loading = false;
		var hasMore = true;
		var request = null;
		var stableListHeight = 0;

		function abortRequest() {
			var xhr = request;
			request = null;
			if (xhr && xhr.readyState !== 4) {
				xhr.abort();
			}
		}

		function updateLoadMore() {
			if (!loadMoreButton) {
				return;
			}
			loadMoreButton.hidden = loading || !hasMore;
			loadMoreButton.disabled = loading;
		}

		/**
		 * Native select scroll events are unreliable (notably Safari).
		 * Keep fetching while the list cannot scroll, unless the control grows with its content.
		 */
		function maybeFillIfNotScrollable() {
			if (loading || !hasMore || select.clientHeight < 1) {
				return;
			}
			if (select.scrollHeight > select.clientHeight + 1) {
				return;
			}
			var height = select.clientHeight;
			if (stableListHeight > 0 && height > stableListHeight + 8) {
				return;
			}
			stableListHeight = height;
			loadPosts(currentPage + 1, true);
		}

		function syncHiddenInput() {
			hiddenInput.value = Array.from(selectedIds).filter(Boolean).join(',');
		}

		/**
		 * @param {string} id
		 * @param {string} label
		 */
		function ensureSelectedOption(id, label) {
			var existing = select.querySelector('option[value="' + id + '"]');
			if (existing) {
				existing.selected = true;
				existing.textContent = label;
				return;
			}
			var option = document.createElement('option');
			option.value = id;
			option.textContent = label;
			option.selected = true;
			select.appendChild(option);
		}

		/**
		 * @param {string} message
		 */
		function showMessage(message) {
			select.innerHTML = '';
			selectedOptions.forEach(function (opt) {
				ensureSelectedOption(opt.id, opt.label);
			});
			var option = document.createElement('option');
			option.value = '';
			option.disabled = true;
			option.textContent = message;
			select.appendChild(option);
		}

		/**
		 * @param {Array<{id:number|string,label:string}>} posts
		 * @param {boolean} append
		 */
		function renderPosts(posts, append) {
			if (!append) {
				select.innerHTML = '';
				selectedOptions.forEach(function (opt) {
					ensureSelectedOption(opt.id, opt.label);
				});
			}

			(posts || []).forEach(function (post) {
				var id = String(post.id);
				if (selectedIds.has(id)) {
					return;
				}
				if (append && select.querySelector('option[value="' + id + '"]')) {
					return;
				}
				var option = document.createElement('option');
				option.value = id;
				option.textContent = post.label || ('#' + id);
				select.appendChild(option);
			});

			if (!append && (posts || []).length === 0 && selectedIds.size === 0) {
				var empty = document.createElement('option');
				empty.value = '';
				empty.disabled = true;
				empty.textContent = i18n.noResults || 'No entries found';
				select.appendChild(empty);
			}
		}

		/**
		 * @param {number} page
		 * @param {boolean} append
		 */
		function loadPosts(page, append) {
			// A new search replaces the in-flight request. Append waits so pages stay in order.
			if (append && (loading || !hasMore)) {
				return;
			}

			abortRequest();
			loading = true;
			updateLoadMore();

			if (!append) {
				select.innerHTML = '';
				selectedOptions.forEach(function (opt) {
					ensureSelectedOption(opt.id, opt.label);
				});
				var loadingOption = document.createElement('option');
				loadingOption.value = '';
				loadingOption.disabled = true;
				loadingOption.textContent = i18n.loading || 'Loading...';
				select.appendChild(loadingOption);
			}

			var formData = new FormData();
			formData.append('action', actions.searchPosts || 'mksddn_mc_search_posts');
			formData.append('nonce', config.nonce);
			formData.append('post_type', postType);
			formData.append('search', currentSearch);
			formData.append('page', String(page));

			var xhr = new XMLHttpRequest();
			request = xhr;
			xhr.open('POST', config.ajaxUrl, true);
			xhr.onload = function () {
				if (request !== xhr) {
					return;
				}
				request = null;
				loading = false;

				if (xhr.status !== 200) {
					updateLoadMore();
					if (!append) {
						showMessage(i18n.error || 'Error loading entries');
					}
					return;
				}

				try {
					var response = JSON.parse(xhr.responseText);
					if (!response.success || !response.data) {
						updateLoadMore();
						if (!append) {
							var message = response.data && response.data.message
								? response.data.message
								: (i18n.error || 'Error loading entries');
							showMessage(message);
						}
						return;
					}

					var posts = response.data.posts || [];
					currentPage = parseInt(response.data.page, 10) || page;
					totalPages = parseInt(response.data.total_pages, 10) || 0;
					hasMore = totalPages > 0 && currentPage < totalPages;
					if (append && posts.length === 0) {
						hasMore = false;
					}
					renderPosts(posts, append);
					updateLoadMore();
					maybeFillIfNotScrollable();
				} catch (e) {
					updateLoadMore();
					if (!append) {
						showMessage(i18n.error || 'Error loading entries');
					}
				}
			};
			xhr.onerror = function () {
				if (request !== xhr) {
					return;
				}
				request = null;
				loading = false;
				updateLoadMore();
				if (!append) {
					showMessage(i18n.error || 'Error loading entries');
				}
			};
			xhr.onabort = function () {
				if (request !== xhr) {
					return;
				}
				request = null;
				loading = false;
				updateLoadMore();
			};
			xhr.send(formData);
		}

		function resetAndLoad(searchTerm) {
			currentSearch = searchTerm;
			currentPage = 0;
			totalPages = 0;
			hasMore = true;
			stableListHeight = 0;
			loadPosts(1, false);
		}

		var debouncedSearch = debounce(function () {
			var searchTerm = searchInput.value.trim();
			if (searchTerm.length >= 2 || searchTerm.length === 0) {
				resetAndLoad(searchTerm);
			} else if (searchTerm.length === 1) {
				abortRequest();
				loading = false;
				hasMore = false;
				updateLoadMore();
				showMessage(i18n.typeMore || 'Type at least 2 characters…');
			}
		}, 300);

		searchInput.addEventListener('input', debouncedSearch);
		searchInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				var searchTerm = searchInput.value.trim();
				if (searchTerm.length >= 2 || searchTerm.length === 0) {
					resetAndLoad(searchTerm);
				}
			}
		});

		select.addEventListener('change', function () {
			selectedIds.clear();
			selectedOptions.clear();
			Array.prototype.forEach.call(select.selectedOptions, function (option) {
				if (!option.value || option.disabled) {
					return;
				}
				selectedIds.add(option.value);
				selectedOptions.set(option.value, {
					id: option.value,
					label: option.textContent
				});
			});
			syncHiddenInput();
		});

		select.addEventListener('scroll', function () {
			if (loading || !hasMore) {
				return;
			}
			if (select.scrollTop + select.clientHeight >= select.scrollHeight - 24) {
				loadPosts(currentPage + 1, true);
			}
		});

		if (loadMoreButton) {
			loadMoreButton.addEventListener('click', function () {
				if (loading || !hasMore) {
					return;
				}
				loadPosts(currentPage + 1, true);
			});
		}

		resetAndLoad('');
	}

	function init() {
		var root = document.querySelector('[data-mksddn-mc-content-picker]');
		if (!root) {
			return;
		}
		root.querySelectorAll('.mksddn-mc-search-input').forEach(function (input) {
			initColumn(input);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
