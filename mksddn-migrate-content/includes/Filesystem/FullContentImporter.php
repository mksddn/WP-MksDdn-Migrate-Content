<?php
/**
 * Restores full wp-content from archive.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports files to uploads/themes/plugins.
 */
class FullContentImporter {

	/**
	 * Extract allowed paths to wp-content.
	 *
	 * @param string $archive_path Uploaded archive.
	 * @return true|WP_Error
	 */
	public function import_from( string $archive_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to open archive for import.', 'mksddn-migrate-content' ) );
		}

		$allowed_roots = array(
			'wp-content/uploads',
			'wp-content/plugins',
			'wp-content/themes',
		);

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$name = $stat['name'];
			if ( $this->should_skip_path( $name ) ) {
				continue;
			}
			if ( ! $this->is_allowed_path( $name, $allowed_roots ) ) {
				continue;
			}

			$target = trailingslashit( ABSPATH ) . $name;

			if ( '/' === substr( $name, -1 ) ) {
				wp_mkdir_p( $target );
				continue;
			}

			$dir = dirname( $target );
			wp_mkdir_p( $dir );

			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				$zip->close();
				return new WP_Error( 'mksddn_zip_stream', sprintf( __( 'Unable to read "%s" from archive.', 'mksddn-migrate-content' ), $name ) );
			}

			$file = fopen( $target, 'wb' );
			if ( ! $file ) {
				fclose( $stream );
				$zip->close();
				return new WP_Error( 'mksddn_fs_write', sprintf( __( 'Unable to write "%s". Check permissions.', 'mksddn-migrate-content' ), $target ) );
			}

			stream_copy_to_stream( $stream, $file );
			fclose( $stream );
			fclose( $file );
		}

		$zip->close();
		return true;
	}

	private function is_allowed_path( string $path, array $allowed ): bool {
		foreach ( $allowed as $root ) {
			if ( 0 === strpos( $path, $root ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Skip version-control or dot directories.
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	private function should_skip_path( string $path ): bool {
		$ignored = array( '/.git/', '/.svn/', '/.hg/', '/.DS_Store' );
		foreach ( $ignored as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

