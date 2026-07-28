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
	 * Render export sections (Full Site, Selected Content, and Theme export).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_export_sections(): void {
		$allowed_tabs = array( 'full', 'selected', 'themes' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switcher.
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'full';
		$active_tab    = in_array( $requested_tab, $allowed_tabs, true ) ? $requested_tab : 'full';

		$this->renderer->render(
			'admin/nav-tabs.php',
			array(
				'mksddn_mc_tabs'       => array(
					'full'     => __( 'Full Site Export', 'mksddn-migrate-content' ),
					'selected' => __( 'Selected Content Export', 'mksddn-migrate-content' ),
					'themes'   => __( 'Theme Export', 'mksddn-migrate-content' ),
				),
				'mksddn_mc_active_tab' => $active_tab,
				'mksddn_mc_tabs_mode'  => 'link',
				'mksddn_mc_tabs_base'  => admin_url( 'admin.php?page=mksddn-migrate-content-export' ),
				'mksddn_mc_tabs_class' => 'mksddn-mc-nav-tabs--page',
			)
		);

		echo '<div class="mksddn-mc-tab-panels">';

		if ( 'full' === $active_tab ) {
			$this->renderer->render( 'admin/full-site-export-section.php' );
		} elseif ( 'selected' === $active_tab ) {
			$exportable_types = $this->get_exportable_post_types();
			$items_by_type    = array();

			foreach ( $exportable_types as $type => $label ) {
				$items_by_type[ $type ] = $this->get_items_for_type( $type );
			}

			$this->renderer->render(
				'admin/selected-content-export-section.php',
				array(
					'exportable_types' => $exportable_types,
					'items_by_type'    => $items_by_type,
				)
			);
		} else {
			$theme_exporter   = new \MksDdn\MigrateContent\Filesystem\ThemeExporter();
			$available_themes = $theme_exporter->get_available_themes();
			$this->renderer->render(
				'admin/theme-export-section.php',
				array(
					'available_themes' => $available_themes,
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Render import sections (unified import form).
	 *
	 * @param array|null $pending_user_preview Pending user preview data.
	 * @param array|null $pending_theme_preview Pending theme preview data.
	 * @param array|null $preflight_context      Keys: report, report_id; or null.
	 * @return void
	 * @since 1.0.0
	 */
	public function render_import_sections( ?array $pending_user_preview = null, ?array $pending_theme_preview = null, ?array $preflight_context = null ): void {
		$preflight_report    = null;
		$preflight_report_id = '';
		if ( is_array( $preflight_context ) ) {
			$preflight_report    = isset( $preflight_context['report'] ) ? $preflight_context['report'] : null;
			$preflight_report_id = isset( $preflight_context['report_id'] ) ? (string) $preflight_context['report_id'] : '';
		}

		$show_source_form = empty( $pending_user_preview )
			&& empty( $pending_theme_preview )
			&& empty( $preflight_report );

		$allowed_tabs = array( 'upload', 'server' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switcher.
		$requested_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'upload';
		$active_tab    = in_array( $requested_tab, $allowed_tabs, true ) ? $requested_tab : 'upload';

		if ( $show_source_form ) {
			$this->renderer->render(
				'admin/nav-tabs.php',
				array(
					'mksddn_mc_tabs'       => array(
						'upload' => __( 'Upload file', 'mksddn-migrate-content' ),
						'server' => __( 'Select from server', 'mksddn-migrate-content' ),
					),
					'mksddn_mc_active_tab' => $active_tab,
					'mksddn_mc_tabs_mode'  => 'link',
					'mksddn_mc_tabs_base'  => admin_url( 'admin.php?page=mksddn-migrate-content-import' ),
					'mksddn_mc_tabs_class' => 'mksddn-mc-nav-tabs--page',
				)
			);
		}

		echo '<div class="mksddn-mc-tab-panels">';

		$this->renderer->render(
			'admin/unified-import-form.php',
			array(
				'mksddn_mc_pending_user_preview'  => $pending_user_preview,
				'mksddn_mc_pending_theme_preview' => $pending_theme_preview,
				'mksddn_mc_preflight_report'      => $preflight_report,
				'mksddn_mc_preflight_report_id'   => $preflight_report_id,
				'mksddn_mc_active_source_tab'     => $active_tab,
			)
		);

		echo '</div>';
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
		$cache_key = 'mksddn_mc_export_items_' . $type;
		$cached = wp_cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		if ( 'page' === $type ) {
			$items = get_pages(
				array(
					'lang' => '', // Get pages from all languages (Polylang compatibility).
				)
			);
		} else {
			$items = get_posts(
				array(
					'post_type'      => $type,
					'posts_per_page' => 100,
					'post_status'    => 'publish',
					'orderby'        => 'title',
					'order'          => 'ASC',
					'lang'           => '', // Get posts from all languages (Polylang compatibility).
				)
			);
		}

		// Cache for 5 minutes.
		wp_cache_set( $cache_key, $items, '', 300 );

		return $items;
	}


}

