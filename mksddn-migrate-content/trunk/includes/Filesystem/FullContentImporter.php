<?php
/**
 * Restores full wp-content from archive.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Database\FullDatabaseImporter;
use MksDdn\MigrateContent\Support\DomainReplacer;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use MksDdn\MigrateContent\Users\UserMergeApplier;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports files to uploads/themes/plugins.
 */
class FullContentImporter {

	private FullDatabaseImporter $db_importer;
	private bool $database_imported = false;
	private array $user_merge_summary = array();

	/**
	 * Progress callback.
	 *
	 * @var callable|null
	 */
	private $progress_callback = null;

	/**
	 * Setup importer.
	 *
	 * @param FullDatabaseImporter|null $db_importer Optional database importer.
	 */
	public function __construct( ?FullDatabaseImporter $db_importer = null ) {
		$this->db_importer = $db_importer ?? new FullDatabaseImporter();
	}

	/**
	 * Set progress callback.
	 *
	 * @param callable $callback Callback receiving (int $percent, string $message).
	 * @return void
	 * @since 1.0.0
	 */
	public function set_progress_callback( callable $callback ): void {
		$this->progress_callback = $callback;
	}

	/**
	 * Report progress.
	 *
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 * @since 1.0.0
	 */
	private function report_progress( int $percent, string $message ): void {
		if ( is_callable( $this->progress_callback ) ) {
			call_user_func( $this->progress_callback, $percent, $message );
		}
	}

	/**
	 * Extract allowed paths to wp-content and restore DB if present.
	 *
	 * @param string $archive_path Uploaded archive.
	 * @param SiteUrlGuard|null $url_guard Optional URL guard instance.
	 * @param array $options Import options.
	 * @return true|WP_Error
	 */
	public function import_from( string $archive_path, ?SiteUrlGuard $url_guard = null, array $options = array() ) {
		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.max_execution_time_Disallowed
		}

