<?php
/**
 * Unified import form template.
 *
 * @package MksDdn\MigrateContent
 *
 * @var array|null $mksddn_mc_preflight_report    Preflight report payload when returning from step 1.
 * @var string     $mksddn_mc_preflight_report_id Report id for step 2 (import) form.
 * @var string     $mksddn_mc_active_source_tab   Active source tab: upload|server.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_preflight_report_id = isset( $mksddn_mc_preflight_report_id ) ? (string) $mksddn_mc_preflight_report_id : '';
$mksddn_mc_active_source_tab   = isset( $mksddn_mc_active_source_tab ) ? (string) $mksddn_mc_active_source_tab : 'upload';
if ( ! in_array( $mksddn_mc_active_source_tab, array( 'upload', 'server' ), true ) ) {
	$mksddn_mc_active_source_tab = 'upload';
}

$mksddn_mc_imports_dir = wp_upload_dir();
?>
<section class="mksddn-mc-section">
	<p><?php esc_html_e( 'Upload or select a backup file (.wpbkp or .json). First run preflight; then start the import from the report without uploading again.', 'mksddn-migrate-content' ); ?></p>

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

<?php if ( ! empty( $mksddn_mc_preflight_report ) ) : ?>
	<?php
	\MksDdn\MigrateContent\Core\View\ViewRenderer::render_template(
		'admin/preflight-report.php',
		array(
			'mksddn_mc_preflight_report'    => $mksddn_mc_preflight_report,
			'mksddn_mc_preflight_report_id' => $mksddn_mc_preflight_report_id,
		)
	);
	?>
<?php endif; ?>
<?php if ( $mksddn_mc_pending_user_preview ) : ?>
	<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/user-preview.php', array( 'preview' => $mksddn_mc_pending_user_preview ) ); ?>
<?php elseif ( $mksddn_mc_pending_theme_preview ) : ?>
	<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/theme-preview.php', array( 'preview' => $mksddn_mc_pending_theme_preview ) ); ?>
<?php elseif ( empty( $mksddn_mc_preflight_report ) ) : ?>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mksddn-mc-unified-import-form" data-mksddn-unified-import="true">
			<?php wp_nonce_field( 'mksddn_mc_unified_import' ); ?>
			<input type="hidden" name="action" value="mksddn_mc_unified_import">
			<input type="hidden" name="import_source" value="<?php echo esc_attr( $mksddn_mc_active_source_tab ); ?>">

			<div class="mksddn-mc-field">
				<?php if ( 'upload' === $mksddn_mc_active_source_tab ) : ?>
					<div class="mksddn-mc-import-source-upload">
						<div
							class="mksddn-mc-dropzone"
							data-mksddn-dropzone="true"
							tabindex="0"
							role="button"
							aria-controls="import_file"
							aria-label="<?php esc_attr_e( 'Upload backup file. Drop a .wpbkp or .json file here, or press Enter to browse.', 'mksddn-migrate-content' ); ?>"
						>
							<input
								type="file"
								id="import_file"
								name="import_file"
								class="mksddn-mc-file-input mksddn-mc-dropzone__input"
								accept=".wpbkp,.json,application/json"
								required
							>
							<div class="mksddn-mc-dropzone__idle">
								<span class="mksddn-mc-dropzone__icon" aria-hidden="true"></span>
								<p class="mksddn-mc-dropzone__title"><?php esc_html_e( 'Drop .wpbkp or .json here', 'mksddn-migrate-content' ); ?></p>
								<p class="mksddn-mc-dropzone__hint"><?php esc_html_e( 'or click to browse', 'mksddn-migrate-content' ); ?></p>
							</div>
							<div class="mksddn-mc-dropzone__selected" hidden>
								<span class="mksddn-mc-dropzone__file-name"></span>
								<span class="mksddn-mc-dropzone__file-size"></span>
								<button type="button" class="button-link mksddn-mc-dropzone__clear">
									<?php esc_html_e( 'Remove', 'mksddn-migrate-content' ); ?>
								</button>
							</div>
							<p class="mksddn-mc-dropzone__error" hidden></p>
						</div>
						<p class="description"><?php esc_html_e( 'Archives (.wpbkp) include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ); ?></p>
					</div>
				<?php else : ?>
					<div class="mksddn-mc-import-source-server">
						<div class="mksddn-mc-server-file-row">
							<select name="server_file" id="mksddn-mc-unified-server-file" required>
								<option value=""><?php esc_html_e( 'Select a file...', 'mksddn-migrate-content' ); ?></option>
							</select>
							<button type="button" class="button button-secondary mksddn-mc-delete-server-file" disabled><?php esc_html_e( 'Delete', 'mksddn-migrate-content' ); ?></button>
						</div>
						<p class="description"><?php esc_html_e( 'Select an import file from the server directory. You can delete unused backups from the server.', 'mksddn-migrate-content' ); ?></p>
						<div class="mksddn-mc-server-file-notice notice notice-error" style="display: none; margin-top: 0.5rem;"></div>
					</div>
				<?php endif; ?>
			</div>

			<p class="description"><?php esc_html_e( 'This step only analyzes the file. No database or file changes are made yet.', 'mksddn-migrate-content' ); ?></p>

			<button type="submit" class="button button-primary" id="mksddn-mc-unified-import-submit"><?php esc_html_e( 'Run preflight', 'mksddn-migrate-content' ); ?></button>
		</form>
	<?php endif; ?>
</section>
