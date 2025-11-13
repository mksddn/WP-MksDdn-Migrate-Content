<?php
/**
 * Import page template.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap mksddn-mc-import-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-import-form">
		<h2><?php esc_html_e( 'Import Options', 'mksddn-migrate-content' ); ?></h2>

		<div class="mksddn-mc-dropzone" id="mksddn-mc-dropzone">
			<p class="mksddn-mc-dropzone-text">
				<?php esc_html_e( 'Drag and drop your export file here, or click to select', 'mksddn-migrate-content' ); ?>
			</p>
			<input type="file" id="mksddn-mc-import-file" name="import_file" accept=".json" style="display: none;">
		</div>

		<p class="submit">
			<button type="button" class="button button-primary" id="mksddn-mc-import-button" disabled>
				<?php esc_html_e( 'Import', 'mksddn-migrate-content' ); ?>
			</button>
		</p>

		<div id="mksddn-mc-import-progress" style="display: none;">
			<div class="mksddn-mc-progress-bar">
				<div class="mksddn-mc-progress-fill"></div>
			</div>
			<p class="mksddn-mc-progress-text"></p>
		</div>

		<div id="mksddn-mc-import-result"></div>
	</div>
</div>