		// Disable output buffering to prevent timeout issues.
		if ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->report_progress( 5, __( 'Opening archive...', 'mksddn-migrate-content' ) );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to open archive for import.', 'mksddn-migrate-content' ) );
		}
		$url_guard = $url_guard ?? new SiteUrlGuard();

		$this->user_merge_summary = array();

		$this->log( 'Starting database import...' );

		$this->report_progress( 10, __( 'Importing database...', 'mksddn-migrate-content' ) );

		// Flush output to keep connection alive.
		$this->flush_output();

		$db_result = $this->maybe_import_database( $zip, $options );
		if ( is_wp_error( $db_result ) ) {
			$zip->close();
			return $db_result;
		}

		$this->log( 'Starting files extraction...' );

		$this->report_progress( 50, __( 'Extracting files...', 'mksddn-migrate-content' ) );

		$files_result = $this->extract_files( $zip );
		$zip->close();

		$this->log( 'Import completed successfully.' );

		$this->report_progress( 95, __( 'Finalizing...', 'mksddn-migrate-content' ) );

		if ( $this->database_imported ) {
			$url_guard->restore();
		}

		$this->report_progress( 100, __( 'Import complete', 'mksddn-migrate-content' ) );

		return $files_result;
	}

	/**
	 * Return summary for last user merge operation.
	 *
	 * @return array
	 */
	public function get_user_merge_summary(): array {
		return $this->user_merge_summary;
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
		$ignored = array( '.git/', '.svn/', '.hg/', '.DS_Store' );
		foreach ( $ignored as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Import database dump when present in archive payload.
	 *
	 * @param ZipArchive $zip Archive instance.
	 * @param array      $options Import options.
	 * @return true|WP_Error
	 */
	private function maybe_import_database( ZipArchive $zip, array $options = array() ) {
		$payload_json = $zip->getFromName( 'payload/content.json' );
		if ( false === $payload_json ) {
			return true;
		}

		// Calculate required memory: JSON size * 3-5x for decoding and processing.
		$json_size      = strlen( $payload_json );
		$json_size_mb   = round( $json_size / ( 1024 * 1024 ), 2 );
		$required_bytes = $json_size * 5; // Conservative estimate.
		$required_mb    = ceil( $required_bytes / ( 1024 * 1024 ) );

		// Log large file import for monitoring.
		if ( $json_size > 100 * 1024 * 1024 ) { // > 100MB.
			$this->log( sprintf( 'Importing large database file (%s MB). Memory management enabled.', $json_size_mb ) );
		}

		// Check if file is too large to process safely.
		$absolute_max = PluginConfig::max_import_json_size();
		if ( $json_size > $absolute_max ) {
			return new WP_Error(
				'mksddn_mc_file_too_large',
				sprintf(
					/* translators: %1$s: file size in MB, %2$s: maximum size in MB. */
					__( 'Import file is too large (%1$s MB). Maximum supported size is %2$s MB. Please split the export or contact support.', 'mksddn-migrate-content' ),
					$json_size_mb,
					round( $absolute_max / ( 1024 * 1024 ), 0 )
				)
			);
		}

		// Increase memory limit dynamically based on file size.
		$original_limit = ini_get( 'memory_limit' );
		$current_limit  = wp_convert_hr_to_bytes( $original_limit );
		$min_limit      = PluginConfig::min_import_memory_limit();
		$max_limit      = PluginConfig::max_import_memory_limit();
		$target_limit   = max( $min_limit, min( $required_mb * 1024 * 1024, $max_limit ) );

		if ( $current_limit < $target_limit && '-1' !== $original_limit ) {
			$target_limit_mb = ceil( $target_limit / ( 1024 * 1024 ) );
			$set_result      = @ini_set( 'memory_limit', $target_limit_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
			if ( false === $set_result && $json_size > 100 * 1024 * 1024 ) {
				$this->log( sprintf( 'Warning - Unable to increase memory limit to %d MB. Large file import may fail.', $target_limit_mb ) );
			} else {
				$this->log( sprintf( 'Memory limit increased to %d MB for import.', $target_limit_mb ) );
			}
		}

		$this->log( sprintf( 'Starting JSON decode (size: %s MB)...', $json_size_mb ) );

		// Flush before long operation.
		$this->flush_output();

		$data = json_decode( $payload_json, true );

		unset( $payload_json ); // Free memory immediately after decoding.

		$this->log( 'JSON decode completed.' );

		// Flush after long operation.
		$this->flush_output();

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// Restore original memory limit on error.
			if ( isset( $original_limit ) ) {
				@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
			}
			return new WP_Error( 'mksddn_mc_full_import_payload', __( 'Corrupted payload inside archive.', 'mksddn-migrate-content' ) );
		}

		if ( empty( $data['database'] ) || ! is_array( $data['database'] ) ) {
			$this->log( 'No database data found in payload.' );
			$this->restore_memory_limit( $original_limit );
			return true;
		}

		$table_count = isset( $data['database']['tables'] ) ? count( $data['database']['tables'] ) : 0;
		$this->log( sprintf( 'Found %d tables to import. Starting environment replacement...', $table_count ) );

		$current_base  = function_exists( 'home_url' ) ? home_url() : (string) get_option( 'home' );
		$uploads       = wp_upload_dir();
		$current_paths = array(
			'root'    => ABSPATH,
			'content' => WP_CONTENT_DIR,
			'uploads' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
		);

		$replacer = new DomainReplacer();
		$replacer->replace_dump_environment( $data['database'], $current_base, $current_paths );

		$this->log( 'Environment replacement completed.' );

		$user_merge      = isset( $options['user_merge'] ) && is_array( $options['user_merge'] ) ? $options['user_merge'] : array();
		$merge_enabled   = ! empty( $user_merge['enabled'] );
		$merge_plan      = isset( $user_merge['plan'] ) && is_array( $user_merge['plan'] ) ? $user_merge['plan'] : array();
		$user_tables     = isset( $user_merge['tables'] ) && is_array( $user_merge['tables'] ) ? $user_merge['tables'] : array();
		$user_applier    = null;
		$remote_snapshot = array();

		if ( $merge_enabled ) {
			$user_applier    = new UserMergeApplier();
			$remote_snapshot = $user_applier->extract_remote_users( $data['database'], $user_tables );
			$user_applier->strip_user_tables( $data['database'], $user_tables );
		}

		// Import database.
		$result = $this->db_importer->import( $data['database'] );
		unset( $data['database'] ); // Free database data immediately.
		unset( $data ); // Free remaining data.

		if ( true !== $result ) {
			$this->restore_memory_limit( $original_limit );
			return $result;
		}

		$this->database_imported = true;

		if ( $user_applier ) {
			$merge_result = $user_applier->merge( $remote_snapshot['users'], $merge_plan, $remote_snapshot['prefix'] ?? '' );
			unset( $remote_snapshot ); // Free snapshot data.
			if ( is_wp_error( $merge_result ) ) {
				$this->restore_memory_limit( $original_limit );
				return $merge_result;
			}

			$this->user_merge_summary = $merge_result;
		}

		// Force garbage collection after large import.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$this->restore_memory_limit( $original_limit );

		return true;
	}

	/**
	 * Extract filesystem from archive.
	 *
	 * @param ZipArchive $zip Archive instance.
	 * @return true|WP_Error
	 */
	private function extract_files( ZipArchive $zip ) {
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

			$name      = $stat['name'];
			$normalized = $this->normalize_archive_path( $name );

			if ( null === $normalized || $this->should_skip_path( $normalized ) ) {
				continue;
			}

			if ( ! $this->is_allowed_path( $normalized, $allowed_roots ) ) {
				continue;
			}

			$target       = trailingslashit( ABSPATH ) . $normalized;
			$is_directory = '/' === substr( $name, -1 ) || '/' === substr( $normalized, -1 );

			if ( $is_directory ) {
				wp_mkdir_p( $target );
				continue;
			}

			$dir = dirname( $target );
			wp_mkdir_p( $dir );

			$stream = $zip->getStream( $name );
			if ( ! $stream ) {
				/* translators: %s: path inside archive. */
				return new WP_Error( 'mksddn_zip_stream', sprintf( __( 'Unable to read "%s" from archive.', 'mksddn-migrate-content' ), $name ) );
			}

			$write_ok = FilesystemHelper::put_stream( $target, $stream );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with ZipArchive stream

			if ( ! $write_ok ) {
				/* translators: %s: target filesystem path. */
				return new WP_Error( 'mksddn_fs_write', sprintf( __( 'Unable to write "%s". Check permissions.', 'mksddn-migrate-content' ), $target ) );
			}
		}

		return true;
	}

	/**
	 * Normalize archive path by removing wrapper directories.
	 *
	 * @param string $path Raw archive path.
	 * @return string|null
	 */
	private function normalize_archive_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		// Skip manifest/payload/meta files.
		if ( 0 === strpos( $path, 'manifest' ) || 0 === strpos( $path, 'payload/' ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			$path = substr( $path, 6 );
		}

		$path = ltrim( $path, '/' );

		return '' === $path ? null : $path;
	}

	/**
	 * Flush output buffers to keep connection alive during long operations.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function flush_output(): void {
		if ( function_exists( 'ob_flush' ) && ob_get_level() > 0 ) {
			@ob_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		if ( function_exists( 'flush' ) ) {
			@flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Log message if WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.0.0
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging.
			error_log( 'MksDdn Migrate: ' . $message );
		}
	}

	/**
	 * Restore original memory limit safely.
	 *
	 * @param string $original_limit Original memory limit value.
	 * @return void
	 * @since 1.0.0
	 */
	private function restore_memory_limit( string $original_limit ): void {
		// Don't restore if original was unlimited (-1).
		if ( '-1' !== $original_limit && '' !== $original_limit ) {
			@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
		}
	}
}


