<?php
/**
 * @file: ExportResult.php
 * @description: DTO for export results
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\DataTransferObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data transfer object for export results.
 *
 * @since 1.0.0
 */
class ExportResult {

	/**
	 * Success flag.
	 *
	 * @var bool
	 */
	public bool $success;

	/**
	 * Export file path.
	 *
	 * @var string
	 */
	public string $file_path;

	/**
	 * File size in bytes.
	 *
	 * @var int
	 */
	public int $file_size;

	/**
	 * Export metadata.
	 *
	 * @var array<string, mixed>
	 */
	public array $metadata;

	/**
	 * Error message.
	 *
	 * @var string
	 */
	public string $error;

	/**
	 * Constructor.
	 *
	 * @param bool   $success   Success flag.
	 * @param string $file_path File path.
	 * @param int    $file_size File size.
	 * @param array  $metadata  Metadata.
	 * @param string $error     Error message.
	 * @since 1.0.0
	 */
	public function __construct(
		bool $success = false,
		string $file_path = '',
		int $file_size = 0,
		array $metadata = array(),
		string $error = ''
	) {
		$this->success   = $success;
		$this->file_path  = $file_path;
		$this->file_size  = $file_size;
		$this->metadata   = $metadata;
		$this->error      = $error;
	}

	/**
	 * Create success result.
	 *
	 * @param string $file_path File path.
	 * @param int    $file_size File size.
	 * @param array  $metadata  Metadata.
	 * @return self
	 * @since 1.0.0
	 */
	public static function success( string $file_path, int $file_size = 0, array $metadata = array() ): self {
		return new self( true, $file_path, $file_size, $metadata );
	}

	/**
	 * Create error result.
	 *
	 * @param string $error Error message.
	 * @return self
	 * @since 1.0.0
	 */
	public static function error( string $error ): self {
		return new self( false, '', 0, array(), $error );
	}
}

