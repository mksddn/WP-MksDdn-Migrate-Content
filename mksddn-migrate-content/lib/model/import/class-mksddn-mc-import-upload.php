<?php
/**
 * Import upload
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import upload class
 */
class MksDdn_MC_Import_Upload {

	/**
	 * Execute file upload
	 *
	 * @param array $params Import parameters
	 * @return array
	 * @throws Exception
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Uploading archive file...', 'mksddn-migrate-content' ) );

		// Check if file was uploaded
		if ( ! isset( $_FILES['upload_file']['tmp_name'] ) ) {
			throw new Exception( __( 'No file uploaded.', 'mksddn-migrate-content' ) );
		}

		$upload_tmp_name = $_FILES['upload_file']['tmp_name'];
		$upload_error = isset( $_FILES['upload_file']['error'] ) ? $_FILES['upload_file']['error'] : UPLOAD_ERR_OK;

		// Check upload error
		if ( $upload_error !== UPLOAD_ERR_OK ) {
			throw new Exception( self::get_upload_error_message( $upload_error ) );
		}

		// Validate file
		if ( ! is_uploaded_file( $upload_tmp_name ) ) {
			throw new Exception( __( 'Invalid upload file.', 'mksddn-migrate-content' ) );
		}

		// Get original filename
		$original_filename = isset( $_FILES['upload_file']['name'] ) ? sanitize_file_name( $_FILES['upload_file']['name'] ) : 'archive.mksddn';

		// Validate file extension
		if ( ! mksddn_mc_is_filename_supported( $original_filename ) ) {
			throw new Exception( __( 'Invalid file type. Only .mksddn files are allowed.', 'mksddn-migrate-content' ) );
		}

		// Set storage path
		if ( empty( $params['storage'] ) ) {
			$params['storage'] = 'import-' . time();
		}

		$storage_path = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . $params['storage'];
		MksDdn_MC_Directory::create( $storage_path );

		// Set archive path
		$archive_path = $storage_path . DIRECTORY_SEPARATOR . $original_filename;
		$params['archive'] = $original_filename;
		$params['archive_path'] = $archive_path;

		// Move uploaded file
		if ( ! move_uploaded_file( $upload_tmp_name, $archive_path ) ) {
			throw new Exception( __( 'Failed to move uploaded file.', 'mksddn-migrate-content' ) );
		}

		MksDdn_MC_Status::info( __( 'Archive file uploaded successfully.', 'mksddn-migrate-content' ) );

		return $params;
	}

	/**
	 * Get upload error message
	 *
	 * @param int $error_code Error code
	 * @return string
	 */
	private static function get_upload_error_message( $error_code ) {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file exceeds the maximum file size.', 'mksddn-migrate-content' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The uploaded file was only partially uploaded.', 'mksddn-migrate-content' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file was uploaded.', 'mksddn-migrate-content' );
			case UPLOAD_ERR_NO_TMP_DIR:
				return __( 'Missing a temporary folder.', 'mksddn-migrate-content' );
			case UPLOAD_ERR_CANT_WRITE:
				return __( 'Failed to write file to disk.', 'mksddn-migrate-content' );
			case UPLOAD_ERR_EXTENSION:
				return __( 'A PHP extension stopped the file upload.', 'mksddn-migrate-content' );
			default:
				return __( 'Unknown upload error.', 'mksddn-migrate-content' );
		}
	}
}

