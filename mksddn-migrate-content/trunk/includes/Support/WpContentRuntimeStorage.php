<?php
/**
 * @file: WpContentRuntimeStorage.php
 * @description: Ephemeral full-site import storage under wp-content/mksddn-mc
 * @dependencies: FilesystemHelper, FullImportMaintenance
 * @created: 2026-08-14
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short-lived runtime paths outside uploads (survive full-site uploads replacement).
 *
 * The tree is created only during full-site import and removed when idle.
 *
 * @since 2.6.0
 */
final class WpContentRuntimeStorage {

	/**
	 * Ephemeral plugin root under wp-content.
	 *
	 * @return string
	 */
	public static function root(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'mksddn-mc';
	}

	/**
	 * Runtime lock directory path.
	 *
	 * @return string
	 */
	public static function runtime_dir(): string {
		return trailingslashit( self::root() ) . 'runtime';
	}

	/**
	 * Theme backup root directory path.
	 *
	 * @return string
	 */
	public static function theme_backups_dir(): string {
		return trailingslashit( self::root() ) . 'theme-backups';
	}

	/**
	 * Ensure a runtime subdirectory exists and is web-guarded.
	 *
	 * @param string $dir Absolute directory path under the ephemeral root.
	 * @return bool
	 */
	public static function ensure_protected_directory( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		self::protect_operation_directories( array( self::root(), $dir ) );

		return is_dir( $dir );
	}

	/**
	 * Apply web guards to directories used by an in-progress full import.
	 *
	 * @param array<int, string> $directories Directory paths.
	 * @return void
	 */
	public static function protect_operation_directories( array $directories ): void {
		foreach ( array_unique( array_filter( $directories ) ) as $directory ) {
			if ( is_dir( $directory ) ) {
				FilesystemHelper::protect_directory_from_web( $directory );
			}
		}
	}

	/**
	 * Remove theme backup directories after they are no longer needed.
	 *
	 * Safe while a full import lock is still active.
	 *
	 * @return void
	 */
	public static function cleanup_theme_backups(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		$backups_dir = self::theme_backups_dir();
		$real_backups = realpath( $backups_dir );
		if ( false !== $real_backups && is_dir( $real_backups ) && self::is_within_ephemeral_root( $real_backups ) ) {
			FilesystemHelper::delete( $real_backups, true );
		}

		if ( ! FullImportMaintenance::is_active() ) {
			$real_root = realpath( self::root() );
			if ( false !== $real_root ) {
				self::remove_root_if_empty_or_guards_only( $real_root );
			}
		}
	}

	/**
	 * Remove the ephemeral wp-content tree when no full import is active.
	 *
	 * @return void
	 */
	public static function cleanup_if_idle(): void {
		if ( FullImportMaintenance::is_active() ) {
			return;
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		$root = self::root();
		if ( ! is_dir( $root ) ) {
			return;
		}

		$real_root = realpath( $root );
		if ( false === $real_root || ! self::is_ephemeral_root( $real_root ) ) {
			return;
		}

		foreach ( array( self::theme_backups_dir(), self::runtime_dir() ) as $child ) {
			$real_child = realpath( $child );
			if ( false !== $real_child && is_dir( $real_child ) && self::is_within_ephemeral_root( $real_child ) ) {
				FilesystemHelper::delete( $real_child, true );
			}
		}

		self::remove_root_if_empty_or_guards_only( $real_root );
	}

	/**
	 * Whether the path resolves to the configured ephemeral root.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 */
	private static function is_ephemeral_root( string $path ): bool {
		$expected = wp_normalize_path( untrailingslashit( self::root() ) );
		$actual   = wp_normalize_path( untrailingslashit( $path ) );

		return $expected === $actual;
	}

	/**
	 * Whether the path stays inside the ephemeral root.
	 *
	 * @param string $path Absolute directory path.
	 * @return bool
	 */
	private static function is_within_ephemeral_root( string $path ): bool {
		$prefix = trailingslashit( wp_normalize_path( untrailingslashit( self::root() ) ) );

		return str_starts_with( wp_normalize_path( $path ), $prefix );
	}

	/**
	 * Delete the ephemeral root when it only contains guard files or is empty.
	 *
	 * @param string $dir Absolute root directory path.
	 * @return void
	 */
	private static function remove_root_if_empty_or_guards_only( string $dir ): void {
		if ( ! is_dir( $dir ) || ! self::is_ephemeral_root( $dir ) ) {
			return;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return;
		}

		$allowed = array( '.', '..', '.htaccess', 'web.config', 'index.php' );

		foreach ( $entries as $entry ) {
			if ( in_array( $entry, $allowed, true ) ) {
				continue;
			}

			$entry_path = trailingslashit( $dir ) . $entry;
			if ( is_dir( $entry_path ) ) {
				if ( ! self::directory_contains_only_guards( $entry_path ) ) {
					return;
				}

				FilesystemHelper::delete( $entry_path, true );
				continue;
			}

			return;
		}

		FilesystemHelper::delete( $dir, true );
	}

	/**
	 * Whether a directory contains only web-guard stub files.
	 *
	 * @param string $dir Absolute directory path.
	 * @return bool
	 */
	private static function directory_contains_only_guards( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return true;
		}

		$entries = scandir( $dir );
		if ( false === $entries ) {
			return false;
		}

		$allowed = array( '.', '..', '.htaccess', 'web.config', 'index.php' );

		foreach ( $entries as $entry ) {
			if ( in_array( $entry, $allowed, true ) ) {
				continue;
			}

			return false;
		}

		return true;
	}
}
