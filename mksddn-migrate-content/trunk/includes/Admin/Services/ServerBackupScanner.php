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
	 * Cache for scanned files.
	 *
	 * @var array|null
	 */
	private ?array $cache = null;

	/**
	 * Cache TTL in seconds.
	 *
	 * @var int
	 */
	private int $cache_ttl = 30;

	/**
	 * Cache timestamp.
	 *
	 * @var int|null
	 */
	private ?int $cache_timestamp = null;

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

		$validation_error = $this->validate_imports_dir( $imports_dir );
		if ( $validation_error ) {
			return $validation_error;
		}

		// Check cache.
		if ( null !== $this->cache && null !== $this->cache_timestamp ) {
			if ( time() - $this->cache_timestamp < $this->cache_ttl ) {
				return $this->cache;
			}
		}

		$files = array();
		$items = @scandir( $imports_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- directory existence checked above

		if ( false === $items ) {
			return new WP_Error(
				'mksddn_mc_imports_scan_failed',
				__( 'Failed to scan imports directory.', 'mksddn-migrate-content' )
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
				'name'     => sanitize_file_name( $item ),
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

		// Cache result.
		$this->cache = $files;
		$this->cache_timestamp = time();

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

		$validation_error = $this->validate_imports_dir( $imports_dir );
		if ( $validation_error ) {
			return $validation_error;
		}

		$file_path = trailingslashit( $imports_dir ) . basename( $filename );

		// Security: prevent path traversal attacks.
		$real_path = realpath( $file_path );
		$real_imports_dir = realpath( $imports_dir );

		if ( ! $real_path || ! $real_imports_dir || strpos( $real_path, $real_imports_dir ) !== 0 ) {
			return new WP_Error(
				'mksddn_mc_import_file_invalid_path',
				__( 'Invalid file path.', 'mksddn-migrate-content' )
			);
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'mksddn_mc_import_file_not_found',
				__( 'Import file not found.', 'mksddn-migrate-content' )
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
			'name'     => sanitize_file_name( $filename ),
			'path'     => $file_path,
			'size'     => $file_size,
			'extension' => $extension,
		);
	}
}

