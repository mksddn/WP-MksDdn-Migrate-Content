<?php
/**
 * Full site import section template.
 *
 * @package MksDdn\MigrateContent
 * @var array|null $pending_user_preview Pending user preview data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Full Site Import', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Import everything (database + wp-content) from a .wpbkp archive.', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<div class="mksddn-mc-card">
			<?php if ( $pending_user_preview ) : ?>
				<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/user-preview.php', array( 'preview' => $pending_user_preview ) ); ?>
			<?php else : ?>
				<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/full-import-form.php' ); ?>
			<?php endif; ?>
		</div>
	</div>
</section>
