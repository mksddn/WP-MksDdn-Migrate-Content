/**
 * Server file selector module for import forms.
 *
 * @package MksDdn\MigrateContent
 * @since 1.0.1
 */
(function() {
	'use strict';

	/**
	 * Server file selector handler (server-source import tab).
	 *
	 * @param {Object} options Configuration options.
	 * @param {HTMLElement} options.form Form element.
	 * @param {HTMLElement} options.serverDiv Server container.
	 * @param {HTMLElement} options.serverSelect Server file select.
	 * @param {HTMLElement|null} options.deleteButton Delete backup button.
	 * @param {string} options.ajaxAction AJAX action name.
	 * @param {string} options.deleteAjaxAction AJAX action for delete.
	 * @param {string} options.nonce Nonce for AJAX request.
	 * @param {Object} options.i18n Translation strings.
	 */
	function ServerFileSelector(options) {
		this.form = options.form;
		this.serverDiv = options.serverDiv;
		this.serverSelect = options.serverSelect;
		this.deleteButton = options.deleteButton || null;
		this.ajaxAction = options.ajaxAction;
		this.deleteAjaxAction = options.deleteAjaxAction || 'mksddn_mc_delete_server_backup';
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

		this.serverSelect.addEventListener('change', function() {
			self.updateDeleteButtonState();
			self.clearNotice();
		});

		if (this.deleteButton) {
			this.deleteButton.addEventListener('click', function(e) {
				e.preventDefault();
				self.handleDelete();
			});
		}

		this.form.addEventListener('submit', function(e) {
			self.handleSubmit(e);
		});

		this.loadServerFiles();
		this.updateDeleteButtonState();
	};

	/**
	 * Enable or disable the delete button based on selection.
	 */
	ServerFileSelector.prototype.updateDeleteButtonState = function() {
		if (!this.deleteButton) {
			return;
		}

		this.deleteButton.disabled = this.isLoading || this.isDeleting || !this.serverSelect.value || this.serverSelect.disabled;
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
		this.updateDeleteButtonState();

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
				self.populateSelect(data.data.files);
				if (successMessage) {
					self.showNotice(successMessage, 'success');
				}
			} else {
				var emptyMessage = data.data && data.data.message ? data.data.message : self.i18n.noFiles || 'No backup files found';
				self.showError(emptyMessage);
				if (successMessage) {
					self.showNotice(successMessage, 'success');
				}
			}
			self.updateDeleteButtonState();
		})
		.catch(function(error) {
			self.isLoading = false;
			self.showError(self.i18n.loadError || 'Error loading files');
			self.updateDeleteButtonState();
			console.error('Error loading server files:', error);
		});
	};

	/**
	 * Delete the selected server backup file.
	 */
	ServerFileSelector.prototype.handleDelete = function() {
		if (this.isDeleting || this.isLoading) {
			return;
		}

		var filename = this.serverSelect.value;
		if (!filename) {
			this.showNotice(this.i18n.deleteSelect || 'Please select a file to delete.', 'error');
			return;
		}

		var confirmMessage = this.i18n.deleteConfirm || 'Delete this backup file from the server? This cannot be undone.';
		if (!window.confirm(confirmMessage)) {
			return;
		}

		this.isDeleting = true;
		this.updateDeleteButtonState();
		if (this.deleteButton) {
			this.deleteButton.textContent = this.i18n.deleting || 'Deleting...';
		}

		var self = this;
		var formData = new URLSearchParams({
			action: this.deleteAjaxAction,
			nonce: this.nonce,
			filename: filename
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
			if (self.deleteButton) {
				self.deleteButton.textContent = self.deleteButton.dataset.label || 'Delete';
			}

			if (data.success) {
				var successMessage = (data.data && data.data.message) ? data.data.message : (self.i18n.deleteSuccess || 'Backup file deleted.');
				self.loadServerFiles(successMessage);
			} else {
				self.showNotice(
					(data.data && data.data.message) ? data.data.message : (self.i18n.deleteError || 'Failed to delete backup file.'),
					'error'
				);
				self.updateDeleteButtonState();
			}
		})
		.catch(function(error) {
			self.isDeleting = false;
			if (self.deleteButton) {
				self.deleteButton.textContent = self.deleteButton.dataset.label || 'Delete';
			}
			self.showNotice(self.i18n.deleteError || 'Failed to delete backup file.', 'error');
			self.updateDeleteButtonState();
			console.error('Error deleting server file:', error);
		});
	};

	/**
	 * Show loading state.
	 */
	ServerFileSelector.prototype.showLoading = function() {
		this.serverSelect.innerHTML = '';
		var option = document.createElement('option');
		option.value = '';
		option.textContent = this.i18n.loading || 'Loading...';
		this.serverSelect.appendChild(option);
		this.serverSelect.disabled = true;
	};

	/**
	 * Populate select with files.
	 *
	 * @param {Array} files Array of file objects.
	 */
	ServerFileSelector.prototype.populateSelect = function(files) {
		this.serverSelect.innerHTML = '<option value="">' + (this.i18n.selectFile || 'Select a file...') + '</option>';
		this.serverSelect.disabled = false;

		var self = this;
		files.forEach(function(file) {
			var option = document.createElement('option');
			option.value = file.name;
			option.textContent = file.name + ' (' + file.size_human + ', ' + file.modified_human + ')';
			self.serverSelect.appendChild(option);
		});

		this.clearNotice();
		this.updateDeleteButtonState();
	};

	/**
	 * Show error message in the select and notice area.
	 *
	 * @param {string} message Error message.
	 */
	ServerFileSelector.prototype.showError = function(message) {
		this.serverSelect.innerHTML = '';
		var option = document.createElement('option');
		option.value = '';
		option.textContent = message;
		this.serverSelect.appendChild(option);
		this.serverSelect.disabled = true;
		this.showNotice(message, 'error');
		this.updateDeleteButtonState();
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
		if (!this.serverSelect.value) {
			e.preventDefault();
			alert(this.i18n.pleaseSelect || 'Please select a file from the server.');
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
			deleteAjaxAction: config && config.deleteAjaxAction ? config.deleteAjaxAction : 'mksddn_mc_delete_server_backup',
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
			var serverSelect = form.querySelector('select[name="server_file"]');
			var deleteButton = form.querySelector('.mksddn-mc-delete-server-file');

			// Page-level tabs render only the active source panel.
			if ('server' !== source || !serverDiv || !serverSelect) {
				return;
			}

			if (deleteButton) {
				deleteButton.dataset.label = deleteButton.textContent;
			}

			new ServerFileSelector({
				form: form,
				serverDiv: serverDiv,
				serverSelect: serverSelect,
				deleteButton: deleteButton,
				ajaxAction: defaultConfig.ajaxAction,
				deleteAjaxAction: defaultConfig.deleteAjaxAction,
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
