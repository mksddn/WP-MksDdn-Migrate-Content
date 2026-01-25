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
	<h2><?php esc_html_e( 'Full Site Export', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Export everything (database + wp-content) via chunked transfer.', 'mksddn-migrate-content' ); ?></p>
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
