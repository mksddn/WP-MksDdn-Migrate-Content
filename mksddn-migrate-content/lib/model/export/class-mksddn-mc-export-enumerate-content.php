<?php
/**
 * Export enumerate content
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export enumerate content class
 */
class MksDdn_MC_Export_Enumerate_Content {

	/**
	 * Execute content enumeration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Enumerating content files...', 'mksddn-migrate-content' ) );

		$content_files = array();

		// Get wp-content directory
		$content_dir = WP_CONTENT_DIR;

		// Exclude directories
		$excluded_dirs = array(
			'uploads',
			'plugins',
			'themes',
			'upgrade',
			'backup',
			'backups',
			'cache',
			'w3tc-config',
		);

		// Get all files in wp-content
		$iterator = new MksDdn_MC_Recursive_Directory_Iterator( $content_dir );
		$filter = new MksDdn_MC_Recursive_Exclude_Filter( $iterator, $excluded_dirs );

		foreach ( new RecursiveIteratorIterator( $filter ) as $file ) {
			if ( $file->isFile() ) {
				$relative_path = str_replace( $content_dir . DIRECTORY_SEPARATOR, '', $file->getPathname() );
				$content_files[] = $relative_path;
			}
		}

		$params['content_files'] = $content_files;
		$params['total_content_files_count'] = count( $content_files );

		return $params;
	}
}

