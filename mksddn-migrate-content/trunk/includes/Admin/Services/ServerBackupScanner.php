<?php
/**
 * @file: ServerBackupScanner.php
 * @description: Service for scanning server imports directory for available backup files
 * @dependencies: Config\PluginConfig
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Config\PluginConfig;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for scanning server imports directory.
 *
 * @since 1.0.1
 */
class ServerBackupScanner {

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	private int $cache_ttl = 30;

	/**
	 * Ensure imports directory exists, create if needed.
	 *
	 * @return WP_Error|null Error if creation fails, null otherwise.
	 * @since 1.0.1
	 */
	private function ensure_imports_dir(): ?WP_Error {
		$imports_dir = PluginConfig::imports_dir();

		if ( ! is_dir( $imports_dir ) ) {
			$create_result = PluginConfig::create_required_directories();
			if ( is_wp_error( $create_result ) ) {
				return $create_result;
			}
		}

		return null;
	}

	/**
	 * Validate imports directory.
	 *
	 * @param string $imports_dir Directory path.
	 * @return WP_Error|null Error if validation fails, null otherwise.
	 * @since 1.0.1
	 */
	private function validate_imports_dir( string $imports_dir ): ?WP_Error {
		if ( ! is_dir( $imports_dir ) ) {
			return new WP_Error(
				'mksddn_mc_imports_dir_invalid',
				__( 'Imports directory does not exist. Please reactivate the plugin to create required directories.', 'mksddn-migrate-content' )
			);
		}

		if ( ! is_readable( $imports_dir ) ) {
			return new WP_Error(
				'mksddn_mc_imports_dir_unreadable',
				__( 'Imports directory is not readable.', 'mksddn-migrate-content' )
			);
		}

		return null;
	}

