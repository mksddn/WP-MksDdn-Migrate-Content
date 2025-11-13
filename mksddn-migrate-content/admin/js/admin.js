/**
 * Admin scripts.
 *
 * @package MksDdn_Migrate_Content
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		initExport();
		initImport();
		initHistory();
	});

	/**
	 * Initialize export functionality.
	 */
	function initExport() {
		var $exportForm = $('#mksddn-mc-export-form');
		var $exportType = $('#export-type');
		var $selectiveOptions = $('#selective-options');
		var $selectiveSlugs = $('#selective-slugs');
		var $exportButton = $('#mksddn-mc-export-button');
		var $progress = $('#mksddn-mc-export-progress');
		var $result = $('#mksddn-mc-export-result');

		$exportType.on('change', function() {
			if ($(this).val() === 'selective') {
				$selectiveOptions.show();
				$selectiveSlugs.show();
			} else {
				$selectiveOptions.hide();
				$selectiveSlugs.hide();
			}
		});

		$exportForm.on('submit', function(e) {
			e.preventDefault();

			var exportType = $exportType.val();
			var data = {
				action: 'mksddn_mc_export',
				nonce: mksddnMcAdmin.nonce,
				type: exportType
			};

			if (exportType === 'selective') {
				data.post_types = $('input[name="post_types[]"]:checked').map(function() {
					return $(this).val();
				}).get();
				data.slugs = $('#post-slugs').val().split('\n').map(function(slug) {
					return slug.trim();
				}).filter(function(slug) {
					return slug.length > 0;
				});
			}

			$exportButton.prop('disabled', true);
			$progress.show();
			$result.hide();
			updateProgress(0, mksddnMcAdmin.i18n.exporting);

			$.ajax({
				url: mksddnMcAdmin.ajaxUrl,
				type: 'POST',
				data: data,
				success: function(response) {
					$exportButton.prop('disabled', false);
					updateProgress(100, mksddnMcAdmin.i18n.success);

					if (response.success) {
						var downloadText = mksddnMcAdmin.i18n.download || 'Download';
						showResult('success', response.data.message + '<br><a href="' + response.data.file_url + '" download>' + downloadText + '</a>');
					} else {
						showResult('error', response.data.message);
					}
				},
				error: function() {
					$exportButton.prop('disabled', false);
					showResult('error', mksddnMcAdmin.i18n.error);
				}
			});
		});
	}

	/**
	 * Initialize import functionality.
	 */
	function initImport() {
		var $dropzone = $('#mksddn-mc-dropzone');
		var $fileInput = $('#mksddn-mc-import-file');
		var $importButton = $('#mksddn-mc-import-button');
		var $progress = $('#mksddn-mc-import-progress');
		var $result = $('#mksddn-mc-import-result');

		$dropzone.on('click', function() {
			$fileInput.click();
		});

		$fileInput.on('change', function() {
			if (this.files.length > 0) {
				$importButton.prop('disabled', false);
				$dropzone.find('.mksddn-mc-dropzone-text').text(this.files[0].name);
			}
		});

		$dropzone.on('dragover', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).addClass('dragover');
		});

		$dropzone.on('dragleave', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).removeClass('dragover');
		});

		$dropzone.on('drop', function(e) {
			e.preventDefault();
			e.stopPropagation();
			$(this).removeClass('dragover');

			var files = e.originalEvent.dataTransfer.files;
			if (files.length > 0) {
				$fileInput[0].files = files;
				$importButton.prop('disabled', false);
				$dropzone.find('.mksddn-mc-dropzone-text').text(files[0].name);
			}
		});

		$importButton.on('click', function() {
			if ($fileInput[0].files.length === 0) {
				alert(mksddnMcAdmin.i18n.selectFile);
				return;
			}

			var formData = new FormData();
			formData.append('action', 'mksddn_mc_import');
			formData.append('nonce', mksddnMcAdmin.nonce);
			formData.append('import_file', $fileInput[0].files[0]);

			$importButton.prop('disabled', true);
			$progress.show();
			$result.hide();
			updateProgress(0, mksddnMcAdmin.i18n.importing);

			$.ajax({
				url: mksddnMcAdmin.ajaxUrl,
				type: 'POST',
				data: formData,
				processData: false,
				contentType: false,
				success: function(response) {
					$importButton.prop('disabled', false);
					updateProgress(100, mksddnMcAdmin.i18n.success);

					if (response.success) {
						showResult('success', response.data.message);
					} else {
						showResult('error', response.data.message);
					}
				},
				error: function() {
					$importButton.prop('disabled', false);
					showResult('error', mksddnMcAdmin.i18n.error);
				}
			});
		});
	}

	/**
	 * Initialize history tabs.
	 */
	function initHistory() {
		$('.mksddn-mc-history-tabs .nav-tab').on('click', function(e) {
			e.preventDefault();
			var target = $(this).attr('href');

			$('.mksddn-mc-history-tabs .nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			$('.mksddn-mc-history-section').hide();
			$(target).show();
		});
	}

	/**
	 * Update progress bar.
	 */
	function updateProgress(percent, text) {
		$('.mksddn-mc-progress-fill').css('width', percent + '%');
		$('.mksddn-mc-progress-text').text(text);
	}

	/**
	 * Show result message.
	 */
	function showResult(type, message) {
		var $result = $('.mksddn-mc-export-result, .mksddn-mc-import-result');
		$result.removeClass('success error').addClass(type);
		$result.html(message).show();
	}

})(jQuery);

