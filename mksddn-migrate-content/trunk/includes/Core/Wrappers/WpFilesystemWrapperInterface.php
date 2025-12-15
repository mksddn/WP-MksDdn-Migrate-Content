<?php
/**
 * @file: WpFilesystemWrapperInterface.php
 * @description: Interface for WordPress filesystem wrapper
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\Wrappers;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for WordPress filesystem wrapper.
 *
 * @since 1.0.0
 */
interface WpFilesystemWrapperInterface {

	/**
	 * Get upload directory info.
	 *
	 * @return array|WP_Error Upload directory array or error.
	 * @since 1.0.0
	 */
	public function upload_dir(): array|WP_Error;

	/**
	 * Handle file upload.
	 *
	 * @param array       $file      File data.
	 * @param array       $overrides Optional overrides.
	 * @param string|null $time      Optional time string.
	 * @return array|WP_Error File data or error.
	 * @since 1.0.0
	 */
	public function handle_upload( array $file, array $overrides = array(), ?string $time = null ): array|WP_Error;
}

