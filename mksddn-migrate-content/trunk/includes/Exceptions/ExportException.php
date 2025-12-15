<?php
/**
 * @file: ExportException.php
 * @description: Exception for export operation errors
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when export operations fail.
 *
 * @since 1.0.0
 */
class ExportException extends \Exception {

	/**
	 * Export context data.
	 *
	 * @var array<string, mixed>
	 */
	private array $context = array();

	/**
	 * Constructor.
	 *
	 * @param string          $message Error message.
	 * @param array<string, mixed> $context Export context.
	 * @param int             $code   Error code.
	 * @param \Throwable|null $previous Previous exception.
	 * @since 1.0.0
	 */
	public function __construct( string $message = '', array $context = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->context = $context;
	}

	/**
	 * Get export context.
	 *
	 * @return array<string, mixed>
	 * @since 1.0.0
	 */
	public function get_context(): array {
		return $this->context;
	}
}

