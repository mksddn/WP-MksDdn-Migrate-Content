<?php
/**
 * Full site export section template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<p><?php esc_html_e( 'Pack the database and wp-content (uploads, plugins, mu-plugins, themes) into a .wpbkp archive. Large sites need free disk space in the PHP temp directory and may run for several minutes. Chunked download is used when available; direct download is used only for client or transport fallback.', 'mksddn-migrate-content' ); ?></p>

	<div class="notice notice-warning inline" style="margin: 12px 0;">
		<p>
			<strong><?php esc_html_e( 'Production warning:', 'mksddn-migrate-content' ); ?></strong>
			<?php esc_html_e( 'Full-site export is heavy. On small VPS hosts (low RAM, no swap) it can exhaust memory and take PHP-FPM offline for the whole site. Prefer staging, ensure free disk space, and avoid raising PHP memory_limit to multi-GB values on 1–2 GB servers. The exporter streams the database in chunks and refuses to start when disk/memory checks fail.', 'mksddn-migrate-content' ); ?>
		</p>
	</div>

	<div class="mksddn-mc-grid">
		<div class="mksddn-mc-card">
			<h3><?php esc_html_e( 'Export', 'mksddn-migrate-content' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mksddn-full-export="true">
				<?php wp_nonce_field( 'mksddn_mc_full_export' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_export_full">
				<button type="submit" class="button button-secondary"><?php esc_html_e( 'Export Full Site (.wpbkp)', 'mksddn-migrate-content' ); ?></button>
			</form>
		</div>
	</div>
</section>
