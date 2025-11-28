<?php
/**
 * Exports full wp-content (uploads, themes, plugins).
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use Mksddn_MC\Database\FullDatabaseExporter;
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
	 * File extensions that should be stored without recompression.
	 *
	 * @var string[]
	 */
	private array $store_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'zip', 'gz', 'rar', '7z', 'mp4', 'mov', 'mp3', 'ogg', 'pdf', 'wpbkp' );

	private FullDatabaseExporter $db_exporter;

	/**
	 * Setup exporter.
	 *
	 * @param FullDatabaseExporter|null $db_exporter Optional DB exporter.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
	}

	/**
	 * Build archive with uploads/plugins/themes and DB dump.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error
	 */
	public function export_to( string $target_path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to create archive for full export.', 'mksddn-migrate-content' ) );
		}

		$payload = array(
			'type'     => 'full-site',
			'database' => $this->db_exporter->export(),
		);

		$manifest = array(
			'format_version' => 1,
			'plugin_version' => MKSDDN_MC_VERSION,
			'type'           => 'full-site',
			'created_at_gmt' => gmdate( 'c' ),
		);

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$payload_json  = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		if ( false === $manifest_json || false === $payload_json ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'payload/content.json', $payload_json );

		$this->append_wp_content( $zip, 'files' );
		$zip->close();
		return $target_path;
	}

	/**
	 * Append wp-content directories to archive.
	 *
	 * @param ZipArchive $zip         Archive instance.
	 * @param string     $base_prefix Base directory inside archive.
	 */
	private function append_wp_content( ZipArchive $zip, string $base_prefix = '' ): void {
		foreach ( $this->get_wp_content_paths( $base_prefix ) as $archive_root => $real_path ) {
			if ( ! is_dir( $real_path ) ) {
				continue;
			}

			$this->add_directory_to_zip( $zip, $real_path, $archive_root );
		}
	}

	/**
	 * Map archive targets to physical directories.
	 *
	 * @param string $base_prefix Optional base folder.
	 * @return array<string, string>
	 */
	private function get_wp_content_paths( string $base_prefix = '' ): array {
		$prefix = '' === $base_prefix ? '' : trim( $base_prefix, '/' ) . '/';

		return array(
			$prefix . 'wp-content/uploads' => WP_CONTENT_DIR . '/uploads',
			$prefix . 'wp-content/plugins' => WP_PLUGIN_DIR,
			$prefix . 'wp-content/themes'  => get_theme_root(),
		);
	}

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

	private function maybe_adjust_compression( ZipArchive $zip, string $target, string $path ): void {
		if ( ! method_exists( $zip, 'setCompressionName' ) ) {
			return;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, $this->store_extensions, true ) ) {
			$zip->setCompressionName( $target, ZipArchive::CM_STORE );
		}
	}

	private function should_skip_path( string $path ): bool {
		$ignored = array(
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
}

