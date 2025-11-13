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
		var $selectivePosts = $('#selective-posts');
		var $loadPostsButton = $('#mksddn-mc-load-posts');
		var $postsList = $('#mksddn-mc-posts-list');
		var $postsContent = $('#mksddn-mc-posts-content');
		var $postsLoading = $('#mksddn-mc-posts-loading');
		var $exportButton = $('#mksddn-mc-export-button');
		var $progress = $('#mksddn-mc-export-progress');
		var $result = $('#mksddn-mc-export-result');

		$exportType.on('change', function() {
			if ($(this).val() === 'selective') {
				$selectiveOptions.show();
				$selectivePosts.show();
			} else {
				$selectiveOptions.hide();
				$selectivePosts.hide();
				$postsList.hide();
			}
		});

		$loadPostsButton.on('click', function() {
			var postTypes = $('input[name="post_types[]"]:checked').map(function() {
				return $(this).val();
			}).get();

			if (postTypes.length === 0) {
				alert(mksddnMcAdmin.i18n.selectPostType || 'Please select at least one post type.');
				return;
			}

			$postsLoading.show();
			$postsContent.empty();
			$postsList.show();

			$.ajax({
				url: mksddnMcAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'mksddn_mc_get_posts',
					nonce: mksddnMcAdmin.nonce,
					post_types: postTypes
				},
				success: function(response) {
					$postsLoading.hide();

					if (response.success && response.data.posts.length > 0) {
						var html = '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="select-all-posts"> <strong>Select All</strong></label></div>';
						html += '<div style="max-height: 350px; overflow-y: auto;">';

						var groupedPosts = {};
						response.data.posts.forEach(function(post) {
							if (!groupedPosts[post.type]) {
								groupedPosts[post.type] = [];
							}
							groupedPosts[post.type].push(post);
						});

						Object.keys(groupedPosts).forEach(function(postType) {
							html += '<div style="margin-bottom: 15px;"><strong>' + escapeHtml(postType) + '</strong><br>';
							groupedPosts[postType].forEach(function(post) {
								html += '<label style="display: block; margin: 5px 0;"><input type="checkbox" name="selected_posts[]" value="' + escapeHtml(post.slug) + '" data-post-type="' + escapeHtml(post.type) + '"> ' + escapeHtml(post.title) + ' <span style="color: #666;">(' + escapeHtml(post.slug) + ')</span></label>';
							});
							html += '</div>';
						});

						html += '</div>';
						$postsContent.html(html);

						$('#select-all-posts').on('change', function() {
							$('input[name="selected_posts[]"]').prop('checked', $(this).prop('checked'));
						});
					} else {
						var noPostsText = mksddnMcAdmin.i18n.noPostsFound || 'No posts found for selected post types.';
						$postsContent.html('<p>' + noPostsText + '</p>');
					}
				},
				error: function() {
					$postsLoading.hide();
					var errorText = mksddnMcAdmin.i18n.errorLoadingPosts || 'Error loading posts.';
					$postsContent.html('<p style="color: red;">' + errorText + '</p>');
				}
			});
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
				var selectedPosts = $('input[name="selected_posts[]"]:checked');
				if (selectedPosts.length === 0) {
					alert(mksddnMcAdmin.i18n.selectPost || 'Please select at least one post to export.');
					return;
				}

				var postTypes = [];
				var slugs = [];

				selectedPosts.each(function() {
					var slug = $(this).val();
					var postType = $(this).data('post-type');
					slugs.push(slug);
					if (postTypes.indexOf(postType) === -1) {
						postTypes.push(postType);
					}
				});

				data.post_types = postTypes;
				data.slugs = slugs;
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

	/**
	 * Escape HTML to prevent XSS.
	 */
	function escapeHtml(text) {
		var map = {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;'
		};
		return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
	}

})(jQuery);

