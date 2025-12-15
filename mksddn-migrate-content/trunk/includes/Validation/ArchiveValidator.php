<?php
/**
 * @file: ArchiveValidator.php
 * @description: Validator for archive structure and integrity
 * @dependencies: ValidationResult
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Validation;

use MksDdn\MigrateContent\Contracts\ValidatorInterface;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator for archive structure and integrity.
 *
 * @since 1.0.0
 */
class ArchiveValidator implements ValidatorInterface {

	/**
	 * Validate archive.
	 *
	 * @param mixed  $data Archive path or extracted data.
	 * @param string $type Validation type (not used).
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	public function validate( mixed $data, string $type ): bool {
		$result = $this->validate_archive( $data );
		return $result->is_valid();
	}

	/**
	 * Validate archive structure with detailed result.
	 *
	 * @param string|array $archive Archive path or extracted data array.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	public function validate_archive( string|array $archive ): ValidationResult {
		$errors = array();

		if ( is_string( $archive ) ) {
			if ( ! file_exists( $archive ) ) {
				$errors[] = __( 'Archive file not found.', 'mksddn-migrate-content' );
				return ValidationResult::failure( $errors );
			}

			if ( ! is_readable( $archive ) ) {
				$errors[] = __( 'Archive file is not readable.', 'mksddn-migrate-content' );
				return ValidationResult::failure( $errors );
			}
		}

		if ( is_array( $archive ) ) {
			if ( empty( $archive['payload'] ) ) {
				$errors[] = __( 'Archive payload is missing.', 'mksddn-migrate-content' );
				return ValidationResult::failure( $errors );
			}

			if ( ! isset( $archive['type'] ) || empty( $archive['type'] ) ) {
				$errors[] = __( 'Archive type is missing.', 'mksddn-migrate-content' );
				return ValidationResult::failure( $errors );
			}
		}

		return ValidationResult::success();
	}

	/**
	 * Validate manifest.json structure.
	 *
	 * @param array $manifest Manifest data.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	public function validate_manifest( array $manifest ): ValidationResult {
		$errors = array();

		if ( empty( $manifest ) ) {
			$errors[] = __( 'Manifest is empty.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( ! isset( $manifest['version'] ) ) {
			$errors[] = __( 'Manifest version is missing.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( ! isset( $manifest['type'] ) ) {
			$errors[] = __( 'Manifest type is missing.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		return ValidationResult::success();
	}
}

