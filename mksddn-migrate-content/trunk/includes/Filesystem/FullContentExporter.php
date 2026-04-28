<?php
/**
 * @file: FullContentExporter.php
 * @description: Builds full-site .wpbkp ZIP with database JSON and wp-content trees; validates archive completion and size.
 * @dependencies: FullDatabaseExporter, ContentCollector, ExportMemoryHelper, FilesystemHelper, ZipArchive
 * @created: 2024-12-15
 */

/**
 * Exports full wp-content (uploads, plugins, mu-plugins, themes).
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Support\ExportMemoryHelper;
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
	 * @param ContentCollector|null     $collector   Optional collector.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
	}

	/**
	 * Build archive with uploads/plugins/themes and DB dump.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error Path on success.
	 */
	public function export_to( string $target_path ) {
		$original_memory = ExportMemoryHelper::raise_for_export();
		try {
			$dir_result = FilesystemHelper::ensure_directory( $target_path );
			if ( is_wp_error( $dir_result ) ) {
				return new WP_Error(
					'mksddn_zip_dir',
					__( 'Unable to create export directory.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_open',
					__( 'Unable to create archive for full export. Check that the server temp directory is writable and has free disk space.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
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
			unset( $payload );

			if ( false === $manifest_json || false === $payload_json ) {
				$zip->close();
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_mc_full_export_payload',
					__( 'Failed to encode full-site payload. The database may be too large for this export method — try increasing PHP memory_limit or use a host-level backup.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_memory_hint_message() )
				);
			}

			if ( ! $zip->addFromString( 'manifest.json', $manifest_json ) ) {
				$zip->close();
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_add_manifest',
					__( 'Could not write manifest into the export archive.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			if ( ! $zip->addFromString( 'payload/content.json', $payload_json ) ) {
				$zip->close();
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_add_payload',
					__( 'Could not write database payload into the export archive. The dump may exceed available memory or disk space.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_memory_hint() )
				);
			}

			$this->append_wp_content( $zip, 'files' );

			if ( ! $zip->close() ) {
				$this->discard_archive( $target_path );
				$status = '';
				if ( method_exists( $zip, 'getStatusString' ) ) {
					$status = (string) $zip->getStatusString();
				}
				$message = __( 'Failed to finalize the export archive.', 'mksddn-migrate-content' );
				if ( '' !== $status ) {
					$message .= ' ' . sprintf(
						/* translators: %s: short technical detail from ZipArchive */
						__( 'Detail: %s', 'mksddn-migrate-content' ),
						$status
					);
				}
				$message .= ' ' . $this->get_disk_hint_message();

				return new WP_Error(
					'mksddn_zip_close',
					$message,
					array(
						'zip_status' => $status,
						'hint'       => $this->get_disk_hint_message(),
					)
				);
			}

			if ( ! is_readable( $target_path ) ) {
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_unreadable',
					__( 'Export archive was created but cannot be read. Check file permissions on the server temp directory.', 'mksddn-migrate-content' )
				);
			}

			$size = filesize( $target_path );
			if ( false === $size || $size <= 0 ) {
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_empty',
					__( 'Export file is empty after writing. This often means the disk or hosting quota is full, or the temp directory is not writable.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			return $target_path;
		} finally {
			ExportMemoryHelper::restore( $original_memory );
		}
	}

	/**
	 * Short hint for disk / quota issues (translated).
	 *
	 * @return string
	 */
	private function get_disk_hint_message(): string {
		return __( 'Check free disk space, hosting quota, and PHP temp directory (sys_temp_dir / upload_tmp_dir).', 'mksddn-migrate-content' );
	}

	/**
	 * Short hint for memory issues (translated).
	 *
	 * @return string
	 */
	private function get_memory_hint_message(): string {
		return __( 'Increase PHP memory_limit and max_execution_time if possible, or export using server tools for very large sites.', 'mksddn-migrate-content' );
	}

	/**
	 * Combined disk + memory hint.
	 *
	 * @return string
	 */
	private function get_disk_memory_hint(): string {
		return $this->get_disk_hint_message() . ' ' . $this->get_memory_hint_message();
	}

	/**
	 * Remove a failed archive file if present.
	 *
	 * @param string $path Absolute path.
	 */
	private function discard_archive( string $path ): void {
		if ( file_exists( $path ) ) {
			FilesystemHelper::delete( $path );
		}
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

		$mu_plugins_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return array(
			$prefix . 'wp-content/uploads'    => $uploads['basedir'],
			$prefix . 'wp-content/plugins'    => dirname( plugin_dir_path( MKSDDN_MC_FILE ) ),
			$prefix . 'wp-content/mu-plugins' => $mu_plugins_dir,
			$prefix . 'wp-content/themes'     => get_theme_root(),
		);
	}
}
