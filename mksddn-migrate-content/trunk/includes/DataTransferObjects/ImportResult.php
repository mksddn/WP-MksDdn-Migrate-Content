<?php
/**
 * @file: ImportResult.php
 * @description: DTO for import results
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\DataTransferObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data transfer object for import results.
 *
 * @since 1.0.0
 */
class ImportResult {

	/**
	 * Success flag.
	 *
	 * @var bool
	 */
	public bool $success;

	/**
	 * Number of imported posts.
	 *
	 * @var int
	 */
	public int $posts_imported;

	/**
	 * Number of imported media files.
	 *
	 * @var int
	 */
	public int $media_imported;

	/**
	 * Snapshot ID if created.
	 *
	 * @var string
	 */
	public string $snapshot_id;

	/**
	 * Import metadata.
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
	 * @param bool   $success        Success flag.
	 * @param int    $posts_imported Posts imported.
	 * @param int    $media_imported Media imported.
	 * @param string $snapshot_id    Snapshot ID.
	 * @param array  $metadata       Metadata.
	 * @param string $error          Error message.
	 * @since 1.0.0
	 */
	public function __construct(
		bool $success = false,
		int $posts_imported = 0,
		int $media_imported = 0,
		string $snapshot_id = '',
		array $metadata = array(),
		string $error = ''
	) {
		$this->success        = $success;
		$this->posts_imported = $posts_imported;
		$this->media_imported = $media_imported;
		$this->snapshot_id    = $snapshot_id;
		$this->metadata        = $metadata;
		$this->error           = $error;
	}

	/**
	 * Create success result.
	 *
	 * @param int    $posts_imported Posts imported.
	 * @param int    $media_imported Media imported.
	 * @param string $snapshot_id    Snapshot ID.
	 * @param array  $metadata       Metadata.
	 * @return self
	 * @since 1.0.0
	 */
	public static function success( int $posts_imported = 0, int $media_imported = 0, string $snapshot_id = '', array $metadata = array() ): self {
		return new self( true, $posts_imported, $media_imported, $snapshot_id, $metadata );
	}

	/**
	 * Create error result.
	 *
	 * @param string $error Error message.
	 * @return self
	 * @since 1.0.0
	 */
	public static function error( string $error ): self {
		return new self( false, 0, 0, '', array(), $error );
	}
}

