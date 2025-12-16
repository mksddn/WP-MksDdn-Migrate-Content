/**
 * Server file selector module for import forms.
 *
 * @package MksDdn\MigrateContent
 * @since 1.0.1
 */
(function() {
	'use strict';

	/**
	 * Server file selector handler.
	 *
	 * @param {Object} options Configuration options.
	 * @param {HTMLElement} options.form Form element.
	 * @param {HTMLElement} options.uploadRadio Upload radio button.
	 * @param {HTMLElement} options.serverRadio Server radio button.
	 * @param {HTMLElement} options.uploadDiv Upload container.
	 * @param {HTMLElement} options.serverDiv Server container.
	 * @param {HTMLElement} options.fileInput File input.
	 * @param {HTMLElement} options.serverSelect Server file select.
	 * @param {string} options.ajaxAction AJAX action name.
	 * @param {string} options.nonce Nonce for AJAX request.
	 * @param {Object} options.i18n Translation strings.
	 */
	function ServerFileSelector(options) {
		this.form = options.form;
		this.uploadRadio = options.uploadRadio;
		this.serverRadio = options.serverRadio;
		this.uploadDiv = options.uploadDiv;
		this.serverDiv = options.serverDiv;
		this.fileInput = options.fileInput;
		this.serverSelect = options.serverSelect;
		this.ajaxAction = options.ajaxAction;
		this.nonce = options.nonce;
		this.i18n = options.i18n || {};
		this.isLoading = false;

		this.init();
	}

	/**
	 * Initialize the selector.
	 */
	ServerFileSelector.prototype.init = function() {
		var self = this;

		this.uploadRadio.addEventListener('change', function() {
			self.toggleSource();
		});

		this.serverRadio.addEventListener('change', function() {
			self.toggleSource();
		});

		this.form.addEventListener('submit', function(e) {
			self.handleSubmit(e);
		});
	};

	/**
	 * Toggle between upload and server source.
	 */
	ServerFileSelector.prototype.toggleSource = function() {
		if (this.uploadRadio.checked) {
			this.uploadDiv.style.display = 'block';
			this.serverDiv.style.display = 'none';
			this.fileInput.required = true;
			this.serverSelect.required = false;
			this.serverSelect.value = '';
		} else {
			this.uploadDiv.style.display = 'none';
			this.serverDiv.style.display = 'block';
			this.fileInput.required = false;
			this.fileInput.value = '';
			this.serverSelect.required = true;
			this.loadServerFiles();
		}
	};

	/**
	 * Load server files via AJAX.
	 */
	ServerFileSelector.prototype.loadServerFiles = function() {
		if (this.isLoading) {
			return;
		}

		this.isLoading = true;
		this.showLoading();

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
			} else {
				self.showError(data.data && data.data.message ? data.data.message : self.i18n.noFiles || 'No backup files found');
			}
		})
		.catch(function(error) {
			self.isLoading = false;
			self.showError(self.i18n.loadError || 'Error loading files');
			console.error('Error loading server files:', error);
		});
	};

	/**
	 * Show loading state.
	 */
	ServerFileSelector.prototype.showLoading = function() {
		this.serverSelect.innerHTML = '<option value="">' + (this.i18n.loading || 'Loading...') + '</option>';
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
	};

	/**
	 * Show error message.
	 *
	 * @param {string} message Error message.
	 */
	ServerFileSelector.prototype.showError = function(message) {
		this.serverSelect.innerHTML = '<option value="">' + message + '</option>';
		this.serverSelect.disabled = true;

		// Show notice if possible.
		var notice = this.serverDiv.querySelector('.mksddn-mc-server-file-notice');
		if (notice) {
			notice.textContent = message;
			notice.style.display = 'block';
			notice.className = 'mksddn-mc-server-file-notice notice notice-error';
		}
	};

	/**
	 * Handle form submission.
	 *
	 * @param {Event} e Submit event.
	 */
	ServerFileSelector.prototype.handleSubmit = function(e) {
		if (this.serverRadio.checked) {
			if (!this.serverSelect.value) {
				e.preventDefault();
				alert(this.i18n.pleaseSelect || 'Please select a file from the server.');
				return false;
			}
			this.fileInput.removeAttribute('required');
			this.fileInput.disabled = true;
		} else {
			this.serverSelect.removeAttribute('required');
		}
	};

	/**
	 * Auto-initialize server file selectors on the page.
	 *
	 * @param {Object} config Global configuration.
	 */
	function autoInit(config) {
		var forms = document.querySelectorAll('form[data-mksddn-full-import="true"], .mksddn-mc-card form');
		var defaultConfig = {
			ajaxAction: config && config.ajaxAction ? config.ajaxAction : 'mksddn_mc_get_server_backups',
			nonce: config && config.nonce ? config.nonce : '',
			i18n: config && config.i18n ? config.i18n : {}
		};

		forms.forEach(function(form) {
			if (form.dataset.serverFileSelectorInitialized === 'true') {
				return;
			}

			var uploadRadio = form.querySelector('input[name="import_source"][value="upload"]');
			var serverRadio = form.querySelector('input[name="import_source"][value="server"]');
			var uploadDiv = form.querySelector('.mksddn-mc-import-source-upload');
			var serverDiv = form.querySelector('.mksddn-mc-import-source-server');
			var fileInput = form.querySelector('input[type="file"]');
			var serverSelect = form.querySelector('select[name="server_file"]');

			if (!uploadRadio || !serverRadio || !uploadDiv || !serverDiv || !fileInput || !serverSelect) {
				return;
			}

			new ServerFileSelector({
				form: form,
				uploadRadio: uploadRadio,
				serverRadio: serverRadio,
				uploadDiv: uploadDiv,
				serverDiv: serverDiv,
				fileInput: fileInput,
				serverSelect: serverSelect,
				ajaxAction: defaultConfig.ajaxAction,
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

