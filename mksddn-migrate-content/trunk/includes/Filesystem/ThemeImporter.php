<?php
/**
 * @file: ThemeImporter.php
 * @description: Imports themes from archive with merge or replace mode
 * @dependencies: FilesystemHelper
 * @created: 2026-02-19
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports themes from archive.
 *
 * @since 2.1.0
 */
class ThemeImporter {

	/**
	 * Theme directory prefix in archive.
	 */
	private const THEME_ARCHIVE_PREFIX = 'wp-content/themes/';

	/**
	 * Import mode: 'merge' or 'replace'.
	 *
	 * @var string
	 */
	private string $mode = 'replace';

	/**
	 * Progress callback.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Constructor.
	 *
	 * @param string $mode Import mode: 'merge' or 'replace'.
	 */
	public function __construct( string $mode = 'replace' ) {
		$this->mode = in_array( $mode, array( 'merge', 'replace' ), true ) ? $mode : 'replace';
	}

	/**
	 * Log debug message if WP_DEBUG is enabled.
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	private function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[MksDdn MC] %s', $message ) );
		}
	}

	/**
	 * Set progress callback.
	 *
	 * @param callable $callback Callback receiving (int $percent, string $message).
	 * @return void
	 */
	public function set_progress_callback( callable $callback ): void {
		$this->progress_callback = $callback;
	}

	/**
	 * Report progress.
	 *
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 */
	private function report_progress( int $percent, string $message ): void {
		if ( is_callable( $this->progress_callback ) ) {
			call_user_func( $this->progress_callback, $percent, $message );
		}
	}

	/**
	 * Import themes from archive.
	 *
	 * @param string $archive_path Path to archive file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	public function import_themes( string $archive_path ) {
		if ( ! file_exists( $archive_path ) ) {
			return new WP_Error( 'mksddn_mc_archive_missing', __( 'Archive file not found.', 'mksddn-migrate-content' ) );
		}

		// Check available disk space before import.
		// Using multiplier of 3 to account for extraction overhead and temporary files.
		$archive_size = filesize( $archive_path );
		if ( false !== $archive_size && function_exists( 'disk_free_space' ) ) {
			$free_space = disk_free_space( dirname( get_theme_root() ) );
			$required_space = $archive_size * 3; // Multiplier accounts for extraction overhead.
			if ( false !== $free_space && $free_space < $required_space ) {
				return new WP_Error( 'mksddn_mc_insufficient_space', __( 'Insufficient disk space for theme import. Please free up space and try again.', 'mksddn-migrate-content' ) );
			}
		}

		$this->report_progress( 5, __( 'Opening archive...', 'mksddn-migrate-content' ) );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to open archive for import.', 'mksddn-migrate-content' ) );
		}

		$this->report_progress( 10, __( 'Extracting themes...', 'mksddn-migrate-content' ) );

		$result = $this->extract_themes( $zip );
		$zip->close();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->report_progress( 100, __( 'Theme import complete', 'mksddn-migrate-content' ) );

		return true;
	}

	/**
	 * Extract themes from archive.
	 *
	 * @param ZipArchive $zip Archive instance.
	 * @return true|WP_Error
	 */
	private function extract_themes( ZipArchive $zip ) {
		$theme_root = get_theme_root();
		$allowed_prefix = self::THEME_ARCHIVE_PREFIX;

		$themes_to_extract = array();

		// First pass: identify themes in archive.
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name = $stat['name'];

			// Skip manifest and payload files.
			if ( 0 === strpos( $name, 'manifest' ) || 0 === strpos( $name, 'payload/' ) ) {
				continue;
			}

			// Normalize path.
			$normalized = $this->normalize_archive_path( $name );
			if ( null === $normalized ) {
				continue;
			}

			// Check if path is within themes directory.
			if ( 0 !== strpos( $normalized, $allowed_prefix ) ) {
				continue;
			}

			// Extract theme slug from path.
			$relative_path = substr( $normalized, strlen( $allowed_prefix ) );
			$path_parts = explode( '/', $relative_path, 2 );
			$theme_slug = $path_parts[0] ?? '';

			if ( empty( $theme_slug ) ) {
				continue;
			}

			if ( ! isset( $themes_to_extract[ $theme_slug ] ) ) {
				$themes_to_extract[ $theme_slug ] = array();
			}

			$themes_to_extract[ $theme_slug ][] = $name;
		}

