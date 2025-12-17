<?php
/**
 * Restores full wp-content from archive.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

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

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log import start.
		error_log( 'MksDdn Migrate: Starting database import...' );

		$this->report_progress( 10, __( 'Importing database...', 'mksddn-migrate-content' ) );

		// Flush output to keep connection alive.
		$this->flush_output();

		$db_result = $this->maybe_import_database( $zip, $options );
		if ( is_wp_error( $db_result ) ) {
			$zip->close();
			return $db_result;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log files extraction start.
		error_log( 'MksDdn Migrate: Starting files extraction...' );

		$this->report_progress( 50, __( 'Extracting files...', 'mksddn-migrate-content' ) );

		$files_result = $this->extract_files( $zip );
		$zip->close();

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log import completion.
		error_log( 'MksDdn Migrate: Import completed successfully.' );

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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Logging large imports for monitoring.
			error_log( sprintf( 'MksDdn Migrate: Importing large database file (%s MB). Memory management enabled.', $json_size_mb ) );
		}

		// Check if file is too large to process safely.
		$absolute_max = 1024 * 1024 * 1024; // 1GB absolute maximum for JSON.
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
		$min_limit      = 1024 * 1024 * 1024; // Minimum 1GB (increased from 512MB).
		$max_limit      = 3072 * 1024 * 1024; // Maximum 3GB to prevent server issues.
		$target_limit   = max( $min_limit, min( $required_mb * 1024 * 1024, $max_limit ) );

		if ( $current_limit < $target_limit ) {
			$target_limit_mb = ceil( $target_limit / ( 1024 * 1024 ) );
			$set_result      = @ini_set( 'memory_limit', $target_limit_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
			if ( false === $set_result && $json_size > 100 * 1024 * 1024 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Warning about memory limit.
				error_log( sprintf( 'MksDdn Migrate: Warning - Unable to increase memory limit to %d MB. Large file import may fail.', $target_limit_mb ) );
			} else {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log memory increase.
				error_log( sprintf( 'MksDdn Migrate: Memory limit increased to %d MB for import.', $target_limit_mb ) );
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log JSON decoding start.
		error_log( sprintf( 'MksDdn Migrate: Starting JSON decode (size: %s MB)...', $json_size_mb ) );

		// Flush before long operation.
		$this->flush_output();

		// For very large files (>100MB), use streaming approach if possible.
		$use_streaming = $json_size > 100 * 1024 * 1024 && function_exists( 'json_stream_decode' );

		if ( $use_streaming ) {
			$data = $this->decode_json_streaming( $payload_json );
		} else {
			$data = json_decode( $payload_json, true );
		}

		unset( $payload_json ); // Free memory immediately after decoding.

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log JSON decoding completion.
		error_log( 'MksDdn Migrate: JSON decode completed.' );

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
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log no database.
			error_log( 'MksDdn Migrate: No database data found in payload.' );
			// Restore original memory limit if no database to import.
			if ( isset( $original_limit ) ) {
				@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
			}
			return true;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log database processing start.
		$table_count = isset( $data['database']['tables'] ) ? count( $data['database']['tables'] ) : 0;
		error_log( sprintf( 'MksDdn Migrate: Found %d tables to import. Starting environment replacement...', $table_count ) );

		$current_base  = function_exists( 'home_url' ) ? home_url() : (string) get_option( 'home' );
		$uploads       = wp_upload_dir();
		$current_paths = array(
			'root'    => ABSPATH,
			'content' => WP_CONTENT_DIR,
			'uploads' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
		);

		$replacer = new DomainReplacer();
		$replacer->replace_dump_environment( $data['database'], $current_base, $current_paths );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log environment replacement completion.
		error_log( 'MksDdn Migrate: Environment replacement completed.' );

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

		// Process database import table by table to save memory.
		$result = $this->import_database_incremental( $data['database'] );
		unset( $data['database'] ); // Free database data immediately.
		unset( $data ); // Free remaining data.

		if ( true !== $result ) {
			// Restore original memory limit on error.
			if ( isset( $original_limit ) ) {
				@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
			}
			return $result;
		}

		$this->database_imported = true;

		if ( $user_applier ) {
			$merge_result = $user_applier->merge( $remote_snapshot['users'], $merge_plan, $remote_snapshot['prefix'] ?? '' );
			unset( $remote_snapshot ); // Free snapshot data.
			if ( is_wp_error( $merge_result ) ) {
				// Restore original memory limit on error.
				if ( isset( $original_limit ) ) {
					@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
				}
				return $merge_result;
			}

			$this->user_merge_summary = $merge_result;
		}

		// Force garbage collection after large import.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Restore original memory limit.
		if ( isset( $original_limit ) ) {
			@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
		}

		return true;
	}

	/**
	 * Import database incrementally, processing one table at a time to save memory.
	 *
	 * @param array $database Database dump data.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function import_database_incremental( array $database ): bool|\WP_Error {
		if ( empty( $database['tables'] ) || ! is_array( $database['tables'] ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log no tables.
			error_log( 'MksDdn Migrate: No tables to import.' );
			return true;
		}

		$table_count = count( $database['tables'] );
		$processed   = 0;

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log import start.
		error_log( sprintf( 'MksDdn Migrate: Starting incremental import of %d tables...', $table_count ) );

		// Process each table separately to free memory after each one.
		foreach ( $database['tables'] as $table_name => $table_data ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log table processing.
			error_log( sprintf( 'MksDdn Migrate: Processing table %d/%d: %s', $processed + 1, $table_count, $table_name ) );

			// Report progress: 10-50% range for database import.
			$db_progress = 10 + (int) ( ( $processed / $table_count ) * 40 );
			$this->report_progress(
				$db_progress,
				/* translators: 1: current table number, 2: total tables */
				sprintf( __( 'Importing table %1$d of %2$d...', 'mksddn-migrate-content' ), $processed + 1, $table_count )
			);

			// Flush periodically to keep connection alive.
			if ( 0 === $processed % 5 ) {
				$this->flush_output();
			}
			// Create a minimal dump structure with only one table.
			$single_table_dump = array(
				'tables' => array(
					$table_name => $table_data,
				),
			);

			// Import this single table.
			$result = $this->db_importer->import( $single_table_dump );

			// Free memory immediately after processing.
			unset( $single_table_dump, $database['tables'][ $table_name ] );

			if ( true !== $result ) {
				unset( $database ); // Free remaining data on error.
				return $result;
			}

			++$processed;

			// Force garbage collection every 5 tables.
			if ( 0 === $processed % 5 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Log progress for large imports.
			if ( $table_count > 10 && 0 === $processed % 10 ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Progress logging.
				error_log( sprintf( 'MksDdn Migrate: Imported %d/%d tables', $processed, $table_count ) );
			}
		}

		unset( $database ); // Free remaining data.

		return true;
	}

	/**
	 * Decode JSON with streaming approach for very large files.
	 * Falls back to standard json_decode if streaming is not available.
	 *
	 * @param string $json JSON string to decode.
	 * @return array|null Decoded data or empty array on failure.
	 * @since 1.0.0
	 */
	private function decode_json_streaming( string $json ): ?array {
		// PHP doesn't have built-in streaming JSON decoder, but we can optimize
		// by processing in chunks if the structure allows.
		// For now, fall back to standard decode but with better error handling.
		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			// Try to get more memory if decode failed.
			$current_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
			$new_limit     = $current_limit * 2;
			$max_limit     = 4096 * 1024 * 1024; // 4GB absolute maximum.

			if ( $new_limit <= $max_limit ) {
				@ini_set( 'memory_limit', ceil( $new_limit / ( 1024 * 1024 ) ) . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.memory_limit_Disallowed
				$data = json_decode( $json, true );
			}
		}

		return $data;
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

}


