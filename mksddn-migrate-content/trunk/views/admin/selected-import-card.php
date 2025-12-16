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
			</div>
		</div>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected file', 'mksddn-migrate-content' ); ?></button>
	</form>
</div>

<script>
(function() {
	const form = document.querySelector('.mksddn-mc-card form');
	if (!form) return;

	const uploadRadio = form.querySelector('input[value="upload"]');
	const serverRadio = form.querySelector('input[value="server"]');
	const uploadDiv = form.querySelector('.mksddn-mc-import-source-upload');
	const serverDiv = form.querySelector('.mksddn-mc-import-source-server');
	const fileInput = form.querySelector('#import_file');
	const serverSelect = form.querySelector('#mksddn-mc-selected-server-file');

	function loadServerFiles() {
		serverSelect.innerHTML = '<option value=""><?php echo esc_js( __( 'Loading...', 'mksddn-migrate-content' ) ); ?></option>';
		
		fetch(ajaxurl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				action: 'mksddn_mc_get_server_backups',
				nonce: '<?php echo esc_js( wp_create_nonce( 'mksddn_mc_admin' ) ); ?>'
			})
		})
		.then(response => response.json())
		.then(data => {
			if (data.success && data.data.files && data.data.files.length > 0) {
				serverSelect.innerHTML = '<option value=""><?php echo esc_js( __( 'Select a file...', 'mksddn-migrate-content' ) ); ?></option>';
				data.data.files.forEach(file => {
					const option = document.createElement('option');
					option.value = file.name;
					option.textContent = file.name + ' (' + file.size_human + ', ' + file.modified_human + ')';
					serverSelect.appendChild(option);
				});
			} else {
				serverSelect.innerHTML = '<option value=""><?php echo esc_js( __( 'No backup files found', 'mksddn-migrate-content' ) ); ?></option>';
			}
		})
		.catch(error => {
			serverSelect.innerHTML = '<option value=""><?php echo esc_js( __( 'Error loading files', 'mksddn-migrate-content' ) ); ?></option>';
			console.error('Error loading server files:', error);
		});
	}

	function toggleSource() {
		if (uploadRadio.checked) {
			uploadDiv.style.display = 'block';
			serverDiv.style.display = 'none';
			fileInput.required = true;
			serverSelect.required = false;
			serverSelect.value = '';
		} else {
			uploadDiv.style.display = 'none';
			serverDiv.style.display = 'block';
			fileInput.required = false;
			fileInput.value = '';
			serverSelect.required = true;
			loadServerFiles();
		}
	}

	uploadRadio.addEventListener('change', toggleSource);
	serverRadio.addEventListener('change', toggleSource);

	form.addEventListener('submit', function(e) {
		if (serverRadio.checked) {
			if (!serverSelect.value) {
				e.preventDefault();
				alert('<?php echo esc_js( __( 'Please select a file from the server.', 'mksddn-migrate-content' ) ); ?>');
				return false;
			}
			fileInput.removeAttribute('required');
			fileInput.disabled = true;
		} else {
			serverSelect.removeAttribute('required');
		}
	});
})();
</script>

