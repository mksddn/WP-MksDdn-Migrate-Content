<?php
/**
 * File handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * File operations class
 */
class MksDdn_MC_File {

	/**
	 * Check if file exists
	 *
	 * @param string $path File path
	 * @return bool
	 */
	public static function exists( $path ) {
		return file_exists( $path );
	}

	/**
	 * Read file contents
	 *
	 * @param string $path File path
	 * @return string|false
	 */
	public static function read( $path ) {
		if ( ! self::exists( $path ) ) {
			return false;
		}

		return file_get_contents( $path );
	}

	/**
	 * Write file contents
	 *
	 * @param string $path File path
	 * @param string $content File content
	 * @return bool
	 */
	public static function write( $path, $content ) {
		$directory = dirname( $path );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		return file_put_contents( $path, $content ) !== false;
	}

	/**
	 * Delete file
	 *
	 * @param string $path File path
	 * @return bool
	 */
	public static function delete( $path ) {
		if ( ! self::exists( $path ) ) {
			return false;
		}

		return @unlink( $path );
	}

	/**
	 * Copy file
	 *
	 * @param string $source Source file path
	 * @param string $destination Destination file path
	 * @return bool
	 */
	public static function copy( $source, $destination ) {
		if ( ! self::exists( $source ) ) {
			return false;
		}

		$directory = dirname( $destination );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		return copy( $source, $destination );
	}

	/**
	 * Move file
	 *
	 * @param string $source Source file path
	 * @param string $destination Destination file path
	 * @return bool
	 */
	public static function move( $source, $destination ) {
		if ( ! self::exists( $source ) ) {
			return false;
		}

		$directory = dirname( $destination );
		if ( ! is_dir( $directory ) ) {
			wp_mkdir_p( $directory );
		}

		return rename( $source, $destination );
	}

	/**
	 * Get file size
	 *
	 * @param string $path File path
	 * @return int|false
	 */
	public static function size( $path ) {
		if ( ! self::exists( $path ) ) {
			return false;
		}

		return filesize( $path );
	}

	/**
	 * Get file modification time
	 *
	 * @param string $path File path
	 * @return int|false
	 */
	public static function mtime( $path ) {
		if ( ! self::exists( $path ) ) {
			return false;
		}

		return filemtime( $path );
	}
}

