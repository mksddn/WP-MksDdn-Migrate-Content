<?php
/**
 * Selected content import section template.
 *
 * @package MksDdn\MigrateContent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Selected Content Import', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Import selected content from a .wpbkp or .json archive.', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<?php
		\MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/selected-import-card.php' );
		?>
	</div>
</section>