		if ( empty( $themes_to_extract ) ) {
			return new WP_Error( 'mksddn_mc_no_themes_in_archive', __( 'No themes found in archive.', 'mksddn-migrate-content' ) );
		}

		// Second pass: extract themes.
		$root_path = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;

		foreach ( $themes_to_extract as $theme_slug => $files ) {
			$target_theme_path = trailingslashit( $theme_root ) . $theme_slug;

			// Validate that target path is within theme root for security.
			// Check parent directory if theme doesn't exist yet.
			$real_theme_root = realpath( $theme_root );
			$check_path = is_dir( $target_theme_path ) ? $target_theme_path : dirname( $target_theme_path );
			$real_check_path = realpath( $check_path );
			if ( $real_check_path && ( ! $real_theme_root || 0 !== strpos( $real_check_path, $real_theme_root ) ) ) {
				return new WP_Error( 'mksddn_mc_invalid_theme_path', sprintf( __( 'Invalid theme path detected: %s', 'mksddn-migrate-content' ), $theme_slug ) );
			}

			// Prevent deletion of active theme or parent theme in replace mode.
			$active_stylesheet = get_stylesheet();
			$active_template = get_template();
			$is_active = $active_stylesheet === $theme_slug;
			$is_parent = $active_template === $theme_slug && $active_stylesheet !== $theme_slug;

			// Handle replace mode: delete existing theme directory.
			if ( 'replace' === $this->mode && is_dir( $target_theme_path ) ) {
				// Safety check: prevent deletion of active or parent theme.
				if ( $is_active || $is_parent ) {
					$this->log_debug( sprintf( 'Skipping deletion of active/parent theme: %s (mode: %s)', $theme_slug, $this->mode ) );
					// Fall back to merge mode for active/parent themes.
					$this->report_progress( 20, sprintf( __( 'Skipping deletion of active/parent theme: %s (using merge mode)', 'mksddn-migrate-content' ), $theme_slug ) );
				} else {
					$this->report_progress( 20, sprintf( __( 'Removing existing theme: %s', 'mksddn-migrate-content' ), $theme_slug ) );
					$this->log_debug( sprintf( 'Deleting theme directory: %s', $target_theme_path ) );
					
					if ( ! FilesystemHelper::delete( $target_theme_path, true ) ) {
						return new WP_Error( 'mksddn_mc_theme_delete_failed', sprintf( __( 'Failed to remove existing theme: %s', 'mksddn-migrate-content' ), $theme_slug ) );
					}
				}
			}

			// Extract theme files.
			foreach ( $files as $archive_file ) {
				$normalized = $this->normalize_archive_path( $archive_file );
				if ( null === $normalized ) {
					continue;
				}

				$target = trailingslashit( $root_path ) . $normalized;
				$is_directory = '/' === substr( $archive_file, -1 ) || '/' === substr( $normalized, -1 );

				if ( $is_directory ) {
					wp_mkdir_p( $target );
					continue;
				}

				$dir = dirname( $target );
				wp_mkdir_p( $dir );

				$stream = $zip->getStream( $archive_file );
				if ( ! $stream ) {
					return new WP_Error( 'mksddn_zip_stream', sprintf( __( 'Unable to read "%s" from archive.', 'mksddn-migrate-content' ), $archive_file ) );
				}

				$write_ok = FilesystemHelper::put_stream( $target, $stream );
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with ZipArchive stream

				if ( ! $write_ok ) {
					return new WP_Error( 'mksddn_fs_write', sprintf( __( 'Unable to write "%s". Check permissions.', 'mksddn-migrate-content' ), $target ) );
				}
			}

			$this->report_progress( 50, sprintf( __( 'Imported theme: %s', 'mksddn-migrate-content' ), $theme_slug ) );
			$this->log_debug( sprintf( 'Successfully imported theme: %s (mode: %s)', $theme_slug, $this->mode ) );
		}

		return true;
	}

	/**
	 * Normalize archive path by removing wrapper directories.
	 *
	 * @param string $path Raw archive path.
	 * @return string|null
	 */
	private function normalize_archive_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		// Skip manifest/payload/meta files.
		if ( 0 === strpos( $path, 'manifest' ) || 0 === strpos( $path, 'payload/' ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			$path = substr( $path, 6 );
		}

		$path = ltrim( $path, '/' );

		return '' === $path ? null : $path;
	}
}
