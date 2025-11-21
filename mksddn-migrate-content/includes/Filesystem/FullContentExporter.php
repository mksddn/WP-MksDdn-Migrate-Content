<?php
/**
 * Exports full wp-content (uploads, themes, plugins).
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects filesystem data for bundling.
 */
class FullContentExporter {

	/**
	 * Build archive with uploads/plugins/themes.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error
	 */
	public function export_to( string $target_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to create archive for full export.', 'mksddn-migrate-content' ) );
		}

		$paths = array(
			'wp-content/uploads' => WP_CONTENT_DIR . '/uploads',
			'wp-content/plugins' => WP_PLUGIN_DIR,
			'wp-content/themes'  => get_theme_root(),
		);

		foreach ( $paths as $archive_root => $real_path ) {
			if ( ! is_dir( $real_path ) ) {
				continue;
			}

			$this->add_directory_to_zip( $zip, $real_path, $archive_root );
		}

		$zip->close();
		return $target_path;
	}

	private function add_directory_to_zip( ZipArchive $zip, string $source_dir, string $archive_root ): void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $path => $info ) {
			$relative = trim( str_replace( $source_dir, '', $path ), DIRECTORY_SEPARATOR );
			$target   = trim( $archive_root . '/' . $relative, '/' );

			if ( '' === $target ) {
				continue;
			}

			if ( $info->isDir() ) {
				$zip->addEmptyDir( $target . '/' );
			} else {
				$zip->addFile( $path, $target );
			}
		}
	}
}

