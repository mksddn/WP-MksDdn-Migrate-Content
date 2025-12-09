<?php
/**
 * Simple wrapper over WP_Filesystem for direct FS operations.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Support;


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
	 * Get filesystem instance (direct transport).
	 */
	public static function instance() {
		if ( null === self::$filesystem ) {
			$root = defined( 'ABSPATH' ) ? constant( 'ABSPATH' ) : dirname( __DIR__, 5 ) . '/';
			require_once $root . 'wp-admin/includes/file.php';
			require_once $root . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once $root . 'wp-admin/includes/class-wp-filesystem-direct.php';

			global $wp_filesystem;
			if ( ! $wp_filesystem && function_exists( 'WP_Filesystem' ) ) {
				call_user_func( 'WP_Filesystem' );
			}

			self::$filesystem = $wp_filesystem;
		}

		return self::$filesystem;
	}

	/**
	 * Write a string into file.
	 */
	public static function put_contents( string $path, string $contents, ?int $mode = null ): bool {
		$chmod = $mode ?? ( defined( 'FS_CHMOD_FILE' ) ? (int) constant( 'FS_CHMOD_FILE' ) : null );

		return self::instance()->put_contents( $path, $contents, $chmod );
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

		if ( defined( 'FS_CHMOD_FILE' ) ) {
			@chmod( $path, (int) constant( 'FS_CHMOD_FILE' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- best effort chmod after streaming
		}

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
}


