<?php
/**
 * @file: AdminPageView.php
 * @description: View class for rendering admin page sections
 * @dependencies: Core\View\ViewRenderer
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Views;

use MksDdn\MigrateContent\Core\View\ViewRenderer;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * View class for rendering admin page sections.
 *
 * @since 1.0.0
 */
class AdminPageView {

	/**
	 * View renderer.
	 *
	 * @var ViewRenderer
	 */
	private ViewRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param ViewRenderer|null      $renderer         View renderer.
	 * @since 1.0.0
	 */
	public function __construct(
		?ViewRenderer $renderer = null
	) {
		$this->renderer         = $renderer ?? new ViewRenderer();
	}

	/**
	 * Render admin page styles.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_styles(): void {
		// Styles are now enqueued via wp_enqueue_style() in AdminPageController::enqueue_assets().
	}

	/**
	 * Render full site section.
	 *
	 * @param array|null $pending_user_preview Pending user preview data.
	 * @return void
	 * @since 1.0.0
	 */
	public function render_full_site_section( ?array $pending_user_preview = null ): void {
		$this->renderer->render( 'admin/full-site-section.php', array( 'pending_user_preview' => $pending_user_preview ) );
	}

	/**
	 * Render selected content section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_selected_content_section(): void {
		$exportable_types = $this->get_exportable_post_types();
		$items_by_type    = array();

		foreach ( $exportable_types as $type => $label ) {
			$items_by_type[ $type ] = $this->get_items_for_type( $type );
		}

		$this->renderer->render(
			'admin/selected-content-section.php',
			array(
				'exportable_types' => $exportable_types,
				'items_by_type'    => $items_by_type,
			)
		);
	}


	/**
	 * Get exportable post types.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function get_exportable_post_types(): array {
		$objects = get_post_types(
			array(
				'show_ui' => true,
				'public'  => true,
			),
			'objects'
		);

		$types = array();
		foreach ( $objects as $type => $object ) {
			if ( in_array( $type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}
			$types[ $type ] = $object->labels->singular_name ?? $object->label ?? ucfirst( $type );
		}

		if ( ! isset( $types['page'] ) ) {
			$types = array( 'page' => __( 'Page', 'mksddn-migrate-content' ) ) + $types;
		}

		return $types;
	}

	/**
	 * Get items for post type.
	 *
	 * @param string $type Post type.
	 * @return WP_Post[]
	 * @since 1.0.0
	 */
	private function get_items_for_type( string $type ): array {
		if ( 'page' === $type ) {
			return get_pages();
		}

		return get_posts(
			array(
				'post_type'      => $type,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}


}