	/**
	 * Get list of available import files from server directory.
	 *
	 * @return array|WP_Error List of import files with metadata or error.
	 * @since 1.0.1
	 */
	public function scan(): array|WP_Error {
		$imports_dir = PluginConfig::imports_dir();

		// Ensure directory exists, create if needed.
		$ensure_error = $this->ensure_imports_dir();
		if ( $ensure_error ) {
			return $ensure_error;
		}

		$validation_error = $this->validate_imports_dir( $imports_dir );
		if ( $validation_error ) {
			return $validation_error;
		}

		// Check WordPress transient cache.
		$cache_key = 'mksddn_mc_server_backups';
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$files = array();
		$items = @scandir( $imports_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- directory existence checked above

		if ( false === $items ) {
			$error_message = __( 'Failed to scan imports directory.', 'mksddn-migrate-content' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'MksDdn Migrate Content: %s (Directory: %s)', $error_message, $imports_dir ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error(
				'mksddn_mc_imports_scan_failed',
				$error_message
			);
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$file_path = trailingslashit( $imports_dir ) . $item;

			if ( ! is_file( $file_path ) ) {
				continue;
			}

			$extension = strtolower( pathinfo( $item, PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
				continue;
			}

			$file_size = filesize( $file_path );
			if ( false === $file_size ) {
				continue;
			}

			$file_mtime = filemtime( $file_path );
			if ( false === $file_mtime ) {
				$file_mtime = 0;
			}

			$files[] = array(
				// Use actual filename from filesystem. Sanitization should be done when displaying in UI.
				'name'     => $item,
				'path'     => $file_path,
				'size'     => $file_size,
				'size_human' => size_format( $file_size ),
				'modified' => $file_mtime,
				'modified_human' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $file_mtime ),
				'extension' => $extension,
			);
		}

		usort(
			$files,
			function ( $a, $b ) {
				return $b['modified'] - $a['modified'];
			}
		);

		// Cache result in WordPress transient.
		set_transient( $cache_key, $files, $this->cache_ttl );

		return $files;
	}

	/**
	 * Get import file by name.
	 *
	 * @param string $filename Import filename.
	 * @return array|WP_Error File data or error.
	 * @since 1.0.1
	 */
	public function get_file( string $filename ): array|WP_Error {
		$imports_dir = PluginConfig::imports_dir();

		// Debug logging for troubleshooting.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'MksDdn Migrate Content: get_file() called with filename: %s', $filename ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Ensure directory exists, create if needed.
		$ensure_error = $this->ensure_imports_dir();
		if ( $ensure_error ) {
			return $ensure_error;
		}

		$validation_error = $this->validate_imports_dir( $imports_dir );
		if ( $validation_error ) {
			return $validation_error;
		}

		// Prevent path traversal attacks by using basename to strip any directory components.
		$safe_filename = basename( $filename );
		$file_path = trailingslashit( $imports_dir ) . $safe_filename;

		// Debug logging for troubleshooting.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'MksDdn Migrate Content: get_file() checking path: %s (exists: %s)', $file_path, file_exists( $file_path ) ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		// Check if file exists before path validation.
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'mksddn_mc_import_file_not_found',
				__( 'Import file not found.', 'mksddn-migrate-content' )
			);
		}

		// Security: prevent path traversal attacks by resolving real paths.
		// realpath() resolves symlinks and removes '..' components, ensuring the file
		// is actually within the imports directory.
		$real_path = realpath( $file_path );
		$real_imports_dir = realpath( $imports_dir );

		// Fallback: If realpath fails (e.g., on macOS, Windows systems or permission issues),
		// use normalized path. This is less secure but necessary for cross-platform compatibility.
		// Note: This fallback should only be used when realpath fails but the path is known to be valid.
		if ( ! $real_imports_dir && is_dir( $imports_dir ) ) {
			$real_imports_dir = rtrim( str_replace( '\\', '/', $imports_dir ), '/' );
		}

		// If realpath failed but file exists, use normalized path as fallback.
		// This handles cases where realpath() returns false on macOS for valid files.
		if ( ! $real_path && file_exists( $file_path ) && is_readable( $file_path ) ) {
			$real_path = str_replace( '\\', '/', $file_path );
		}

		if ( ! $real_path ) {
			return new WP_Error(
				'mksddn_mc_import_file_invalid_path',
				__( 'Invalid file path.', 'mksddn-migrate-content' )
			);
		}

		if ( ! $real_imports_dir ) {
			return new WP_Error(
				'mksddn_mc_imports_dir_invalid',
				__( 'Imports directory path is invalid.', 'mksddn-migrate-content' )
			);
		}

		// Normalize paths for cross-platform compatibility.
		// Ensure both paths use the same normalization method for reliable comparison.
		$real_imports_dir_normalized = rtrim( str_replace( '\\', '/', $real_imports_dir ), '/' ) . '/';
		$real_path_normalized = str_replace( '\\', '/', $real_path );

		// Ensure the file path is within the imports directory.
		// When using fallback paths, verify by checking if normalized path starts with imports dir.
		// Use case-sensitive comparison as macOS file system is case-sensitive by default.
		if ( strpos( $real_path_normalized, $real_imports_dir_normalized ) !== 0 ) {
			// Debug logging for troubleshooting.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'MksDdn Migrate Content: Path validation failed. File path: %s, Imports dir: %s', $real_path_normalized, $real_imports_dir_normalized ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return new WP_Error(
				'mksddn_mc_import_file_invalid_path',
				__( 'Invalid file path.', 'mksddn-migrate-content' )
			);
		}

		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'mksddn_mc_import_file_unreadable',
				__( 'Import file is not readable.', 'mksddn-migrate-content' )
			);
		}

		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return new WP_Error(
				'mksddn_mc_import_file_invalid_type',
				__( 'Invalid import file type. Only .wpbkp and .json files are supported.', 'mksddn-migrate-content' )
			);
		}

		$file_size = filesize( $file_path );
		if ( false === $file_size ) {
			return new WP_Error(
				'mksddn_mc_import_file_invalid_size',
				__( 'Unable to determine import file size.', 'mksddn-migrate-content' )
			);
		}

		return array(
			'name'     => $safe_filename,
			'path'     => $file_path,
			'size'     => $file_size,
			'extension' => $extension,
		);
	}
}

