/**
 * Server file selector module for import forms.
 *
 * @package MksDdn\MigrateContent
 * @since 1.0.1
 */
(function() {
	'use strict';

	var DEFAULT_MAX_BULK_DELETE = 100;

	/**
	 * Server file selector handler (server-source import tab).
	 *
	 * Click a row body to choose the import file. Checkboxes are only for bulk delete.
	 *
	 * @param {Object} options Configuration options.
	 * @param {HTMLElement} options.form Form element.
	 * @param {HTMLElement} options.serverDiv Server container.
	 * @param {HTMLElement} options.fileList File list container.
	 * @param {HTMLInputElement|null} options.serverFileInput Hidden server_file input.
	 * @param {HTMLElement|null} options.selectAllButton Select-all / deselect-all button.
	 * @param {HTMLElement|null} options.deleteSelectedButton Bulk delete button.
	 * @param {string} options.ajaxAction AJAX action name.
	 * @param {string} options.deleteBulkAjaxAction AJAX action for bulk delete.
	 * @param {number} options.maxBulkDelete Max files per bulk delete request.
	 * @param {string} options.nonce Nonce for AJAX request.
	 * @param {Object} options.i18n Translation strings.
	 */
	function ServerFileSelector(options) {
		this.form = options.form;
		this.serverDiv = options.serverDiv;
		this.fileList = options.fileList;
		this.serverFileInput = options.serverFileInput || null;
		this.selectAllButton = options.selectAllButton || null;
		this.deleteSelectedButton = options.deleteSelectedButton || null;
		this.ajaxAction = options.ajaxAction;
		this.deleteBulkAjaxAction = options.deleteBulkAjaxAction || 'mksddn_mc_delete_server_backups';
		this.maxBulkDelete = parseInt(options.maxBulkDelete, 10) || DEFAULT_MAX_BULK_DELETE;
		this.nonce = options.nonce;
		this.i18n = options.i18n || {};
		this.isLoading = false;
		this.isDeleting = false;

		this.init();
	}

	/**
	 * Initialize the selector.
	 */
	ServerFileSelector.prototype.init = function() {
		var self = this;

		this.fileList.addEventListener('change', function(e) {
			var target = e.target;
			if (target && target.matches('input.mksddn-mc-server-file-bulk')) {
				self.updateToolbarState();
			}
		});

		this.fileList.addEventListener('click', function(e) {
			var target = e.target;
			if (!target) {
				return;
			}

			// Checkbox clicks only toggle bulk selection.
			if (target.matches('input.mksddn-mc-server-file-bulk')) {
				return;
			}

			var chooseButton = target.closest('button.mksddn-mc-server-file-item__choose');
			if (!chooseButton || !self.fileList.contains(chooseButton)) {
				return;
			}

			var item = chooseButton.closest('.mksddn-mc-server-file-item');
			var filename = (item && item.getAttribute('data-filename')) || chooseButton.getAttribute('data-filename') || '';
			if (!filename) {
				return;
			}

			self.setImportFile(filename);
			self.clearNotice();
		});

		if (this.selectAllButton) {
			this.selectAllButton.dataset.labelSelect = this.selectAllButton.textContent;
			this.selectAllButton.addEventListener('click', function(e) {
				e.preventDefault();
				self.handleSelectAllToggle();
			});
		}

		if (this.deleteSelectedButton) {
			this.deleteSelectedButton.dataset.label = this.deleteSelectedButton.textContent;
			this.deleteSelectedButton.addEventListener('click', function(e) {
				e.preventDefault();
				self.handleDeleteSelected();
			});
		}

		this.form.addEventListener('submit', function(e) {
			self.handleSubmit(e);
		});

		this.loadServerFiles();
		this.updateToolbarState();
	};

	/**
	 * Get bulk-delete checkboxes.
	 *
	 * @return {NodeListOf<HTMLInputElement>}
	 */
	ServerFileSelector.prototype.getBulkCheckboxes = function() {
		return this.fileList.querySelectorAll('input.mksddn-mc-server-file-bulk');
	};

	/**
	 * Get selected filenames for bulk delete.
	 *
	 * @return {string[]}
	 */
	ServerFileSelector.prototype.getSelectedFilenames = function() {
		var filenames = [];
		this.getBulkCheckboxes().forEach(function(checkbox) {
			if (checkbox.checked && checkbox.value) {
				filenames.push(checkbox.value);
			}
		});
		return filenames;
	};

	/**
	 * Whether the selectable bulk set is fully checked (capped by maxBulkDelete).
	 *
	 * @return {boolean}
	 */
	ServerFileSelector.prototype.isBulkSelectionComplete = function() {
		var checkboxes = this.getBulkCheckboxes();
		if (!checkboxes.length) {
			return false;
		}

		var selectable = Math.min(checkboxes.length, this.maxBulkDelete);
		return this.getSelectedFilenames().length >= selectable;
	};

	/**
	 * Replace sprintf-style %d placeholders in a localized string.
	 *
	 * @param {string} template Localized template.
	 * @param {number|string} value Replacement value.
	 * @return {string}
	 */
	ServerFileSelector.prototype.formatCountMessage = function(template, value) {
		if (!template) {
			return '';
		}
		return String(template).split('%d').join(String(value));
	};

	/**
	 * Get the currently selected import file.
	 *
	 * @return {string}
	 */
	ServerFileSelector.prototype.getSelectedServerFile = function() {
		return this.serverFileInput && this.serverFileInput.value ? this.serverFileInput.value : '';
	};

	/**
	 * Mark a file as the import source.
	 *
	 * @param {string} filename Backup basename.
	 */
	ServerFileSelector.prototype.setImportFile = function(filename) {
		if (this.serverFileInput) {
			this.serverFileInput.value = filename || '';
		}

		this.fileList.querySelectorAll('.mksddn-mc-server-file-item').forEach(function(item) {
			var isSelected = filename && item.getAttribute('data-filename') === filename;
			item.classList.toggle('is-selected', !!isSelected);
			item.setAttribute('aria-current', isSelected ? 'true' : 'false');
		});
	};

	/**
	 * Enable or disable toolbar buttons based on list state.
	 */
	ServerFileSelector.prototype.updateToolbarState = function() {
		var checkboxes = this.getBulkCheckboxes();
		var hasFiles = checkboxes.length > 0;
		var selectedCount = this.getSelectedFilenames().length;
		var busy = this.isLoading || this.isDeleting;
		var selectionComplete = this.isBulkSelectionComplete();

		if (this.selectAllButton) {
			this.selectAllButton.disabled = busy || !hasFiles;
			this.selectAllButton.textContent = selectionComplete
				? (this.i18n.deselectAll || '')
				: (this.i18n.selectAll || this.selectAllButton.dataset.labelSelect || '');
		}

		if (this.deleteSelectedButton) {
			this.deleteSelectedButton.disabled = busy || selectedCount < 1;
		}
	};

	/**
	 * Toggle Select all / Deselect all for bulk-delete checkboxes.
	 * Select all caps at maxBulkDelete and shows a notice when more files exist.
	 */
	ServerFileSelector.prototype.handleSelectAllToggle = function() {
		if (this.isLoading || this.isDeleting) {
			return;
		}

		var checkboxes = this.getBulkCheckboxes();
		if (!checkboxes.length) {
			return;
		}

		if (this.isBulkSelectionComplete()) {
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = false;
			});
			this.clearNotice();
			this.updateToolbarState();
			return;
		}

		var limit = this.maxBulkDelete;
		var total = checkboxes.length;
		var checked = 0;

		checkboxes.forEach(function(checkbox) {
			if (checked < limit) {
				checkbox.checked = true;
				checked++;
			} else {
				checkbox.checked = false;
			}
		});

		if (total > limit) {
			this.showNotice(this.formatCountMessage(this.i18n.deleteBulkLimit || '', limit), 'error');
		} else {
			this.clearNotice();
		}

		this.updateToolbarState();
	};

	/**
	 * Load server files via AJAX.
	 *
	 * @param {string|null} successMessage Optional success notice to show after reload.
	 */
	ServerFileSelector.prototype.loadServerFiles = function(successMessage) {
		if (this.isLoading) {
			return;
		}

		this.isLoading = true;
		this.showLoading();
		this.updateToolbarState();

		var self = this;
		var formData = new URLSearchParams({
			action: this.ajaxAction,
			nonce: this.nonce
		});

		fetch(ajaxurl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: formData
		})
		.then(function(response) {
			return response.json();
		})
		.then(function(data) {
			self.isLoading = false;
			if (data.success && data.data.files && data.data.files.length > 0) {
				self.populateList(data.data.files);
				if (successMessage) {
					self.showNotice(successMessage, 'success');
				}
			} else {
				var emptyMessage = data.data && data.data.message ? data.data.message : self.i18n.noFiles || '';
				self.showError(emptyMessage);
				if (successMessage) {
					self.showNotice(successMessage, 'success');
				}
			}
			self.updateToolbarState();
		})
		.catch(function(error) {
			self.isLoading = false;
			self.showError(self.i18n.loadError || '');
			self.updateToolbarState();
			console.error('Error loading server files:', error);
		});
	};

	/**
	 * Delete checked backup files in one request.
	 */
	ServerFileSelector.prototype.handleDeleteSelected = function() {
		if (this.isDeleting || this.isLoading) {
			return;
		}

		var filenames = this.getSelectedFilenames();
		if (!filenames.length) {
			this.showNotice(this.i18n.deleteSelect || '', 'error');
			return;
		}

		if (filenames.length > this.maxBulkDelete) {
			this.showNotice(this.formatCountMessage(this.i18n.deleteBulkLimit || '', this.maxBulkDelete), 'error');
			return;
		}

		var confirmMessage = this.formatCountMessage(this.i18n.deleteBulkConfirm || '', filenames.length);
		if (!window.confirm(confirmMessage)) {
			return;
		}

		this.isDeleting = true;
		this.updateToolbarState();
		if (this.deleteSelectedButton) {
			this.deleteSelectedButton.textContent = this.i18n.deleting || '';
		}

		var self = this;
		var formData = new URLSearchParams();
		formData.append('action', this.deleteBulkAjaxAction);
		formData.append('nonce', this.nonce);
		filenames.forEach(function(filename) {
			formData.append('filenames[]', filename);
		});

		fetch(ajaxurl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: formData
		})
		.then(function(response) {
			return response.json();
		})
		.then(function(data) {
			self.isDeleting = false;
			if (self.deleteSelectedButton) {
				self.deleteSelectedButton.textContent = self.deleteSelectedButton.dataset.label || self.i18n.deleteSelected || '';
			}

			if (data.success) {
				var successMessage = (data.data && data.data.message) ? data.data.message : (self.i18n.deleteBulkSuccess || '');
				self.loadServerFiles(successMessage);
			} else {
				self.showNotice(
					(data.data && data.data.message) ? data.data.message : (self.i18n.deleteError || ''),
					'error'
				);
				self.updateToolbarState();
			}
		})
		.catch(function(error) {
			self.isDeleting = false;
			if (self.deleteSelectedButton) {
				self.deleteSelectedButton.textContent = self.deleteSelectedButton.dataset.label || self.i18n.deleteSelected || '';
			}
			self.showNotice(self.i18n.deleteError || '', 'error');
			self.updateToolbarState();
			console.error('Error deleting server files:', error);
		});
	};

	/**
	 * Show loading state in the list.
	 */
	ServerFileSelector.prototype.showLoading = function() {
		if (this.serverFileInput) {
			this.serverFileInput.value = '';
		}
		this.fileList.innerHTML = '';
		var empty = document.createElement('p');
		empty.className = 'mksddn-mc-server-file-list__empty';
		empty.textContent = this.i18n.loading || '';
		this.fileList.appendChild(empty);
	};

	/**
	 * Populate list with files. Does not auto-select an import file.
	 *
	 * @param {Array} files Array of file objects.
	 */
	ServerFileSelector.prototype.populateList = function(files) {
		var self = this;
		this.fileList.innerHTML = '';

		if (this.serverFileInput) {
			this.serverFileInput.value = '';
		}

		files.forEach(function(file) {
			var item = document.createElement('div');
			item.className = 'mksddn-mc-server-file-item';
			item.setAttribute('data-filename', file.name);
			item.setAttribute('aria-current', 'false');

			var checkWrap = document.createElement('span');
			checkWrap.className = 'mksddn-mc-server-file-item__check';

			var checkbox = document.createElement('input');
			checkbox.type = 'checkbox';
			checkbox.className = 'mksddn-mc-server-file-bulk';
			checkbox.value = file.name;
			checkbox.setAttribute(
				'aria-label',
				(self.i18n.deleteCheckboxLabel || self.i18n.deleteSelect || 'Delete') + ': ' + file.name
			);
			checkbox.addEventListener('click', function(e) {
				e.stopPropagation();
			});

			checkWrap.appendChild(checkbox);

			var choose = document.createElement('button');
			choose.type = 'button';
			choose.className = 'mksddn-mc-server-file-item__choose';
			choose.setAttribute('data-filename', file.name);
			choose.setAttribute(
				'aria-label',
				(self.i18n.chooseFile || self.i18n.pleaseSelect || 'Select') + ': ' + file.name
			);

			var name = document.createElement('span');
			name.className = 'mksddn-mc-server-file-item__name';
			name.textContent = file.name;

			var meta = document.createElement('span');
			meta.className = 'mksddn-mc-server-file-item__meta';
			meta.textContent = file.size_human + ', ' + file.modified_human;

			choose.appendChild(name);
			choose.appendChild(meta);

			item.appendChild(checkWrap);
			item.appendChild(choose);
			self.fileList.appendChild(item);
		});

		this.clearNotice();
		this.updateToolbarState();
	};

	/**
	 * Show error message in the list and notice area.
	 *
	 * @param {string} message Error message.
	 */
	ServerFileSelector.prototype.showError = function(message) {
		if (this.serverFileInput) {
			this.serverFileInput.value = '';
		}
		this.fileList.innerHTML = '';
		var empty = document.createElement('p');
		empty.className = 'mksddn-mc-server-file-list__empty';
		empty.textContent = message;
		this.fileList.appendChild(empty);
		this.showNotice(message, 'error');
		this.updateToolbarState();
	};

	/**
	 * Show a notice below the server file controls.
	 *
	 * @param {string} message Notice text.
	 * @param {string} type Notice type: error|success.
	 */
	ServerFileSelector.prototype.showNotice = function(message, type) {
		var notice = this.serverDiv.querySelector('.mksddn-mc-server-file-notice');
		if (!notice) {
			return;
		}

		notice.textContent = message;
		notice.style.display = 'block';
		notice.className = 'mksddn-mc-server-file-notice notice notice-' + (type === 'success' ? 'success' : 'error');
	};

	/**
	 * Hide the notice area.
	 */
	ServerFileSelector.prototype.clearNotice = function() {
		var notice = this.serverDiv.querySelector('.mksddn-mc-server-file-notice');
		if (!notice) {
			return;
		}

		notice.textContent = '';
		notice.style.display = 'none';
		notice.className = 'mksddn-mc-server-file-notice notice notice-error';
	};

	/**
	 * Handle form submission.
	 *
	 * @param {Event} e Submit event.
	 */
	ServerFileSelector.prototype.handleSubmit = function(e) {
		if (!this.getSelectedServerFile()) {
			e.preventDefault();
			alert(this.i18n.pleaseSelect || '');
			return false;
		}
	};

	/**
	 * Auto-initialize server file selectors on the page.
	 *
	 * @param {Object} config Global configuration.
	 */
	function autoInit(config) {
		var forms = document.querySelectorAll('form[data-mksddn-full-import="true"], form[data-mksddn-unified-import="true"]');
		var defaultConfig = {
			ajaxAction: config && config.ajaxAction ? config.ajaxAction : 'mksddn_mc_get_server_backups',
			deleteBulkAjaxAction: config && config.deleteBulkAjaxAction ? config.deleteBulkAjaxAction : 'mksddn_mc_delete_server_backups',
			maxBulkDelete: config && config.maxBulkDelete ? config.maxBulkDelete : DEFAULT_MAX_BULK_DELETE,
			nonce: config && config.nonce ? config.nonce : '',
			i18n: config && config.i18n ? config.i18n : {}
		};

		forms.forEach(function(form) {
			if (form.dataset.serverFileSelectorInitialized === 'true') {
				return;
			}

			var sourceInput = form.querySelector('input[name="import_source"]');
			var source = sourceInput ? sourceInput.value : '';
			var serverDiv = form.querySelector('.mksddn-mc-import-source-server');
			var fileList = form.querySelector('.mksddn-mc-server-file-list');
			var serverFileInput = form.querySelector('input[name="server_file"]');
			var selectAllButton = form.querySelector('.mksddn-mc-select-all-server-files');
			var deleteSelectedButton = form.querySelector('.mksddn-mc-delete-selected-server-files');

			// Page-level tabs render only the active source panel.
			if ('server' !== source || !serverDiv || !fileList) {
				return;
			}

			new ServerFileSelector({
				form: form,
				serverDiv: serverDiv,
				fileList: fileList,
				serverFileInput: serverFileInput,
				selectAllButton: selectAllButton,
				deleteSelectedButton: deleteSelectedButton,
				ajaxAction: defaultConfig.ajaxAction,
				deleteBulkAjaxAction: defaultConfig.deleteBulkAjaxAction,
				maxBulkDelete: defaultConfig.maxBulkDelete,
				nonce: defaultConfig.nonce,
				i18n: defaultConfig.i18n
			});

			form.dataset.serverFileSelectorInitialized = 'true';
		});
	}

	// Export for use in forms.
	window.MksDdnServerFileSelector = ServerFileSelector;
	window.MksDdnServerFileSelector.autoInit = autoInit;

	// Auto-initialize if config is available.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			if (window.mksddnServerFileSelector) {
				autoInit(window.mksddnServerFileSelector);
			}
		});
	} else {
		if (window.mksddnServerFileSelector) {
			autoInit(window.mksddnServerFileSelector);
		}
	}
})();
