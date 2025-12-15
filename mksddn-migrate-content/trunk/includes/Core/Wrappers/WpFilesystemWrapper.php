<?php
/**
 * @file: WpFilesystemWrapper.php
 * @description: Wrapper for WordPress filesystem functions
 * @dependencies: WpFilesystemWrapperInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\Wrappers;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for WordPress filesystem functions.
 *
 * @since 1.0.0
 */
class WpFilesystemWrapper implements WpFilesystemWrapperInterface {

	/**
	 * Get upload directory info.
	 *
	 * @return array|WP_Error Upload directory array or error.
	 * @since 1.0.0
	 */
	public function upload_dir(): array|WP_Error {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
		}

		return $upload_dir;
	}

	/**
	 * Handle file upload.
	 *
	 * @param array       $file      File data.
	 * @param array       $overrides Optional overrides.
	 * @param string|null $time      Optional time string.
	 * @return array|WP_Error File data or error.
	 * @since 1.0.0
	 */
	public function handle_upload( array $file, array $overrides = array(), ?string $time = null ): array|WP_Error {
		return wp_handle_upload( $file, $overrides, $time );
	}
}

