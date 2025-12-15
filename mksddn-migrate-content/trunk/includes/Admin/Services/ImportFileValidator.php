<?php
/**
 * @file: ImportFileValidator.php
 * @description: Service for validating uploaded import files
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for validating uploaded import files.
 *
 * @since 1.0.0
 */
class ImportFileValidator {

	/**
	 * Validate uploaded file.
	 *
	 * @param array $file File data from $_FILES.
	 * @return array|WP_Error Validated file data or error.
	 * @since 1.0.0
	 */
	public function validate( array $file ): array|WP_Error {
		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'mksddn_mc_upload_failed', __( 'Failed to upload file.', 'mksddn-migrate-content' ) );
		}

		$tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( (string) $file['tmp_name'] ) : '';
		$name     = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( 0 >= $size ) {
			return new WP_Error( 'mksddn_mc_invalid_size', __( 'Invalid file size.', 'mksddn-migrate-content' ) );
		}

		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$mime      = function_exists( 'mime_content_type' ) && '' !== $tmp_name ? mime_content_type( $tmp_name ) : '';

		$validation = $this->validate_extension_and_mime( $extension, $mime );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return array(
			'path'      => $tmp_name,
			'name'      => $name,
			'size'      => $size,
			'extension' => $extension,
			'mime'      => $mime,
		);
	}

	/**
	 * Validate file extension and MIME type.
	 *
	 * @param string $extension File extension (lowercase).
	 * @param string $mime      Detected MIME type.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function validate_extension_and_mime( string $extension, string $mime ): bool|WP_Error {
		switch ( $extension ) {
			case 'json':
				$json_mimes = array( 'application/json', 'text/plain', 'application/octet-stream' );
				if ( '' !== $mime && ! in_array( $mime, $json_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a JSON export created by this plugin.', 'mksddn-migrate-content' ) );
				}
				return true;

			case 'wpbkp':
				$archive_mimes = array( 'application/octet-stream', 'application/zip', 'application/x-zip-compressed' );
				if ( '' !== $mime && ! in_array( $mime, $archive_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a .wpbkp archive created by this plugin.', 'mksddn-migrate-content' ) );
				}
				return true;
		}

		return new WP_Error( 'mksddn_mc_invalid_type', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
	}
}

