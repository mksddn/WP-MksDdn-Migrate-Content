<?php
/**
 * @file: FileUpload.php
 * @description: DTO for file upload information
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\DataTransferObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data transfer object for file upload information.
 *
 * @since 1.0.0
 */
class FileUpload {

	/**
	 * Uploaded file path.
	 *
	 * @var string
	 */
	public string $file_path;

	/**
	 * Original file name.
	 *
	 * @var string
	 */
	public string $original_name;

	/**
	 * File size in bytes.
	 *
	 * @var int
	 */
	public int $file_size;

	/**
	 * MIME type.
	 *
	 * @var string
	 */
	public string $mime_type;

	/**
	 * File extension.
	 *
	 * @var string
	 */
	public string $extension;

	/**
	 * Constructor.
	 *
	 * @param string $file_path    File path.
	 * @param string $original_name Original name.
	 * @param int    $file_size    File size.
	 * @param string $mime_type    MIME type.
	 * @param string $extension    Extension.
	 * @since 1.0.0
	 */
	public function __construct(
		string $file_path,
		string $original_name = '',
		int $file_size = 0,
		string $mime_type = '',
		string $extension = ''
	) {
		$this->file_path    = $file_path;
		$this->original_name = $original_name;
		$this->file_size    = $file_size;
		$this->mime_type    = $mime_type;
		$this->extension    = $extension;
	}

	/**
	 * Create from WordPress upload array.
	 *
	 * @param array<string, mixed> $upload Upload array from wp_handle_upload.
	 * @return self
	 * @since 1.0.0
	 */
	public static function from_wp_upload( array $upload ): self {
		$file_path = $upload['file'] ?? '';
		$extension = pathinfo( $file_path, PATHINFO_EXTENSION );

		return new self(
			$file_path,
			$upload['original_name'] ?? basename( $file_path ),
			(int) ( $upload['size'] ?? filesize( $file_path ) ),
			$upload['type'] ?? '',
			$extension
		);
	}

	/**
	 * Check if file is archive.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_archive(): bool {
		return 'wpbkp' === $this->extension;
	}

	/**
	 * Check if file is JSON.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_json(): bool {
		return 'json' === $this->extension;
	}
}

