<?php
/**
 * @file: ThemeArchivePathHelper.php
 * @description: Shared normalization for theme paths inside .wpbkp archives
 * @dependencies: none
 * @created: 2026-07-16
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes theme archive entry paths (shared by importer and preflight).
 *
 * @since 2.3.4
 */
class ThemeArchivePathHelper {

	/**
	 * Theme directory prefix inside archives.
	 */
	public const ARCHIVE_PREFIX = 'wp-content/themes/';

	/**
	 * Normalize archive entry path (strip wrappers, reject traversal).
	 *
	 * Mirrors historical ThemeImporter rules so preflight and import agree.
	 *
	 * @param string $path Raw zip entry name.
	 * @return string|null Normalized path or null if skipped/invalid.
	 */
	public static function normalize( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$path = str_replace( '\\', '/', $path );
		if ( false !== strpos( $path, "\0" ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'manifest' ) || 0 === strpos( $path, 'payload/' ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			$path = substr( $path, 6 );
		}

		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			return null;
		}

		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null;
			}
		}

		return $path;
	}
}
