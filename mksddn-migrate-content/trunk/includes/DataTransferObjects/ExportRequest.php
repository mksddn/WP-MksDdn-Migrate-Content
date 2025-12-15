<?php
/**
 * @file: ExportRequest.php
 * @description: DTO for export requests
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\DataTransferObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data transfer object for export requests.
 *
 * @since 1.0.0
 */
class ExportRequest {

	/**
	 * Export mode: 'full' or 'selected'.
	 *
	 * @var string
	 */
	public string $mode;

	/**
	 * Selected post IDs grouped by post type.
	 *
	 * @var array<string, int[]>
	 */
	public array $selected_ids;

	/**
	 * Export format: 'archive' or 'json'.
	 *
	 * @var string
	 */
	public string $format;

	/**
	 * Include media files.
	 *
	 * @var bool
	 */
	public bool $include_media;

	/**
	 * Constructor.
	 *
	 * @param string              $mode          Export mode.
	 * @param array<string, int[]> $selected_ids Selected IDs.
	 * @param string              $format        Export format.
	 * @param bool                $include_media Include media.
	 * @since 1.0.0
	 */
	public function __construct(
		string $mode = 'full',
		array $selected_ids = array(),
		string $format = 'archive',
		bool $include_media = true
	) {
		$this->mode          = $mode;
		$this->selected_ids  = $selected_ids;
		$this->format        = $format;
		$this->include_media = $include_media;
	}

	/**
	 * Create from array.
	 *
	 * @param array $data Request data.
	 * @return self
	 * @since 1.0.0
	 */
	public static function from_array( array $data ): self {
		return new self(
			$data['mode'] ?? 'full',
			$data['selected_ids'] ?? array(),
			$data['format'] ?? 'archive',
			$data['include_media'] ?? true
		);
	}

	/**
	 * Check if export is full site.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_full(): bool {
		return 'full' === $this->mode;
	}

	/**
	 * Check if export is selected content.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_selected(): bool {
		return 'selected' === $this->mode;
	}
}

