<?php
/**
 * Unified import form template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Import', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Upload or select a backup file (.wpbkp or .json). The system will automatically detect the import type.', 'mksddn-migrate-content' ); ?></p>
	<?php
	$mksddn_mc_imports_dir = wp_upload_dir();
	?>
	<div class="notice notice-info" style="margin: 15px 0;">
		<p>
			<strong><?php esc_html_e( 'Tip:', 'mksddn-migrate-content' ); ?></strong>
			<?php
			printf(
				/* translators: %s: imports directory path */
				esc_html__( 'For large files, it is recommended to upload them via FTP/SFTP to the %s directory and then use the "Select from server" option.', 'mksddn-migrate-content' ),
				'<code>' . esc_html( str_replace( ABSPATH, '', trailingslashit( $mksddn_mc_imports_dir['basedir'] ) . 'mksddn-mc/imports/' ) ) . '</code>'
			);
			?>
		</p>
	</div>
	<?php if ( $pending_user_preview ) : ?>
		<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/user-preview.php', array( 'preview' => $pending_user_preview ) ); ?>
	<?php else : ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mksddn-mc-unified-import-form" data-mksddn-unified-import="true">
			<?php wp_nonce_field( 'mksddn_mc_unified_import' ); ?>
			<input type="hidden" name="action" value="mksddn_mc_unified_import">
			
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
					<input type="file" id="import_file" name="import_file" accept=".wpbkp,.json" required>
					<p class="description"><?php esc_html_e( 'Archives (.wpbkp) include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ); ?></p>
				</div>

				<div class="mksddn-mc-import-source-server" style="display: none;">
					<select name="server_file" id="mksddn-mc-unified-server-file" style="width: 100%; max-width: 500px;">
						<option value=""><?php esc_html_e( 'Select a file...', 'mksddn-migrate-content' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Select an import file from the server directory.', 'mksddn-migrate-content' ); ?></p>
					<div class="mksddn-mc-server-file-notice notice notice-error" style="display: none; margin-top: 0.5rem;"></div>
				</div>
			</div>
			
			<div class="mksddn-mc-field" id="mksddn-mc-theme-import-mode" style="display: none;">
				<h4><?php esc_html_e( 'Theme Import Mode', 'mksddn-migrate-content' ); ?></h4>
				<label style="display: block; margin-bottom: 10px;">
					<input type="radio" name="import_mode" value="replace" checked>
					<strong><?php esc_html_e( 'Replace', 'mksddn-migrate-content' ); ?></strong>
					<p class="description" style="margin-left: 25px;">
						<?php esc_html_e( 'Remove existing theme directory and replace with files from archive.', 'mksddn-migrate-content' ); ?>
					</p>
				</label>
				<label style="display: block;">
					<input type="radio" name="import_mode" value="merge">
					<strong><?php esc_html_e( 'Merge', 'mksddn-migrate-content' ); ?></strong>
					<p class="description" style="margin-left: 25px;">
						<?php esc_html_e( 'Combine files from archive with existing theme. Files from archive will overwrite existing files.', 'mksddn-migrate-content' ); ?>
					</p>
				</label>
			</div>
			
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'mksddn-migrate-content' ); ?></button>
		</form>
	<?php endif; ?>
</section>
