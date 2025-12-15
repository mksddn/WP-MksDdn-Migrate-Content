<?php
/**
 * @file: ValidationResult.php
 * @description: DTO for validation results
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data Transfer Object for validation results.
 *
 * @since 1.0.0
 */
class ValidationResult {

	/**
	 * Whether validation passed.
	 *
	 * @var bool
	 */
	private bool $is_valid;

	/**
	 * Error messages.
	 *
	 * @var string[]
	 */
	private array $errors;

	/**
	 * Constructor.
	 *
	 * @param bool     $is_valid Whether validation passed.
	 * @param string[] $errors   Error messages.
	 * @since 1.0.0
	 */
	public function __construct( bool $is_valid = true, array $errors = array() ) {
		$this->is_valid = $is_valid;
		$this->errors   = $errors;
	}

	/**
	 * Create successful result.
	 *
	 * @return self
	 * @since 1.0.0
	 */
	public static function success(): self {
		return new self( true );
	}

	/**
	 * Create failed result.
	 *
	 * @param string|string[] $errors Error message(s).
	 * @return self
	 * @since 1.0.0
	 */
	public static function failure( string|array $errors ): self {
		$error_array = is_array( $errors ) ? $errors : array( $errors );
		return new self( false, $error_array );
	}

	/**
	 * Check if validation passed.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_valid(): bool {
		return $this->is_valid;
	}

	/**
	 * Get error messages.
	 *
	 * @return string[]
	 * @since 1.0.0
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Get first error message.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_first_error(): string {
		return $this->errors[0] ?? '';
	}
}

