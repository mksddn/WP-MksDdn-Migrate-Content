<?php
/**
 * @file: FileValidator.php
 * @description: Validator for uploaded files
 * @dependencies: ValidationResult, PluginConfig
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Validation;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Contracts\ValidatorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator for uploaded files.
 *
 * @since 1.0.0
 */
class FileValidator implements ValidatorInterface {

	/**
	 * Validate uploaded file.
	 *
	 * @param mixed  $data File data (array with keys: tmp_name, name, size, error).
	 * @param string $type File type: wpbkp|json.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	public function validate( mixed $data, string $type ): bool {
		$result = $this->validate_file( $data, $type );
		return $result->is_valid();
	}

	/**
	 * Validate uploaded file with detailed result.
	 *
	 * @param array  $file File data array.
	 * @param string $type Expected file type: wpbkp|json.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	public function validate_file( array $file, string $type ): ValidationResult {
		$errors = array();

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$errors[] = __( 'Failed to upload file.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$errors[] = __( 'Uploaded file could not be verified.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 ) {
			$errors[] = __( 'Invalid file size.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		$max_size = PluginConfig::max_upload_size();
		if ( $size > $max_size ) {
			$errors[] = sprintf(
				/* translators: %s: maximum file size */
				__( 'File size exceeds maximum allowed size of %s.', 'mksddn-migrate-content' ),
				size_format( $max_size )
			);
			return ValidationResult::failure( $errors );
		}

		$filename = isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : '';
		if ( empty( $filename ) ) {
			$errors[] = __( 'Invalid filename.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! $this->is_valid_extension( $extension, $type ) ) {
			$errors[] = sprintf(
				/* translators: %s: expected file extension */
				__( 'Invalid file extension. Expected: %s', 'mksddn-migrate-content' ),
				$type
			);
			return ValidationResult::failure( $errors );
		}

		$mime = $this->detect_mime_type( $file['tmp_name'] );
		if ( ! $this->is_valid_mime_type( $mime, $type ) ) {
			$errors[] = __( 'Invalid file type detected.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		return ValidationResult::success();
	}

	/**
	 * Check if extension is valid for type.
	 *
	 * @param string $extension File extension.
	 * @param string $type      Expected type.
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_extension( string $extension, string $type ): bool {
		$allowed = array(
			'wpbkp' => array( 'wpbkp' ),
			'json'   => array( 'json' ),
		);

		if ( ! isset( $allowed[ $type ] ) ) {
			return false;
		}

		return in_array( $extension, $allowed[ $type ], true );
	}

	/**
	 * Check if MIME type is valid for file type.
	 *
	 * @param string $mime Detected MIME type.
	 * @param string $type Expected file type.
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_valid_mime_type( string $mime, string $type ): bool {
		$allowed_mimes = array(
			'wpbkp' => array( 'application/octet-stream', 'application/zip', 'application/x-zip-compressed' ),
			'json'   => array( 'application/json', 'text/plain', 'application/octet-stream' ),
		);

		if ( ! isset( $allowed_mimes[ $type ] ) ) {
			return false;
		}

		if ( empty( $mime ) ) {
			return true; // Allow if MIME detection failed.
		}

		return in_array( $mime, $allowed_mimes[ $type ], true );
	}

	/**
	 * Detect MIME type of file.
	 *
	 * @param string $file_path File path.
	 * @return string MIME type or empty string.
	 * @since 1.0.0
	 */
	private function detect_mime_type( string $file_path ): string {
		if ( function_exists( 'mime_content_type' ) && file_exists( $file_path ) ) {
			$mime = mime_content_type( $file_path );
			if ( $mime ) {
				return $mime;
			}
		}

		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype = wp_check_filetype( $file_path );
			if ( isset( $filetype['type'] ) ) {
				return $filetype['type'];
			}
		}

		return '';
	}
}

