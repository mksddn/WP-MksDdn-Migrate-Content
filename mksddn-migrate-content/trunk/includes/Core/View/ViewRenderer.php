<?php
/**
 * @file: ViewRenderer.php
 * @description: Base class for rendering view templates
 * @dependencies: Config\PluginConfig
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\View;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base view renderer for template files.
 *
 * @since 1.0.0
 */
class ViewRenderer {

	/**
	 * Views directory path.
	 *
	 * @var string
	 */
	private string $views_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $views_dir Custom views directory path.
	 * @since 1.0.0
	 */
	public function __construct( ?string $views_dir = null ) {
		$this->views_dir = $views_dir ?? PluginConfig::dir() . 'views/';
	}

	/**
	 * Render a view template.
	 *
	 * @param string $template Template name (relative to views directory).
	 * @param array  $vars     Variables to pass to template.
	 * @return void
	 * @since 1.0.0
	 */
	public function render( string $template, array $vars = array() ): void {
		$template_path = $this->views_dir . $template;

		if ( ! file_exists( $template_path ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Template not found: ', 'mksddn-migrate-content' ) . esc_html( $template ) . '</p></div>';
			return;
		}

		// Extract variables for template.
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		// Include template.
		include $template_path;
	}

	/**
	 * Get rendered template as string.
	 *
	 * @param string $template Template name.
	 * @param array  $vars     Variables to pass to template.
	 * @return string Rendered template.
	 * @since 1.0.0
	 */
	public function render_string( string $template, array $vars = array() ): string {
		ob_start();
		$this->render( $template, $vars );
		return ob_get_clean();
	}

	/**
	 * Check if template exists.
	 *
	 * @param string $template Template name.
	 * @return bool True if exists, false otherwise.
	 * @since 1.0.0
	 */
	public function template_exists( string $template ): bool {
		return file_exists( $this->views_dir . $template );
	}

	/**
	 * Render template using static method (for use in templates).
	 *
	 * @param string $template Template name.
	 * @param array  $vars     Variables to pass to template.
	 * @return void
	 * @since 1.0.0
	 */
	public static function render_template( string $template, array $vars = array() ): void {
		$renderer = new self();
		$renderer->render( $template, $vars );
	}
}

