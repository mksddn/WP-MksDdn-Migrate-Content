<?php
/**
 * Import validate
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import validate class
 */
class MksDdn_MC_Import_Validate {

	/**
	 * Execute validation
	 *
	 * @param array $params Import parameters
	 * @return array
	 * @throws Exception
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Validating archive file...', 'mksddn-migrate-content' ) );

		if ( empty( $params['archive_path'] ) ) {
			throw new Exception( __( 'Archive file path not specified.', 'mksddn-migrate-content' ) );
		}

		$archive_path = $params['archive_path'];

		// Check if file exists
		if ( ! file_exists( $archive_path ) ) {
			throw new Exception( __( 'Archive file does not exist.', 'mksddn-migrate-content' ) );
		}

		// Check file size
		$file_size = filesize( $archive_path );
		if ( $file_size === false || $file_size === 0 ) {
			throw new Exception( __( 'Archive file is empty or invalid.', 'mksddn-migrate-content' ) );
		}

		// Check file permissions
		if ( ! is_readable( $archive_path ) ) {
			throw new Exception( __( 'Archive file is not readable.', 'mksddn-migrate-content' ) );
		}

		// Validate archive format (basic check)
		$file_handle = @fopen( $archive_path, 'rb' );
		if ( ! $file_handle ) {
			throw new Exception( __( 'Cannot open archive file for reading.', 'mksddn-migrate-content' ) );
		}

		// Read first bytes to check format
		$header = @fread( $file_handle, 100 );
		@fclose( $file_handle );

		if ( empty( $header ) ) {
			throw new Exception( __( 'Archive file appears to be corrupted.', 'mksddn-migrate-content' ) );
		}

		// Check archive structure
		self::validate_archive_structure( $archive_path );

		MksDdn_MC_Status::info( __( 'Archive file validated successfully.', 'mksddn-migrate-content' ) );

		return $params;
	}

	/**
	 * Validate archive structure
	 *
	 * @param string $archive_path Archive path
	 * @return void
	 * @throws Exception
	 */
	private static function validate_archive_structure( $archive_path ) {
		// Try to open archive and check if it can be read
		$extractor = new MksDdn_MC_Extractor( $archive_path, false );
		$files = $extractor->list_files();

		if ( empty( $files ) ) {
			$extractor->close();
			throw new Exception( __( 'Archive appears to be empty or invalid.', 'mksddn-migrate-content' ) );
		}

		// Check for required files
		$has_database = false;
		$has_package = false;

		foreach ( $files as $file_info ) {
			if ( strpos( $file_info['path'], MKSDDN_MC_DATABASE_NAME ) !== false ) {
				$has_database = true;
			}
			if ( strpos( $file_info['path'], MKSDDN_MC_PACKAGE_NAME ) !== false ) {
				$has_package = true;
			}
		}

		$extractor->close();

		if ( ! $has_package ) {
			throw new Exception( __( 'Archive is missing package.json file.', 'mksddn-migrate-content' ) );
		}
	}
}

