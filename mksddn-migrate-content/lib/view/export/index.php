<?php
/**
 * Export page template
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-export-page">
		<div class="mksddn-mc-export-form">
			<h2><?php esc_html_e( 'Export Site', 'mksddn-migrate-content' ); ?></h2>
			<p><?php esc_html_e( 'Export your entire WordPress site to a migration file.', 'mksddn-migrate-content' ); ?></p>

			<form id="mksddn-mc-export-form" method="post">
				<?php wp_nonce_field( 'mksddn_mc_export', 'nonce' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_export" />
				<input type="hidden" name="priority" value="5" />

				<div class="mksddn-mc-export-options">
					<h3><?php esc_html_e( 'Export Options', 'mksddn-migrate-content' ); ?></h3>
					<p>
						<label>
							<input type="checkbox" name="export_database" value="1" checked />
							<?php esc_html_e( 'Export database', 'mksddn-migrate-content' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="export_media" value="1" checked />
							<?php esc_html_e( 'Export media files', 'mksddn-migrate-content' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="export_plugins" value="1" checked />
							<?php esc_html_e( 'Export plugins', 'mksddn-migrate-content' ); ?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="export_themes" value="1" checked />
							<?php esc_html_e( 'Export themes', 'mksddn-migrate-content' ); ?>
						</label>
					</p>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary button-large" id="mksddn-mc-export-button">
						<?php esc_html_e( 'Export Site', 'mksddn-migrate-content' ); ?>
					</button>
				</p>
			</form>

			<div id="mksddn-mc-export-progress" class="mksddn-mc-progress" style="display: none;">
				<div class="mksddn-mc-progress-bar">
					<div class="mksddn-mc-progress-fill" style="width: 0%;"></div>
				</div>
				<p class="mksddn-mc-progress-text"></p>
			</div>
		</div>
	</div>
</div>

