<?php
/**
 * @file: FileOperationException.php
 * @description: Exception for file operation errors
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when file operations fail.
 *
 * @since 1.0.0
 */
class FileOperationException extends \Exception {

	/**
	 * File path that caused the error.
	 *
	 * @var string
	 */
	private string $file_path = '';

	/**
	 * Constructor.
	 *
	 * @param string          $message  Error message.
	 * @param string          $file_path File path.
	 * @param int             $code     Error code.
	 * @param \Throwable|null $previous Previous exception.
	 * @since 1.0.0
	 */
	public function __construct( string $message = '', string $file_path = '', int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->file_path = $file_path;
	}

	/**
	 * Get file path.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_file_path(): string {
		return $this->file_path;
	}
}

