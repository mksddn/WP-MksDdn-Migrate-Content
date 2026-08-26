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
 * Allows files under uploads/mksddn-mc/imports/ (user-managed backups) and
 * uploads/mksddn-mc/preflight/ (ephemeral browser uploads between import steps).
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
		return array(
			PluginConfig::imports_dir(),
			PluginConfig::preflight_dir(),
		);
	}

	/**
	 * Whether the path is a plugin-owned staging file that should be deleted after import.
	 *
	 * User-placed backups under imports/ are not ephemeral.
	 *
	 * @param string $absolute_path Candidate file path.
	 * @return bool
	 */
	public static function is_ephemeral_path( string $absolute_path ): bool {
		if ( ! self::is_allowed_path( $absolute_path ) ) {
			return false;
		}

		$real_file = realpath( $absolute_path );
		if ( false === $real_file ) {
			return false;
		}

		return self::path_is_under_root( wp_normalize_path( $real_file ), PluginConfig::preflight_dir() );
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
