<?php
/**
 * @file: ImportDataValidator.php
 * @description: Validator for import data structures
 * @dependencies: ValidationResult
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Validation;

use MksDdn\MigrateContent\Contracts\ValidatorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator for import data structures.
 *
 * @since 1.0.0
 */
class ImportDataValidator implements ValidatorInterface {

	/**
	 * Validate import data.
	 *
	 * @param mixed  $data Import data array.
	 * @param string $type Data type: bundle|page|post|etc.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	public function validate( mixed $data, string $type ): bool {
		$result = $this->validate_import_data( $data, $type );
		return $result->is_valid();
	}

	/**
	 * Validate import data with detailed result.
	 *
	 * @param array  $data Import data array.
	 * @param string $type Expected data type.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	public function validate_import_data( array $data, string $type ): ValidationResult {
		$errors = array();

		if ( empty( $data ) ) {
			$errors[] = __( 'Import data is empty.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( 'bundle' === $type ) {
			return $this->validate_bundle( $data );
		}

		return $this->validate_single_item( $data );
	}

	/**
	 * Validate bundle structure.
	 *
	 * @param array $data Bundle data.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	private function validate_bundle( array $data ): ValidationResult {
		$errors = array();

		if ( ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			$errors[] = __( 'Bundle items are missing or invalid.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( empty( $data['items'] ) ) {
			$errors[] = __( 'Bundle is empty.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		return ValidationResult::success();
	}

	/**
	 * Validate single item structure.
	 *
	 * @param array $data Item data.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	private function validate_single_item( array $data ): ValidationResult {
		$errors = array();

		if ( ! isset( $data['post_title'] ) && ! isset( $data['post_name'] ) ) {
			$errors[] = __( 'Item must have title or slug.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		if ( ! isset( $data['post_type'] ) ) {
			$errors[] = __( 'Post type is missing.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		return ValidationResult::success();
	}
}

