<?php
/**
 * Import themes
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import themes class
 */
class MksDdn_MC_Import_Themes {

	/**
	 * Execute themes import
	 *
	 * @param array $params Import parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Importing themes...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			return $params;
		}

		$extract_path = $params['extract_path'];
		$themes_source = $extract_path . DIRECTORY_SEPARATOR . 'themes';
		$themes_dest = get_theme_root();

		if ( is_dir( $themes_source ) ) {
			// Read themes list
			$themes_list_file = $extract_path . DIRECTORY_SEPARATOR . MKSDDN_MC_THEMES_LIST_NAME;
			$themes_list = array();

			if ( file_exists( $themes_list_file ) ) {
				$themes_data = MksDdn_MC_File::read( $themes_list_file );
				$themes_list = json_decode( $themes_data, true );
			}

			// Copy themes
			$themes = glob( $themes_source . DIRECTORY_SEPARATOR . '*' );
			foreach ( $themes as $theme_dir ) {
				if ( is_dir( $theme_dir ) ) {
					$theme_slug = basename( $theme_dir );
					$dest_dir = $themes_dest . DIRECTORY_SEPARATOR . $theme_slug;

					// Skip if theme already exists
					if ( is_dir( $dest_dir ) ) {
						continue;
					}

					MksDdn_MC_Directory::copy( $theme_dir, $dest_dir );
				}
			}
		}

		MksDdn_MC_Status::info( __( 'Themes imported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

