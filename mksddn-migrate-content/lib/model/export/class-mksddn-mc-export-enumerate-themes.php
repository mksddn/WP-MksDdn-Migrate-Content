<?php
/**
 * Export enumerate themes
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export enumerate themes class
 */
class MksDdn_MC_Export_Enumerate_Themes {

	/**
	 * Execute themes enumeration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Enumerating themes...', 'mksddn-migrate-content' ) );

		$themes = array();

		if ( ! function_exists( 'wp_get_themes' ) ) {
			require_once ABSPATH . 'wp-includes/theme.php';
		}

		$all_themes = wp_get_themes();
		$active_theme = get_stylesheet();

		foreach ( $all_themes as $theme_slug => $theme ) {
			$themes[] = array(
				'slug'   => $theme_slug,
				'name'   => $theme->get( 'Name' ),
				'active' => ( $theme_slug === $active_theme ),
			);
		}

		$params['themes'] = $themes;
		$params['total_themes_count'] = count( $themes );

		return $params;
	}
}

