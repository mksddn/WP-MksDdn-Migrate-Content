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
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file_path );
			if ( $mime ) {
				return $mime;
			}
		}

		$mime_map = array(
			'wpbkp' => 'application/zip',
			'json'  => 'application/json',
		);

		return $mime_map[ $extension ] ?? 'application/octet-stream';
	}
}

