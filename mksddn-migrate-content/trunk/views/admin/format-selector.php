<?php
/**
 * Format selector template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mksddn-mc-format-selector">
	<div class="mksddn-mc-basic-selection">
		<label for="export_format"><?php esc_html_e( 'Choose file format:', 'mksddn-migrate-content' ); ?></label>
		<select id="export_format" name="export_format">
			<option value="archive" selected><?php esc_html_e( '.wpbkp (archive with manifest)', 'mksddn-migrate-content' ); ?></option>
			<option value="json"><?php esc_html_e( '.json (content only, editable)', 'mksddn-migrate-content' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( '.json skips media files and is best for quick edits. .wpbkp packs media + checksum.', 'mksddn-migrate-content' ); ?></p><br>
	</div>
</div>

