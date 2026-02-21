<?php
/**
 * @file: theme-preview.php
 * @description: Theme import preview template
 * @dependencies: None
 * @created: 2026-02-21
 */

/**
 * Theme preview template.
 *
 * @package MksDdn\MigrateContent
 * @var array $preview Preview data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<h3><?php esc_html_e( 'Choose theme import mode', 'mksddn-migrate-content' ); ?></h3>
<p><?php esc_html_e( 'Select how the theme archive should be applied to this site.', 'mksddn-migrate-content' ); ?></p>
<p><strong><?php esc_html_e( 'Archive', 'mksddn-migrate-content' ); ?>:</strong> <?php echo esc_html( $preview['original_name'] ?: __( 'uploaded file', 'mksddn-migrate-content' ) ); ?></p>
<div class="notice notice-warning" style="margin: 15px 0;">
	<p>
		<strong><?php esc_html_e( 'Warning:', 'mksddn-migrate-content' ); ?></strong>
		<?php esc_html_e( 'Replace removes the existing theme directory before importing. If the theme is active, the site may be temporarily unavailable until import completes. Consider switching to a safe theme or using Merge.', 'mksddn-migrate-content' ); ?>
	</p>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-theme-plan">
	<?php wp_nonce_field( 'mksddn_mc_theme_preview_' . $preview['id'] ); ?>
	<input type="hidden" name="action" value="mksddn_mc_import_theme">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">

	<div class="mksddn-mc-field">
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

	<div class="mksddn-mc-user-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import themes', 'mksddn-migrate-content' ); ?></button>
	</div>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-inline-form">
	<?php wp_nonce_field( 'mksddn_mc_cancel_theme_preview_' . $preview['id'] ); ?>
	<input type="hidden" name="action" value="mksddn_mc_cancel_theme_preview">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">
	<button type="submit" class="button button-secondary"><?php esc_html_e( 'Cancel theme import', 'mksddn-migrate-content' ); ?></button>
</form>

