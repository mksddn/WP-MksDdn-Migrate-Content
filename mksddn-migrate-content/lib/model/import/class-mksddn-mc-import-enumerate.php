<?php
/**
 * Import enumerate
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import enumerate class
 */
class MksDdn_MC_Import_Enumerate {

	/**
	 * Execute enumeration
	 *
	 * @param array $params Import parameters
	 * @return array
	 * @throws Exception
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Enumerating archive contents...', 'mksddn-migrate-content' ) );

		if ( empty( $params['archive_path'] ) ) {
			throw new Exception( __( 'Archive file path not specified.', 'mksddn-migrate-content' ) );
		}

		$archive_path = $params['archive_path'];
		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		$extract_path = $storage_path . DIRECTORY_SEPARATOR . 'extracted';

		// Create extract directory
		MksDdn_MC_Directory::create( $extract_path );

		// Extract archive
		$extractor = new MksDdn_MC_Extractor( $archive_path, false );
		$files = $extractor->list_files();

		// Extract all files
		foreach ( $files as $file_info ) {
			$dest_path = $extract_path . DIRECTORY_SEPARATOR . $file_info['path'];
			$extractor->set_file_pointer( $file_info['offset'] );
			$extractor->extract_file( $dest_path );
		}

		$extractor->close();

		// Read package.json if exists
		$package_file = $extract_path . DIRECTORY_SEPARATOR . MKSDDN_MC_PACKAGE_NAME;
		if ( file_exists( $package_file ) ) {
			$package_content = MksDdn_MC_File::read( $package_file );
			$params['package'] = json_decode( $package_content, true );
		}

		$params['extract_path'] = $extract_path;
		$params['files'] = $files;

		MksDdn_MC_Status::info( __( 'Archive contents enumerated.', 'mksddn-migrate-content' ) );

		return $params;
	}
}

