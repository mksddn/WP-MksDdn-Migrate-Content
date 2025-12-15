<?php
/**
 * @file: ArchiveHandlerInterface.php
 * @description: Contract for archive operations (create/extract)
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for archive operations.
 *
 * @since 1.0.0
 */
interface ArchiveHandlerInterface {

	/**
	 * Create archive from data.
	 *
	 * @param array $payload Payload data.
	 * @param array $meta   Manifest metadata.
	 * @param array $assets Additional files to embed.
	 * @return string|WP_Error Archive file path or error.
	 * @since 1.0.0
	 */
	public function create_archive( array $payload, array $meta, array $assets = array() ): string|WP_Error;

	/**
	 * Extract archive contents.
	 *
	 * @param string $file_path Archive file path.
	 * @return array|WP_Error Extracted data or error.
	 * @since 1.0.0
	 */
	public function extract( string $file_path ): array|WP_Error;
}

