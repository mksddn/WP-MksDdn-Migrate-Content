<?php
/**
 * Selected import card template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mksddn-mc-card">
	<h3><?php esc_html_e( 'Import', 'mksddn-migrate-content' ); ?></h3>
	<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'import_single_page_nonce' ); ?>
		<div class="mksddn-mc-field">
			<h4><?php esc_html_e( 'Choose File', 'mksddn-migrate-content' ); ?></h4>
			
			<div class="mksddn-mc-import-source-toggle">
				<label>
					<input type="radio" name="import_source" value="upload" checked>
					<?php esc_html_e( 'Upload file', 'mksddn-migrate-content' ); ?>
				</label>
				<label>
					<input type="radio" name="import_source" value="server">
					<?php esc_html_e( 'Select from server', 'mksddn-migrate-content' ); ?>
				</label>
			</div>

			<div class="mksddn-mc-import-source-upload">
				<label for="import_file" class="screen-reader-text"><?php esc_html_e( 'Upload .wpbkp or .json file:', 'mksddn-migrate-content' ); ?></label>
				<input type="file" id="import_file" name="import_file" accept=".wpbkp,.json" required><br>
				<p class="description"><?php esc_html_e( 'Archives include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ); ?></p>
			</div>

			<div class="mksddn-mc-import-source-server" style="display: none;">
				<select name="server_file" id="mksddn-mc-selected-server-file" style="width: 100%; max-width: 500px;">
					<option value=""><?php esc_html_e( 'Select a file...', 'mksddn-migrate-content' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Select an import file from the server directory.', 'mksddn-migrate-content' ); ?></p>
				<div class="mksddn-mc-server-file-notice notice notice-error" style="display: none; margin-top: 0.5rem;"></div>
			</div>
		</div>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected file', 'mksddn-migrate-content' ); ?></button>
	</form>
</div>

<script>
(function() {
	function initServerFileSelector() {
		if (typeof window.MksDdnServerFileSelector === 'undefined') {
			// Retry after a short delay if script not loaded yet.
			setTimeout(initServerFileSelector, 100);
			return;
		}

		const form = document.querySelector('.mksddn-mc-card form');
		if (!form) return;

		// Check if already initialized.
		if (form.dataset.serverFileSelectorInitialized === 'true') {
			return;
		}

		const selector = new window.MksDdnServerFileSelector({
			form: form,
			uploadRadio: form.querySelector('input[value="upload"]'),
			serverRadio: form.querySelector('input[value="server"]'),
			uploadDiv: form.querySelector('.mksddn-mc-import-source-upload'),
			serverDiv: form.querySelector('.mksddn-mc-import-source-server'),
			fileInput: form.querySelector('#import_file'),
			serverSelect: form.querySelector('#mksddn-mc-selected-server-file'),
			ajaxAction: window.mksddnServerFileSelector ? window.mksddnServerFileSelector.ajaxAction : 'mksddn_mc_get_server_backups',
			nonce: window.mksddnServerFileSelector ? window.mksddnServerFileSelector.nonce : '',
			i18n: window.mksddnServerFileSelector ? window.mksddnServerFileSelector.i18n : {}
		});

		form.dataset.serverFileSelectorInitialized = 'true';
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initServerFileSelector);
	} else {
		initServerFileSelector();
	}
})();
</script>

