<?php
/**
 * Template handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Template class for rendering views
 */
class MksDdn_MC_Template {

	/**
	 * Render a template file
	 *
	 * @param string $view View file name
	 * @param array $args Variables to pass to template
	 * @param string|bool $path Custom path to templates
	 * @return void
	 */
	public static function render( $view, $args = array(), $path = false ) {
		if ( false === $path ) {
			$path = MKSDDN_MC_TEMPLATES_PATH;
		}

		$template_file = $path . DIRECTORY_SEPARATOR . $view . '.php';

		if ( ! file_exists( $template_file ) ) {
			wp_die( sprintf( __( 'Template file not found: %s', 'mksddn-migrate-content' ), $template_file ) );
		}

		// Extract variables for template
		extract( $args, EXTR_SKIP );

		// Include template
		include $template_file;
	}

	/**
	 * Get template content as string
	 *
	 * @param string $view View file name
	 * @param array $args Variables to pass to template
	 * @param string|bool $path Custom path to templates
	 * @return string
	 */
	public static function get_content( $view, $args = array(), $path = false ) {
		ob_start();
		self::render( $view, $args, $path );
		return ob_get_clean();
	}

	/**
	 * Get asset URL
	 *
	 * @param string $asset Asset file path
	 * @return string
	 */
	public static function asset_link( $asset ) {
		return MKSDDN_MC_URL . '/lib/view/assets/' . $asset . '?v=' . MKSDDN_MC_VERSION;
	}
}

