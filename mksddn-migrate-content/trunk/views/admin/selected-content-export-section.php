<?php
/**
 * Selected content export section template.
 *
 * @package MksDdn\MigrateContent
 * @var array $exportable_types Exportable post types.
 * @var array $options_pages    ACF Options Pages for UI (optional).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options_pages = isset( $options_pages ) && is_array( $options_pages ) ? $options_pages : array();
?>
<section class="mksddn-mc-section">
	<p><?php esc_html_e( 'Pick one or many entries (pages, posts, CPT) and optional ACF Options Pages, then export them with or without media.', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<?php
		\MksDdn\MigrateContent\Core\View\ViewRenderer::render_template(
			'admin/selected-export-card.php',
			array(
				'exportable_types' => $exportable_types,
				'options_pages'    => $options_pages,
			)
		);
		?>
	</div>
</section>
