<?php
/**
 * @file: ImportRequest.php
 * @description: DTO for import requests
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\DataTransferObjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data transfer object for import requests.
 *
 * @since 1.0.0
 */
class ImportRequest {

	/**
	 * Import file path.
	 *
	 * @var string
	 */
	public string $file_path;

	/**
	 * Import mode: 'full' or 'selected'.
	 *
	 * @var string
	 */
	public string $mode;

	/**
	 * User merge plan.
	 *
	 * @var array<string, array{import: bool, mode: string}>
	 */
	public array $user_plan;

	/**
	 * Replace URLs flag.
	 *
	 * @var bool
	 */
	public bool $replace_urls;

	/**
	 * Constructor.
	 *
	 * @param string $file_path   File path.
	 * @param string $mode        Import mode.
	 * @param array  $user_plan   User merge plan.
	 * @param bool   $replace_urls Replace URLs.
	 * @since 1.0.0
	 */
	public function __construct(
		string $file_path,
		string $mode = 'full',
		array $user_plan = array(),
		bool $replace_urls = true
	) {
		$this->file_path    = $file_path;
		$this->mode         = $mode;
		$this->user_plan    = $user_plan;
		$this->replace_urls = $replace_urls;
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
			$data['file_path'] ?? '',
			$data['mode'] ?? 'full',
			$data['user_plan'] ?? array(),
			$data['replace_urls'] ?? true
		);
	}

	/**
	 * Check if import is full site.
	 *
	 * @return bool
	 * @since 1.0.0
	 */
	public function is_full(): bool {
		return 'full' === $this->mode;
	}
}

