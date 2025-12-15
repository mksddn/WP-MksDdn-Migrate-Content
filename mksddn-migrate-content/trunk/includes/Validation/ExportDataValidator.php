<?php
/**
 * @file: ExportDataValidator.php
 * @description: Validator for export requests and data
 * @dependencies: ValidationResult
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Validation;

use MksDdn\MigrateContent\Contracts\ValidatorInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validator for export requests and data.
 *
 * @since 1.0.0
 */
class ExportDataValidator implements ValidatorInterface {

	/**
	 * Validate export data.
	 *
	 * @param mixed  $data Export request data.
	 * @param string $type Export type: selected|full.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	public function validate( mixed $data, string $type ): bool {
		$result = $this->validate_export_request( $data, $type );
		return $result->is_valid();
	}

	/**
	 * Validate export request with detailed result.
	 *
	 * @param array  $data Export request data.
	 * @param string $type Export type.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	public function validate_export_request( array $data, string $type ): ValidationResult {
		$errors = array();

		if ( 'selected' === $type ) {
			return $this->validate_selected_export( $data );
		}

		if ( 'full' === $type ) {
			return $this->validate_full_export( $data );
		}

		$errors[] = sprintf(
			/* translators: %s: export type */
			__( 'Unknown export type: %s', 'mksddn-migrate-content' ),
			$type
		);

		return ValidationResult::failure( $errors );
	}

	/**
	 * Validate selected content export request.
	 *
	 * @param array $data Request data.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	private function validate_selected_export( array $data ): ValidationResult {
		$errors = array();

		$has_selection = false;
		foreach ( $data as $key => $value ) {
			if ( strpos( $key, 'selected_' ) === 0 && is_array( $value ) && ! empty( $value ) ) {
				$has_selection = true;
				break;
			}
		}

		if ( ! $has_selection ) {
			$errors[] = __( 'No content selected for export.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		$format = $data['export_format'] ?? 'archive';
		if ( ! in_array( $format, array( 'archive', 'json' ), true ) ) {
			$errors[] = __( 'Invalid export format.', 'mksddn-migrate-content' );
			return ValidationResult::failure( $errors );
		}

		return ValidationResult::success();
	}

	/**
	 * Validate full site export request.
	 *
	 * @param array $data Request data.
	 * @return ValidationResult Validation result.
	 * @since 1.0.0
	 */
	private function validate_full_export( array $data ): ValidationResult {
		// Full export doesn't require specific data validation.
		// Permission check is handled at controller level.
		return ValidationResult::success();
	}
}

