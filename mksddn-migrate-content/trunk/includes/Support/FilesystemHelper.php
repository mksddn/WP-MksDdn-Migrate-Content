<?php
/**
 * Simple wrapper over WP_Filesystem for direct FS operations.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Support;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides a shared WP_Filesystem_Direct instance and helpers.
 */
final class FilesystemHelper {

	/**
	 * Cached filesystem instance.
	 *
	 * @var \WP_Filesystem_Direct|null
	 */
	private static $filesystem = null;

	/**
	 * File permissions (local to this class, not global).
	 *
	 * @var int
	 */
	private static $file_chmod = 0644;

	/**
	 * Directory permissions (local to this class, not global).
	 *
	 * @var int
	 */
	private static $dir_chmod = 0755;

	/**
	 * Whether permissions have been initialized.
	 *
	 * @var bool
	 */
	private static $initialized = false;

	/**
	 * Get filesystem instance (direct transport).
	 *
	 * @return \WP_Filesystem_Direct
	 */
	public static function instance(): \WP_Filesystem_Direct {
		if ( null === self::$filesystem ) {
			if ( ! defined( 'ABSPATH' ) ) {
				wp_die( esc_html__( 'ABSPATH is not defined.', 'mksddn-migrate-content' ) );
			}
			$root = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
			require_once $root . 'wp-admin/includes/file.php';

			// Initialize permissions from existing files or use WordPress constants if defined.
			// We intentionally do NOT define global FS_CHMOD_* constants to avoid affecting other plugins.
			self::init_permissions( $root );

			require_once $root . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once $root . 'wp-admin/includes/class-wp-filesystem-direct.php';

			// Always use direct filesystem for reliability.
			self::$filesystem = new \WP_Filesystem_Direct( null );
		}

		return self::$filesystem;
	}

	/**
	 * Initialize file/directory permissions.
	 *
	 * Uses existing WordPress constants if defined, otherwise detects from filesystem.
	 * Does NOT define global constants to avoid changing global behavior.
	 *
	 * @param string $root WordPress root path.
	 * @return void
	 */
	private static function init_permissions( string $root ): void {
		if ( self::$initialized ) {
			return;
		}

		// Use WordPress constants if already defined by core or other plugins.
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			self::$file_chmod = FS_CHMOD_FILE;
		} else {
			// Detect from existing index.php file permissions.
			$index_file = $root . 'index.php';
			$perms      = file_exists( $index_file ) ? fileperms( $index_file ) : false;
			if ( false !== $perms ) {
				self::$file_chmod = ( $perms & 0777 ) | 0644;
			}
		}

		if ( defined( 'FS_CHMOD_DIR' ) ) {
			self::$dir_chmod = FS_CHMOD_DIR;
		} else {
			// Detect from root directory permissions.
			$perms = file_exists( $root ) ? fileperms( $root ) : false;
			if ( false !== $perms ) {
				self::$dir_chmod = ( $perms & 0777 ) | 0755;
			}
		}

		self::$initialized = true;
	}

	/**
	 * Write a string into file.
	 */
	public static function put_contents( string $path, string $contents, ?int $mode = null ): bool {
		// Ensure instance is created.
		self::instance();
		$chmod = $mode ?? self::$file_chmod;

		return self::$filesystem->put_contents( $path, $contents, $chmod );
	}

	/**
	 * Write stream contents into file.
	 *
	 * @param resource $stream Source stream.
	 */
	public static function put_stream( string $path, $stream, int $chunk_size = 131072 ): bool {
		$handle = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct streaming required
		if ( ! $handle ) {
			return false;
		}

		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, $chunk_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- direct streaming required
			if ( false === $chunk ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen for streaming
				return false;
			}

			$written = fwrite( $handle, $chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- paired with fopen for streaming
			if ( false === $written ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen for streaming
				return false;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen for streaming

		// Ensure instance is created and apply file permissions.
		self::instance();
		@chmod( $path, self::$file_chmod ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- best effort chmod after streaming

		return true;
	}

	/**
	 * Delete a file or directory.
	 */
	public static function delete( string $path, bool $recursive = false ): bool {
		return self::instance()->delete( $path, $recursive );
	}

	/**
	 * Remove directory tree.
	 */
	public static function rmdir( string $path ): bool {
		return self::instance()->rmdir( $path, true );
	}

	/**
	 * Move/rename a path.
	 */
	public static function move( string $from, string $to, bool $overwrite = true ): bool {
		return self::instance()->move( $from, $to, $overwrite );
	}

	/**
	 * Copy a file.
	 */
	public static function copy( string $from, string $to, bool $overwrite = true ): bool {
		return self::instance()->copy( $from, $to, $overwrite );
	}

	/**
	 * Append or overwrite file contents with binary-safe handling.
	 *
	 * @param string $path     Target path.
	 * @param string $contents Raw bytes.
	 * @param bool   $reset    Whether to overwrite instead of append.
	 */
	public static function write_bytes( string $path, string $contents, bool $reset = false ): bool {
		$mode   = $reset ? 'wb' : 'ab';
		$handle = fopen( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary streaming required
		if ( ! $handle ) {
			return false;
		}

		$result = fwrite( $handle, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- binary streaming required
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen

		return false !== $result;
	}

	/**
	 * Read bytes from a file starting at offset.
	 *
	 * @return string|false
	 */
	public static function read_bytes( string $path, int $offset, int $length ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- binary streaming required
		if ( ! $handle ) {
			return false;
		}

		fseek( $handle, $offset ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- paired with fopen
		$data = fread( $handle, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- binary streaming required
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen

		return $data;
	}

	/**
	 * Ensure directory exists, create if needed.
	 *
	 * @param string $path File path (directory will be created for this file).
	 * @return bool|WP_Error True if directory exists or was created, WP_Error on failure.
	 */
	public static function ensure_directory( string $path ) {
		$dir = dirname( $path );
		
		if ( is_dir( $dir ) ) {
			return true;
		}
		
		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'mksddn_dir_create',
				__( 'Unable to create directory.', 'mksddn-migrate-content' ),
				array(
					'status' => 500,
					'path'   => $dir,
				)
			);
		}
		
		return true;
	}
}


