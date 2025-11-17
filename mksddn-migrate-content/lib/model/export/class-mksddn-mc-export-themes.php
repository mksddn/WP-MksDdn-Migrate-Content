<?php
/**
 * Export themes
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export themes class
 */
class MksDdn_MC_Export_Themes {

	/**
	 * Execute themes export
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];

		MksDdn_MC_Status::info( __( 'Exporting themes...', 'mksddn-migrate-content' ) );

		// Enumerate themes if not done
		if ( empty( $params['themes'] ) ) {
			$params = MksDdn_MC_Export_Enumerate_Themes::execute( $params );
		}

		$themes = $params['themes'];
		$themes_dir = get_theme_root();
		$themes_list_file = $storage_path . DIRECTORY_SEPARATOR . MKSDDN_MC_THEMES_LIST_NAME;

		// Save themes list
		$themes_data = wp_json_encode( $themes, JSON_PRETTY_PRINT );
		MksDdn_MC_File::write( $themes_list_file, $themes_data );

		// Copy themes to storage
		$themes_storage = $storage_path . DIRECTORY_SEPARATOR . 'themes';
		MksDdn_MC_Directory::create( $themes_storage );

		$processed = 0;
		$total = count( $themes );

		foreach ( $themes as $theme ) {
			$source_dir = $themes_dir . DIRECTORY_SEPARATOR . $theme['slug'];
			$dest_dir = $themes_storage . DIRECTORY_SEPARATOR . $theme['slug'];

			if ( is_dir( $source_dir ) ) {
				MksDdn_MC_Directory::copy( $source_dir, $dest_dir );
			}

			$processed++;
			if ( $processed % 5 === 0 ) {
				$progress = (int) ( ( $processed / $total ) * 100 );
				MksDdn_MC_Status::progress( $progress );
			}
		}

		MksDdn_MC_Status::info( __( 'Themes exported.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

