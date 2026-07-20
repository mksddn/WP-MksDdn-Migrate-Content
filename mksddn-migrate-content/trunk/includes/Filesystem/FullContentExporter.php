<?php
/**
 * @file: FullContentExporter.php
 * @description: Builds full-site .wpbkp ZIP with streamed database JSON and wp-content trees
 * @dependencies: FullDatabaseExporter, ContentCollector, ExportMemoryHelper, ExportPreflight, FilesystemHelper, ZipArchive
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
use MksDdn\MigrateContent\Support\ExportPreflight;
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
	private ExportPreflight $preflight;

	/**
	 * Setup exporter.
	 *
	 * @param FullDatabaseExporter|null $db_exporter Optional DB exporter.
	 * @param ContentCollector|null     $collector   Optional collector.
	 * @param ExportPreflight|null      $preflight   Optional preflight checker.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null, ?ExportPreflight $preflight = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
		$this->preflight   = $preflight ?? new ExportPreflight();
	}

	/**
	 * Build archive with uploads/plugins/themes and DB dump.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error Path on success.
	 */
	public function export_to( string $target_path ) {
		$original_memory = ExportMemoryHelper::raise_for_export();
		$payload_temp    = '';

		try {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			$preflight = $this->preflight->validate_full_export();
			if ( is_wp_error( $preflight ) ) {
				return $preflight;
			}

			$dir_result = FilesystemHelper::ensure_directory( $target_path );
			if ( is_wp_error( $dir_result ) ) {
				return new WP_Error(
					'mksddn_zip_dir',
					__( 'Unable to create export directory.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			$payload_temp = $this->build_payload_file();
			if ( is_wp_error( $payload_temp ) ) {
				return $payload_temp;
			}

			$zip = new ZipArchive();
			if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_open',
					__( 'Unable to create archive for full export.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			$manifest = array(
				'format_version' => 1,
				'plugin_version' => MKSDDN_MC_VERSION,
				'type'           => 'full-site',
				'created_at_gmt' => gmdate( 'c' ),
			);

			$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			if ( false === $manifest_json ) {
				$zip->close();
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_mc_full_export_payload',
					__( 'Failed to encode full-site manifest.', 'mksddn-migrate-content' ),
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

			if ( ! $zip->addFile( $payload_temp, 'payload/content.json' ) ) {
				$zip->close();
				$this->discard_archive( $target_path );
				return new WP_Error(
					'mksddn_zip_add_payload',
					__( 'Could not write database payload into the export archive.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_memory_hint() )
				);
			}

			$content_result = $this->append_wp_content( $zip, 'files' );
			if ( is_wp_error( $content_result ) ) {
				$zip->close();
				$this->discard_archive( $target_path );
				$content_result->add_data(
					array(
						'status' => 500,
						'hint'   => $this->get_source_file_hint_message(),
					)
				);
				return $content_result;
			}

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
					__( 'Export file is empty after writing.', 'mksddn-migrate-content' ),
					array( 'hint' => $this->get_disk_hint_message() )
				);
			}

			return $target_path;
		} finally {
			if ( is_string( $payload_temp ) && '' !== $payload_temp && file_exists( $payload_temp ) ) {
				FilesystemHelper::delete( $payload_temp );
			}
			ExportMemoryHelper::restore( $original_memory );
		}
	}

	/**
	 * Stream payload JSON to a temp file: {"type":"full-site","database":{...}}.
	 *
	 * @return string|WP_Error Absolute path to temp payload file.
	 */
	private function build_payload_file() {
		$payload_temp = \wp_tempnam( 'mksddn-full-payload-' );
		if ( ! $payload_temp ) {
			return new WP_Error(
				'mksddn_mc_export_temp',
				__( 'Unable to create temporary file for export payload.', 'mksddn-migrate-content' ),
				array( 'hint' => $this->get_disk_hint_message() )
			);
		}

		$handle = fopen( $payload_temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming payload requires native handle
		if ( ! $handle ) {
			FilesystemHelper::delete( $payload_temp );
			return new WP_Error(
				'mksddn_mc_export_temp',
				__( 'Unable to open temporary file for export payload.', 'mksddn-migrate-content' ),
				array( 'hint' => $this->get_disk_hint_message() )
			);
		}

		$prefix_ok = fwrite( $handle, '{"type":"full-site","database":' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === $prefix_ok ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			FilesystemHelper::delete( $payload_temp );
			return new WP_Error(
				'mksddn_mc_export_write',
				__( 'Failed to write export payload header.', 'mksddn-migrate-content' ),
				array( 'hint' => $this->get_disk_hint_message() )
			);
		}

		$db_result = $this->db_exporter->stream_to( $handle );
		if ( is_wp_error( $db_result ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			FilesystemHelper::delete( $payload_temp );
			return $db_result;
		}

		$suffix_ok = fwrite( $handle, '}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $suffix_ok ) {
			FilesystemHelper::delete( $payload_temp );
			return new WP_Error(
				'mksddn_mc_export_write',
				__( 'Failed to finalize export payload file.', 'mksddn-migrate-content' ),
				array( 'hint' => $this->get_disk_hint_message() )
			);
		}

		if ( ! is_readable( $payload_temp ) || (int) filesize( $payload_temp ) <= 0 ) {
			FilesystemHelper::delete( $payload_temp );
			return new WP_Error(
				'mksddn_mc_export_payload_empty',
				__( 'Export payload file is empty after writing.', 'mksddn-migrate-content' ),
				array( 'hint' => $this->get_disk_hint_message() )
			);
		}

		return $payload_temp;
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
		return __( 'Full-site export streams the database to disk in chunks. If it still fails, free RAM on the server, avoid multi-GB memory_limit on small VPS hosts, or export from staging / WP-CLI.', 'mksddn-migrate-content' );
	}

	/**
	 * Short hint for source file access issues (translated).
	 *
	 * @return string
	 */
	private function get_source_file_hint_message(): string {
		return __( 'Check file permissions and make sure files are not being changed while the export is running.', 'mksddn-migrate-content' );
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
	 * @return array<string,int>|WP_Error Archive write stats or error.
	 */
	private function append_wp_content( ZipArchive $zip, string $base_prefix = '' ): array|WP_Error {
		return $this->collector->append_directories( $zip, $this->get_wp_content_paths( $base_prefix ) );
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
