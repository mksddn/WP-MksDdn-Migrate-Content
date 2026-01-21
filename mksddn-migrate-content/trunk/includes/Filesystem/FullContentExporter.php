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
	 * Set cancellation check callback for database exporter.
	 *
	 * @param callable $callback Callback that returns true if export should be cancelled.
	 * @return void
	 */
	public function set_cancellation_check( callable $callback ): void {
		if ( method_exists( $this->db_exporter, 'set_cancellation_check' ) ) {
			$this->db_exporter->set_cancellation_check( $callback );
		}
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

		// Create temporary JSON file for streaming large database export.
		$temp_json_file = wp_tempnam( 'mksddn-db-export-' );
		if ( ! $temp_json_file ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_temp_file', __( 'Unable to create temporary file for database export.', 'mksddn-migrate-content' ) );
		}

		// Export database directly to JSON file to avoid memory issues.
		try {
			$export_result = $this->db_exporter->export_to_file( $temp_json_file );
			if ( is_wp_error( $export_result ) ) {
				$zip->close();
				@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				if ( file_exists( $target_path ) ) {
					@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}
				return $export_result;
			}
		} catch ( \Throwable $e ) {
			$zip->close();
			@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			// Clean up partial file.
			if ( file_exists( $target_path ) ) {
				@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return new WP_Error(
				'mksddn_mc_db_export_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Database export failed: %s', 'mksddn-migrate-content' ),
					$e->getMessage()
				)
			);
		}

		// Validate JSON file exists and is not empty.
		if ( ! file_exists( $temp_json_file ) || filesize( $temp_json_file ) === 0 ) {
			$zip->close();
			@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			// Clean up partial file.
			if ( file_exists( $target_path ) ) {
				@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return new WP_Error( 'mksddn_mc_db_export_empty', __( 'Database export returned empty result.', 'mksddn-migrate-content' ) );
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
			@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			// Clean up partial file.
			if ( file_exists( $target_path ) ) {
				@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode manifest.', 'mksddn-migrate-content' ) );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		
		// Add database JSON file directly to archive (file already contains full payload structure).
		// Note: addFile() copies the file, so we can delete temp file after closing archive.
		if ( ! $zip->addFile( $temp_json_file, 'payload/content.json' ) ) {
			$zip->close();
			@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( file_exists( $target_path ) ) {
				@unlink( $target_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return new WP_Error( 'mksddn_mc_zip_add_file', __( 'Failed to add database export to archive.', 'mksddn-migrate-content' ) );
		}

		$this->append_wp_content( $zip, 'files' );
		$zip->close();

		// Clean up temp file after archive is closed.
		@unlink( $temp_json_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

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

