<?php
/**
 * Shared filesystem collector helpers.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams directories into Zip archives with sane defaults.
 */
class ContentCollector {

	/**
	 * File extensions that should be stored without recompression.
	 *
	 * @var string[]
	 */
	private array $store_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'zip', 'gz', 'rar', '7z', 'mp4', 'mov', 'mp3', 'ogg', 'pdf', 'wpbkp' );

	/**
	 * Append multiple directories into archive under given map.
	 *
	 * @param ZipArchive         $zip    Archive instance.
	 * @param array<string,string> $map Map of archive paths to real directories.
	 */
	public function append_directories( ZipArchive $zip, array $map ): void {
		foreach ( $map as $archive_root => $real_path ) {
			if ( ! is_dir( $real_path ) ) {
				continue;
			}

			$this->add_directory_to_zip( $zip, $real_path, $archive_root );
		}
	}

	/**
	 * Append directory recursively into archive.
	 *
	 * @param ZipArchive $zip         Archive instance.
	 * @param string     $source_dir  Absolute path to source directory.
	 * @param string     $archive_root Target folder inside archive.
	 */
	private function add_directory_to_zip( ZipArchive $zip, string $source_dir, string $archive_root ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $path => $info ) {
			if ( $this->should_skip_path( $path ) ) {
				continue;
			}

			$relative = trim( str_replace( $source_dir, '', $path ), DIRECTORY_SEPARATOR );
			$target   = trim( $archive_root . '/' . $relative, '/' );

			if ( '' === $target ) {
				continue;
			}

			if ( $info->isDir() ) {
				$zip->addEmptyDir( $target . '/' );
			} else {
				$zip->addFile( $path, $target );
				$this->maybe_adjust_compression( $zip, $target, $path );
			}
		}
	}

	/**
	 * Skip unwanted files/directories.
	 *
	 * @param string $path Absolute path.
	 */
	private function should_skip_path( string $path ): bool {
		$ignored = array(
			'/mksddn-mc/',
			'/mksddn-migrate-jobs/',
			'/mksddn-migrate-jobs-legacy/',
			'/.git/',
			'/.svn/',
			'/.hg/',
			'/.DS_Store',
		);

		foreach ( $ignored as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'wpbkp' ), true ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Disable compression for already-compressed assets.
	 *
	 * @param ZipArchive $zip   Archive instance.
	 * @param string     $target Archive relative path.
	 * @param string     $path   Source path.
	 */
	private function maybe_adjust_compression( ZipArchive $zip, string $target, string $path ): void {
		if ( ! method_exists( $zip, 'setCompressionName' ) ) {
			return;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, $this->store_extensions, true ) ) {
			$zip->setCompressionName( $target, ZipArchive::CM_STORE );
		}
	}
}


