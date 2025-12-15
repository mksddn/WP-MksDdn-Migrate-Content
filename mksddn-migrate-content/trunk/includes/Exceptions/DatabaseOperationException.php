<?php
/**
 * @file: DatabaseOperationException.php
 * @description: Exception for database operation errors
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception thrown when database operations fail.
 *
 * @since 1.0.0
 */
class DatabaseOperationException extends \Exception {

	/**
	 * SQL query that caused the error.
	 *
	 * @var string
	 */
	private string $query = '';

	/**
	 * Constructor.
	 *
	 * @param string          $message Error message.
	 * @param string          $query  SQL query.
	 * @param int             $code   Error code.
	 * @param \Throwable|null $previous Previous exception.
	 * @since 1.0.0
	 */
	public function __construct( string $message = '', string $query = '', int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->query = $query;
	}

	/**
	 * Get SQL query.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	public function get_query(): string {
		return $this->query;
	}
}

