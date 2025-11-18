<?php
/**
 * Import page template
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-import-page">
		<div class="mksddn-mc-import-form">
			<h2><?php esc_html_e( 'Import Site', 'mksddn-migrate-content' ); ?></h2>
			<p><?php esc_html_e( 'Import a migration file to restore or migrate your site.', 'mksddn-migrate-content' ); ?></p>

			<form id="mksddn-mc-import-form" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'mksddn_mc_import', 'nonce' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_import" />
				<input type="hidden" name="priority" value="5" />

				<div class="mksddn-mc-upload-area" id="mksddn-mc-upload-area">
					<div class="mksddn-mc-upload-icon">📁</div>
					<p class="mksddn-mc-upload-text">
						<?php esc_html_e( 'Drag and drop your .mksddn file here', 'mksddn-migrate-content' ); ?>
						<br />
						<strong><?php esc_html_e( 'or', 'mksddn-migrate-content' ); ?></strong>
					</p>
					<label for="mksddn-mc-upload-file" class="button button-primary">
						<?php esc_html_e( 'Choose File', 'mksddn-migrate-content' ); ?>
					</label>
					<input type="file" id="mksddn-mc-upload-file" name="upload_file" accept=".mksddn,.migrate" style="display: none;" />
					<p class="mksddn-mc-upload-info">
						<?php esc_html_e( 'Maximum file size:', 'mksddn-migrate-content' ); ?>
						<?php echo esc_html( size_format( wp_max_upload_size() ) ); ?>
					</p>
				</div>

				<div id="mksddn-mc-import-progress" class="mksddn-mc-progress" style="display: none;">
					<div class="mksddn-mc-progress-bar">
						<div class="mksddn-mc-progress-fill" style="width: 0%;"></div>
					</div>
					<p class="mksddn-mc-progress-text"></p>
				</div>
			</form>
		</div>
	</div>
</div>

