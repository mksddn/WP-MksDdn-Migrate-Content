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
			<label for="import_file" class="screen-reader-text"><?php esc_html_e( 'Upload .wpbkp or .json file:', 'mksddn-migrate-content' ); ?></label>
			<input type="file" id="import_file" name="import_file" accept=".wpbkp,.json" required><br>
			<p class="description"><?php esc_html_e( 'Archives include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ); ?></p><br>
		</div>
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected file', 'mksddn-migrate-content' ); ?></button>
	</form>
</div>

