<?php
/**
 * @file: PreflightStagingPath.php
 * @description: Validates staged import file paths under plugin uploads storage
 * @dependencies: Config\PluginConfig
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards import file paths used between unified import preflight and import steps.
 *
 * Allows files under uploads/mksddn-mc/imports/ (current flow) and the legacy
 * uploads/mksddn-mc/preflight/ directory for backward compatibility.
 *
 * @since 2.2.0
 */
final class PreflightStagingPath {

	/**
	 * Whether the path is inside an allowed plugin staging directory.
	 *
	 * @param string $absolute_path Candidate file path.
	 * @return bool
	 */
	public static function is_allowed_path( string $absolute_path ): bool {
		if ( '' === trim( $absolute_path ) ) {
			return false;
		}

		$real_file = realpath( $absolute_path );
		if ( false === $real_file || ! is_file( $real_file ) ) {
			return false;
		}

		$normalized_file = wp_normalize_path( $real_file );

		foreach ( self::allowed_directory_roots() as $root ) {
			if ( self::path_is_under_root( $normalized_file, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build allowed staging directory roots.
	 *
	 * @return array<int, string>
	 */
	private static function allowed_directory_roots(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return array();
		}

		$base = trailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . 'mksddn-mc/';

		return array(
			PluginConfig::imports_dir(),
			$base . 'preflight/',
		);
	}

	/**
	 * Check whether a file path resolves under a configured directory root.
	 *
	 * @param string $file_path Normalized absolute file path.
	 * @param string $root      Configured directory root.
	 * @return bool
	 */
	private static function path_is_under_root( string $file_path, string $root ): bool {
		$real_root = realpath( untrailingslashit( wp_normalize_path( $root ) ) );
		if ( false !== $real_root ) {
			$prefix = trailingslashit( wp_normalize_path( $real_root ) );
			return str_starts_with( $file_path, $prefix );
		}

		$prefix = trailingslashit( wp_normalize_path( $root ) );
		return str_starts_with( $file_path, $prefix );
	}
}
