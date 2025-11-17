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
})(jQuery);

