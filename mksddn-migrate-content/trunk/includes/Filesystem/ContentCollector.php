<?php
/**
 * Shared filesystem collector helpers.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
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
	 * @return array<string,int>|WP_Error Archive write stats or error.
	 */
	public function append_directories( ZipArchive $zip, array $map ): array|WP_Error {
		$stats = array(
			'directories' => 0,
			'files'       => 0,
		);

		foreach ( $map as $archive_root => $real_path ) {
			if ( ! is_dir( $real_path ) ) {
				continue;
			}

			$result = $this->add_directory_to_zip( $zip, $real_path, $archive_root );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stats['directories'] += $result['directories'];
			$stats['files']       += $result['files'];
		}

		return $stats;
	}

	/**
	 * Append directory recursively into archive.
	 *
	 * @param ZipArchive $zip         Archive instance.
	 * @param string     $source_dir  Absolute path to source directory.
	 * @param string     $archive_root Target folder inside archive.
	 * @return array<string,int>|WP_Error Archive write stats or error.
	 */
	private function add_directory_to_zip( ZipArchive $zip, string $source_dir, string $archive_root ): array|WP_Error {
		$stats = array(
			'directories' => 0,
			'files'       => 0,
		);

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
				if ( ! $zip->addEmptyDir( $target . '/' ) ) {
					return new WP_Error(
						'mksddn_zip_add_directory',
						sprintf(
							/* translators: %s: archive path. */
							__( 'Could not add directory to the export archive: %s', 'mksddn-migrate-content' ),
							$target
						)
					);
				}
				$stats['directories']++;
			} else {
				if ( ! is_readable( $path ) ) {
					return new WP_Error(
						'mksddn_zip_unreadable_source',
						sprintf(
							/* translators: %s: archive path. */
							__( 'Could not read source file for export: %s', 'mksddn-migrate-content' ),
							$target
						)
					);
				}

				if ( ! $zip->addFile( $path, $target ) ) {
					return new WP_Error(
						'mksddn_zip_add_file',
						sprintf(
							/* translators: %s: archive path. */
							__( 'Could not add file to the export archive: %s', 'mksddn-migrate-content' ),
							$target
						)
					);
				}
				$this->maybe_adjust_compression( $zip, $target, $path );
				$stats['files']++;
			}
		}

		return $stats;
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


