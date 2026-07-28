/**
 * Drag-and-drop file uploader for import forms.
 *
 * @package MksDdn\MigrateContent
 * @since 2.5.0
 */
(function () {
	'use strict';

	var ALLOWED_EXTENSIONS = ['wpbkp', 'json'];

	/**
	 * @param {HTMLElement} root Dropzone root.
	 * @param {Object} i18n Localized strings.
	 */
	function FileDropzone(root, i18n) {
		this.root = root;
		this.i18n = i18n || {};
		this.input = root.querySelector('.mksddn-mc-dropzone__input');
		this.idle = root.querySelector('.mksddn-mc-dropzone__idle');
		this.selected = root.querySelector('.mksddn-mc-dropzone__selected');
		this.fileName = root.querySelector('.mksddn-mc-dropzone__file-name');
		this.fileSize = root.querySelector('.mksddn-mc-dropzone__file-size');
		this.clearBtn = root.querySelector('.mksddn-mc-dropzone__clear');
		this.errorEl = root.querySelector('.mksddn-mc-dropzone__error');
		this.dragDepth = 0;

		if (!this.input) {
			return;
		}

		this.bindEvents();
		this.syncFromInput();
	}

	FileDropzone.prototype.bindEvents = function () {
		var self = this;

		['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (type) {
			self.root.addEventListener(type, function (event) {
				event.preventDefault();
				event.stopPropagation();
			});
		});

		this.root.addEventListener('dragenter', function () {
			self.dragDepth += 1;
			self.root.classList.add('is-dragover');
		});

		this.root.addEventListener('dragleave', function () {
			self.dragDepth = Math.max(0, self.dragDepth - 1);
			if (self.dragDepth === 0) {
				self.root.classList.remove('is-dragover');
			}
		});

		this.root.addEventListener('drop', function (event) {
			self.dragDepth = 0;
			self.root.classList.remove('is-dragover');
			var files = event.dataTransfer && event.dataTransfer.files ? event.dataTransfer.files : null;
			self.handleFiles(files);
		});

		this.root.addEventListener('click', function (event) {
			if (event.target.closest('.mksddn-mc-dropzone__clear')) {
				return;
			}
			self.input.click();
		});

		this.root.addEventListener('keydown', function (event) {
			if (event.target.closest('.mksddn-mc-dropzone__clear')) {
				return;
			}
			if (event.key === 'Enter' || event.key === ' ') {
				event.preventDefault();
				self.input.click();
			}
		});

		this.input.addEventListener('change', function () {
			self.syncFromInput();
		});

		this.input.addEventListener('click', function (event) {
			event.stopPropagation();
		});

		if (this.clearBtn) {
			this.clearBtn.addEventListener('click', function (event) {
				event.preventDefault();
				event.stopPropagation();
				self.clear();
			});
		}
	};

	/**
	 * @param {FileList|Array<File>|null} files Candidate files.
	 */
	FileDropzone.prototype.handleFiles = function (files) {
		if (!files || !files.length) {
			return;
		}

		var file = files[0];
		if (!this.isAllowedFile(file)) {
			this.showError(this.i18n.invalidType || '');
			return;
		}

		this.setFile(file);
	};

	/**
	 * @param {File} file File object.
	 * @return {boolean}
	 */
	FileDropzone.prototype.isAllowedFile = function (file) {
		if (!file || !file.name) {
			return false;
		}

		var parts = file.name.toLowerCase().split('.');
		var ext = parts.length > 1 ? parts.pop() : '';
		return ALLOWED_EXTENSIONS.indexOf(ext) !== -1;
	};

	/**
	 * @param {File} file File to assign to the input.
	 */
	FileDropzone.prototype.setFile = function (file) {
		try {
			var transfer = new DataTransfer();
			transfer.items.add(file);
			this.input.files = transfer.files;
		} catch (error) {
			this.showError(this.i18n.assignError || '');
			return;
		}

		this.clearError();
		this.input.dispatchEvent(new Event('change', { bubbles: true }));
		this.syncFromInput();
	};

	FileDropzone.prototype.clear = function () {
		this.input.value = '';
		this.clearError();
		this.syncFromInput();
		this.input.dispatchEvent(new Event('change', { bubbles: true }));
	};

	FileDropzone.prototype.syncFromInput = function () {
		var file = this.input.files && this.input.files[0] ? this.input.files[0] : null;

		if (!file) {
			this.root.classList.remove('has-file');
			if (this.idle) {
				this.idle.hidden = false;
			}
			if (this.selected) {
				this.selected.hidden = true;
			}
			if (this.fileName) {
				this.fileName.textContent = '';
			}
			if (this.fileSize) {
				this.fileSize.textContent = '';
			}
			return;
		}

		this.root.classList.add('has-file');
		if (this.idle) {
			this.idle.hidden = true;
		}
		if (this.selected) {
			this.selected.hidden = false;
		}
		if (this.fileName) {
			this.fileName.textContent = file.name;
		}
		if (this.fileSize) {
			this.fileSize.textContent = this.formatBytes(file.size);
		}
		this.clearError();
	};

	/**
	 * @param {number} bytes File size.
	 * @return {string}
	 */
	FileDropzone.prototype.formatBytes = function (bytes) {
		if (!bytes || bytes < 0) {
			return this.i18n.bytesZero || '';
		}

		var units = [
			this.i18n.unitB,
			this.i18n.unitKB,
			this.i18n.unitMB,
			this.i18n.unitGB,
			this.i18n.unitTB
		];
		var i = 0;
		var value = bytes;
		while (value >= 1024 && i < units.length - 1) {
			value /= 1024;
			i += 1;
		}

		var rounded = value >= 10 || i === 0 ? Math.round(value) : Math.round(value * 10) / 10;
		var format = this.i18n.bytesFormat || '%1$s %2$s';
		return format.replace('%1$s', String(rounded)).replace('%2$s', units[i] || '');
	};

	/**
	 * @param {string} message Error text.
	 */
	FileDropzone.prototype.showError = function (message) {
		if (!this.errorEl) {
			return;
		}
		this.errorEl.textContent = message;
		this.errorEl.hidden = false;
		this.root.classList.add('has-error');
	};

	FileDropzone.prototype.clearError = function () {
		if (!this.errorEl) {
			return;
		}
		this.errorEl.textContent = '';
		this.errorEl.hidden = true;
		this.root.classList.remove('has-error');
	};

	/**
	 * @param {Object} config Localized config.
	 */
	function autoInit(config) {
		var i18n = config && config.i18n ? config.i18n : {};
		var zones = document.querySelectorAll('[data-mksddn-dropzone="true"]');

		zones.forEach(function (zone) {
			if (zone.dataset.dropzoneInitialized === 'true') {
				return;
			}
			new FileDropzone(zone, i18n);
			zone.dataset.dropzoneInitialized = 'true';
		});
	}

	window.MksDdnFileDropzone = FileDropzone;
	window.MksDdnFileDropzone.autoInit = autoInit;

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			autoInit(window.mksddnFileDropzone || {});
		});
	} else {
		autoInit(window.mksddnFileDropzone || {});
	}
})();
