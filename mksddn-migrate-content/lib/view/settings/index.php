<?php
/**
 * Settings page template
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

$settings = isset( $settings ) ? $settings : array();
$default_excludes = isset( $default_excludes ) ? $default_excludes : array();
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-settings-page">
		<form id="mksddn-mc-settings-form" method="post">
			<?php wp_nonce_field( 'mksddn_mc_settings', 'nonce' ); ?>
			<input type="hidden" name="action" value="mksddn_mc_settings_save" />

			<div class="mksddn-mc-settings-tabs">
				<h2 class="nav-tab-wrapper">
					<a href="#export-settings" class="nav-tab nav-tab-active"><?php esc_html_e( 'Export Settings', 'mksddn-migrate-content' ); ?></a>
					<a href="#import-settings" class="nav-tab"><?php esc_html_e( 'Import Settings', 'mksddn-migrate-content' ); ?></a>
					<a href="#backup-settings" class="nav-tab"><?php esc_html_e( 'Backup Settings', 'mksddn-migrate-content' ); ?></a>
				</h2>

				<div id="export-settings" class="mksddn-mc-settings-tab-content">
					<h3><?php esc_html_e( 'Exclude Files', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Enter file names to exclude from export (one per line).', 'mksddn-migrate-content' ); ?></p>
					<textarea name="exclude_files" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", isset( $settings['exclude_files'] ) ? $settings['exclude_files'] : array() ) ); ?></textarea>

					<h3><?php esc_html_e( 'Exclude Directories', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Enter directory names to exclude from export (one per line).', 'mksddn-migrate-content' ); ?></p>
					<textarea name="exclude_directories" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", isset( $settings['exclude_directories'] ) ? $settings['exclude_directories'] : array() ) ); ?></textarea>

					<h3><?php esc_html_e( 'Exclude Extensions', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Enter file extensions to exclude from export (one per line, without dot).', 'mksddn-migrate-content' ); ?></p>
					<textarea name="exclude_extensions" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", isset( $settings['exclude_extensions'] ) ? $settings['exclude_extensions'] : array() ) ); ?></textarea>

					<h3><?php esc_html_e( 'Exclude Database Tables', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Enter table names to exclude from export (one per line).', 'mksddn-migrate-content' ); ?></p>
					<textarea name="exclude_tables" rows="5" class="large-text"><?php echo esc_textarea( implode( "\n", isset( $settings['exclude_tables'] ) ? $settings['exclude_tables'] : array() ) ); ?></textarea>
				</div>

				<div id="import-settings" class="mksddn-mc-settings-tab-content" style="display: none;">
					<h3><?php esc_html_e( 'URL Replacement', 'mksddn-migrate-content' ); ?></h3>
					<label>
						<input type="checkbox" name="import_replace_urls" value="1" <?php checked( isset( $settings['import_replace_urls'] ) ? $settings['import_replace_urls'] : true, true ); ?> />
						<?php esc_html_e( 'Replace URLs during import', 'mksddn-migrate-content' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Automatically replace old URLs with new site URL during import.', 'mksddn-migrate-content' ); ?></p>

					<h3><?php esc_html_e( 'Path Replacement', 'mksddn-migrate-content' ); ?></h3>
					<label>
						<input type="checkbox" name="import_replace_paths" value="1" <?php checked( isset( $settings['import_replace_paths'] ) ? $settings['import_replace_paths'] : true, true ); ?> />
						<?php esc_html_e( 'Replace file paths during import', 'mksddn-migrate-content' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Automatically replace old file paths with new paths during import.', 'mksddn-migrate-content' ); ?></p>
				</div>

				<div id="backup-settings" class="mksddn-mc-settings-tab-content" style="display: none;">
					<h3><?php esc_html_e( 'Backup Retention', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Number of backups to keep (0 = unlimited).', 'mksddn-migrate-content' ); ?></p>
					<input type="number" name="backups_retention" value="<?php echo esc_attr( isset( $settings['backups_retention'] ) ? $settings['backups_retention'] : 0 ); ?>" min="0" class="small-text" />

					<h3><?php esc_html_e( 'Backup Path', 'mksddn-migrate-content' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Directory where backup files are stored.', 'mksddn-migrate-content' ); ?></p>
					<input type="text" name="backups_path" value="<?php echo esc_attr( isset( $settings['backups_path'] ) ? $settings['backups_path'] : MKSDDN_MC_DEFAULT_BACKUPS_PATH ); ?>" class="large-text" />
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'mksddn-migrate-content' ); ?></button>
			</p>
		</form>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	// Tab switching
	$('.nav-tab').on('click', function(e) {
		e.preventDefault();
		var target = $(this).attr('href');

		$('.nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');

		$('.mksddn-mc-settings-tab-content').hide();
		$(target).show();
	});

	// Form submission
	$('#mksddn-mc-settings-form').on('submit', function(e) {
		e.preventDefault();

		var $form = $(this);
		var $button = $form.find('button[type="submit"]');

		$button.prop('disabled', true).text('<?php echo esc_js( __( 'Saving...', 'mksddn-migrate-content' ) ); ?>');

		$.ajax({
			url: mksddnMc.ajaxUrl,
			type: 'POST',
			data: $form.serialize(),
			success: function(response) {
				if (response.success) {
					$button.prop('disabled', false).text('<?php echo esc_js( __( 'Save Settings', 'mksddn-migrate-content' ) ); ?>');
					alert(response.data ? response.data.message : '<?php echo esc_js( __( 'Settings saved successfully.', 'mksddn-migrate-content' ) ); ?>');
				} else {
					$button.prop('disabled', false).text('<?php echo esc_js( __( 'Save Settings', 'mksddn-migrate-content' ) ); ?>');
					alert(response.data ? response.data.message : '<?php echo esc_js( __( 'Failed to save settings.', 'mksddn-migrate-content' ) ); ?>');
				}
			},
			error: function() {
				$button.prop('disabled', false).text('<?php echo esc_js( __( 'Save Settings', 'mksddn-migrate-content' ) ); ?>');
				alert('<?php echo esc_js( __( 'Network error. Please try again.', 'mksddn-migrate-content' ) ); ?>');
			}
		});
	});
});
</script>

