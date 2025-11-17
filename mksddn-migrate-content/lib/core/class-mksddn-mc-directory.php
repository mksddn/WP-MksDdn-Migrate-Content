<?php
/**
 * Directory handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Directory operations class
 */
class MksDdn_MC_Directory {

	/**
	 * Create directory recursively
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function create( $path ) {
		if ( is_dir( $path ) ) {
			return true;
		}

		return wp_mkdir_p( $path );
	}

	/**
	 * Delete directory recursively
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function delete( $path ) {
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$files = array_diff( scandir( $path ), array( '.', '..' ) );

		foreach ( $files as $file ) {
			$file_path = $path . DIRECTORY_SEPARATOR . $file;
			if ( is_dir( $file_path ) ) {
				self::delete( $file_path );
			} else {
				@unlink( $file_path );
			}
		}

		return @rmdir( $path );
	}

	/**
	 * Check if directory exists
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function exists( $path ) {
		return is_dir( $path );
	}

	/**
	 * Get directory size
	 *
	 * @param string $path Directory path
	 * @return int Size in bytes
	 */
	public static function size( $path ) {
		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$size = 0;
		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			$size += $file->getSize();
		}

		return $size;
	}

	/**
	 * Copy directory recursively
	 *
	 * @param string $source Source directory
	 * @param string $destination Destination directory
	 * @return bool
	 */
	public static function copy( $source, $destination ) {
		if ( ! is_dir( $source ) ) {
			return false;
		}

		if ( ! is_dir( $destination ) ) {
			self::create( $destination );
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			$dest_path = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

			if ( $item->isDir() ) {
				self::create( $dest_path );
			} else {
				copy( $item->getPathname(), $dest_path );
			}
		}

		return true;
	}
}

