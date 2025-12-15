<?php
/**
 * @file: ValidationException.php
 * @description: Exception for validation errors
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when validation fails.
 *
 * @since 1.0.0
 */
class ValidationException extends \Exception {

	/**
	 * Validation errors.
	 *
	 * @var array<string, string>
	 */
	private array $errors = array();

	/**
	 * Constructor.
	 *
	 * @param string               $message Error message.
	 * @param array<string, string> $errors Validation errors.
	 * @param int                  $code    Error code.
	 * @param \Throwable|null     $previous Previous exception.
	 * @since 1.0.0
	 */
	public function __construct( string $message = '', array $errors = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->errors = $errors;
	}

	/**
	 * Get validation errors.
	 *
	 * @return array<string, string>
	 * @since 1.0.0
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	/**
	 * Check if exception has errors.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function has_errors(): bool {
		return ! empty( $this->errors );
	}
}

