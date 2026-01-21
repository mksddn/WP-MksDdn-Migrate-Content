<?php
/**
 * Exports full wp-content (uploads, themes, plugins).
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects filesystem data for bundling.
 */
class FullContentExporter {

	private FullDatabaseExporter $db_exporter;
	private ContentCollector $collector;

	/**
	 * Setup exporter.
	 *
	 * @param FullDatabaseExporter|null $db_exporter Optional DB exporter.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
	}

	/**
	 * Build archive with uploads/plugins/themes and DB dump.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error
	 */
	public function export_to( string $target_path ) {
		$dir_result = FilesystemHelper::ensure_directory( $target_path );
		if ( is_wp_error( $dir_result ) ) {
			return new WP_Error( 'mksddn_zip_dir', __( 'Unable to create export directory.', 'mksddn-migrate-content' ) );
		}

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
		$this->collector->append_directories( $zip, $this->get_wp_content_paths( $base_prefix ) );
	}

	/**
	 * Map archive targets to physical directories.
	 *
	 * @param string $base_prefix Optional base folder.
	 * @return array<string, string>
	 */
	private function get_wp_content_paths( string $base_prefix = '' ): array {
		$prefix = '' === $base_prefix ? '' : trim( $base_prefix, '/' ) . '/';
		$uploads = wp_upload_dir();

		return array(
			$prefix . 'wp-content/uploads' => $uploads['basedir'],
			$prefix . 'wp-content/plugins' => dirname( plugin_dir_path( MKSDDN_MC_FILE ) ),
			$prefix . 'wp-content/themes'  => get_theme_root(),
		);
	}

}

