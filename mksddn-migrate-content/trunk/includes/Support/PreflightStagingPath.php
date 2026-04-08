<?php
/**
 * @file: PreflightStagingPath.php
 * @description: Validates paths under uploads/mksddn-mc/preflight/ (unified import step 2)
 * @dependencies: None
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards preflight-staged files created between unified import step 1 and step 2.
 *
 * @since 2.2.0
 */
final class PreflightStagingPath {

	/**
	 * Whether the path is inside the preflight staging directory under uploads.
	 *
	 * @param string $absolute_path Candidate file path.
	 * @return bool
	 */
	public static function is_allowed_path( string $absolute_path ): bool {
		if ( '' === trim( $absolute_path ) ) {
			return false;
		}

		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$allowed_base = trailingslashit( $uploads['basedir'] ) . 'mksddn-mc/preflight';
		$real_allowed = realpath( $allowed_base );
		$real_file    = realpath( $absolute_path );

		if ( false === $real_allowed || false === $real_file ) {
			return false;
		}

		$prefix = trailingslashit( wp_normalize_path( $real_allowed ) );

		return str_starts_with( wp_normalize_path( $real_file ), $prefix );
	}
}
