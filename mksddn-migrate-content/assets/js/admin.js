/**
 * Admin scripts
 *
 * @package MksDdn_Migrate_Content
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		// Export form handler
		$('#mksddn-mc-export-form').on('submit', function(e) {
			e.preventDefault();

			var $form = $(this);
			var $button = $('#mksddn-mc-export-button');
			var $progress = $('#mksddn-mc-export-progress');
			var $progressBar = $('.mksddn-mc-progress-fill');
			var $progressText = $('.mksddn-mc-progress-text');

			// Disable button
			$button.prop('disabled', true).text('Exporting...');

			// Show progress
			$progress.show();
			$progressText.text('Starting export...');

			// Start export
			startExport($form, $progressBar, $progressText, $button);
		});
	});

	/**
	 * Start export process
	 */
	function startExport($form, $progressBar, $progressText, $button) {
		var formData = $form.serialize();
		formData += '&action=mksddn_mc_export';

		$.ajax({
			url: mksddnMc.ajaxUrl,
			type: 'POST',
			data: formData,
			success: function(response) {
				if (response.success) {
					// Check if export is completed
					if (response.data && response.data.completed) {
						$progressBar.css('width', '100%');
						$progressText.text('Export completed!');
						$button.prop('disabled', false).text('Export Site');
					} else {
						// Continue export
						updateProgress($progressBar, $progressText);
						setTimeout(function() {
							startExport($form, $progressBar, $progressText, $button);
						}, 1000);
					}
				} else {
					$progressText.text('Export failed: ' + (response.data ? response.data.message : 'Unknown error'));
					$button.prop('disabled', false).text('Export Site');
				}
			},
			error: function() {
				$progressText.text('Export failed: Network error');
				$button.prop('disabled', false).text('Export Site');
			}
		});
	}

	/**
	 * Update progress bar
	 */
	function updateProgress($progressBar, $progressText) {
		$.ajax({
			url: mksddnMc.ajaxUrl,
			type: 'POST',
			data: {
				action: 'mksddn_mc_status',
				nonce: mksddnMc.nonce
			},
			success: function(response) {
				if (response.success && response.data) {
					var status = response.data;
					if (status.percent) {
						$progressBar.css('width', status.percent + '%');
					}
					if (status.message) {
						$progressText.text(status.message);
					}
				}
			}
		});
	}

	// Import form handler
	$('#mksddn-mc-import-form').on('submit', function(e) {
		e.preventDefault();

		var $form = $(this);
		var $progress = $('#mksddn-mc-import-progress');
		var $progressBar = $('.mksddn-mc-progress-fill');
		var $progressText = $('.mksddn-mc-progress-text');
		var $uploadArea = $('#mksddn-mc-upload-area');

		var fileInput = document.getElementById('mksddn-mc-upload-file');
		if (!fileInput.files || !fileInput.files[0]) {
			alert('Please select a file to import.');
			return;
		}

		// Show progress
		$progress.show();
		$uploadArea.hide();
		$progressText.text('Uploading file...');

		// Create FormData
		var formData = new FormData($form[0]);
		formData.append('action', 'mksddn_mc_import');

		// Start import
		startImport(formData, $progressBar, $progressText, $uploadArea);
	});

	// File input change handler
	$('#mksddn-mc-upload-file').on('change', function() {
		var fileName = $(this).val().split('\\').pop();
		if (fileName) {
			$('.mksddn-mc-upload-text').html(
				'<strong>' + fileName + '</strong><br />' +
				'<button type="submit" class="button button-primary">Import Site</button>'
			);
		}
	});

	// Drag and drop handlers
	var $uploadArea = $('#mksddn-mc-upload-area');
	var $fileInput = $('#mksddn-mc-upload-file');

	$uploadArea.on('dragover', function(e) {
		e.preventDefault();
		$(this).addClass('dragover');
	});

	$uploadArea.on('dragleave', function(e) {
		e.preventDefault();
		$(this).removeClass('dragover');
	});

	$uploadArea.on('drop', function(e) {
		e.preventDefault();
		$(this).removeClass('dragover');

		var files = e.originalEvent.dataTransfer.files;
		if (files.length > 0) {
			$fileInput[0].files = files;
			$fileInput.trigger('change');
		}
	});

	$uploadArea.on('click', function() {
		$fileInput.click();
	});

	/**
	 * Start import process
	 */
	function startImport(formData, $progressBar, $progressText, $uploadArea) {
		$.ajax({
			url: mksddnMc.ajaxUrl,
			type: 'POST',
			data: formData,
			processData: false,
			contentType: false,
			success: function(response) {
				if (response.success) {
					// Check if confirmation is required
					if (response.data && response.data.requires_confirmation) {
						if (confirm('Are you sure you want to import this archive? This will replace your current site data.')) {
							formData.append('confirmed', '1');
							startImport(formData, $progressBar, $progressText, $uploadArea);
						} else {
							$progress.hide();
							$uploadArea.show();
						}
						return;
					}

					// Check if import is completed
					if (response.data && response.data.completed) {
						$progressBar.css('width', '100%');
						$progressText.text('Import completed!');
					} else {
						// Continue import - update formData with new params
						if (response.data && response.data.priority) {
							formData.set('priority', response.data.priority);
						}
						updateProgress($progressBar, $progressText);
						setTimeout(function() {
							startImport(formData, $progressBar, $progressText, $uploadArea);
						}, 1000);
					}
				} else {
					$progressText.text('Import failed: ' + (response.data ? response.data.message : 'Unknown error'));
				}
			},
			error: function() {
				$progressText.text('Import failed: Network error');
			}
		});
	}
})(jQuery);

