<?php
/**
 * Selected content export section template.
 *
 * @package MksDdn\MigrateContent
 * @var array  $exportable_types Exportable post types.
 * @var array  $items_by_type     Items grouped by post type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Selected Content Export', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Pick one or many entries (pages, posts, CPT) and export them with or without media.', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<?php
		\MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/selected-export-card.php', array( 'exportable_types' => $exportable_types, 'items_by_type' => $items_by_type ) );
		?>
	</div>
</section>
