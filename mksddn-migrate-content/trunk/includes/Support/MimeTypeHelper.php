<?php
/**
 * @file: MimeTypeHelper.php
 * @description: Utility for detecting MIME types of files
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Utility for detecting MIME types.
 *
 * @since 1.0.1
 */
class MimeTypeHelper {

	/**
	 * Detect MIME type for file.
	 *
	 * @param string $file_path File path.
	 * @param string $extension File extension.
	 * @return string MIME type.
	 * @since 1.0.1
	 */
	public static function detect( string $file_path, string $extension ): string {
		// Try mime_content_type if available and file exists.
		if ( function_exists( 'mime_content_type' ) && file_exists( $file_path ) ) {
			$mime = @mime_content_type( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fallback available
			if ( $mime && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}

		// Try WordPress wp_check_filetype if available.
		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype = wp_check_filetype( $file_path );
			if ( ! empty( $filetype['type'] ) ) {
				return $filetype['type'];
			}
		}

		// Fallback to extension-based mapping.
		$mime_map = array(
			'wpbkp' => 'application/zip',
			'json'  => 'application/json',
		);

		$extension_lower = strtolower( $extension );
		return $mime_map[ $extension_lower ] ?? 'application/octet-stream';
	}
}

