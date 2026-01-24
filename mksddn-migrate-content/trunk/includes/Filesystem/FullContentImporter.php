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
		// Disable non-critical hooks during import to reduce memory and CPU load.
		$this->disable_non_critical_hooks();

		$result = $this->perform_import( $archive_path, $url_guard, $options );

		// Re-enable hooks.
		$this->enable_hooks();

		return $result;
	}

	/**
	 * Disable non-critical WordPress hooks during import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function disable_non_critical_hooks(): void {
		// Disable auto-save, revisions, and other non-critical functionality.
		wp_suspend_cache_addition( true );
		
		// Disable search indexing and cron during import.
		if ( defined( 'DOING_AUTOSAVE' ) ) {
			define( 'DOING_AUTOSAVE', true );
		}
		
		// Stop WP-Cron from running.
		add_filter( 'pre_cron_timeout', '__return_zero' );
		
		// Prevent transients from being set.
		add_filter( 'transient_timeout_limit', '__return_zero' );
	}

	/**
	 * Re-enable hooks after import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function enable_hooks(): void {
		wp_suspend_cache_addition( false );
		remove_filter( 'pre_cron_timeout', '__return_zero' );
		remove_filter( 'transient_timeout_limit', '__return_zero' );
	}

	/**
	 * Perform the actual import.
	 *
	 * @param string          $archive_path Path to archive.
	 * @param SiteUrlGuard|null $url_guard   URL guard instance.
	 * @param array           $options      Import options.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function perform_import( string $archive_path, ?SiteUrlGuard $url_guard = null, array $options = array() ) {
		$this->log( sprintf( 'import_from() called with archive_path: %s', $archive_path ) );
		$this->log( sprintf( 'Options: %s', wp_json_encode( $options ) ) );

		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Disable output buffering to prevent timeout issues.
		if ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->report_progress( 5, __( 'Opening archive...', 'mksddn-migrate-content' ) );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			$this->log( sprintf( 'Failed to open archive: %s', $archive_path ) );
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to open archive for import.', 'mksddn-migrate-content' ) );
		}
		$this->log( 'Archive opened successfully.' );
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
		$this->log( 'Starting maybe_import_database()...' );
		
		// Check JSON file size before reading to manage memory better.
		$json_stat = $zip->statName( 'payload/content.json' );
		$json_file_size = false !== $json_stat && isset( $json_stat['size'] ) ? (int) $json_stat['size'] : 0;
		
		if ( $json_file_size === 0 ) {
			$this->log( 'No payload/content.json found in archive. Skipping database import.' );
			return true;
		}
		
		$json_file_size_mb = round( $json_file_size / ( 1024 * 1024 ), 2 );
		$json_file_size_gb = round( $json_file_size / ( 1024 * 1024 * 1024 ), 2 );
		$this->log( sprintf( 'Found payload/content.json, size: %d bytes (%s MB, %s GB)', $json_file_size, $json_file_size_mb, $json_file_size_gb ) );
		
		// Calculate required memory BEFORE reading: JSON size * 7 (for reading + decoding + processing).
		$required_bytes = $json_file_size * 7; // Conservative estimate: read (1x) + decode (5x) + buffer (1x).
		$required_mb = ceil( $required_bytes / ( 1024 * 1024 ) );
		$required_gb = round( $required_bytes / ( 1024 * 1024 * 1024 ), 2 );
		
		// Increase memory limit BEFORE reading JSON to prevent exhaustion.
		$original_limit = ini_get( 'memory_limit' );
		$current_limit_bytes = wp_convert_hr_to_bytes( $original_limit );
		$min_limit = PluginConfig::min_import_memory_limit();
		$max_limit = PluginConfig::max_import_memory_limit();
		$target_limit_bytes = max( $min_limit, min( $required_bytes, $max_limit ) );
		$target_limit_mb = ceil( $target_limit_bytes / ( 1024 * 1024 ) );
		
		if ( $current_limit_bytes < $target_limit_bytes && '-1' !== $original_limit ) {
			$this->log( sprintf( 'Increasing memory limit from %s (%d MB) to %d MB BEFORE reading JSON (required: %s GB for %s MB JSON)', $original_limit, round( $current_limit_bytes / ( 1024 * 1024 ) ), $target_limit_mb, $required_gb, $json_file_size_mb ) );
			$set_result = @ini_set( 'memory_limit', $target_limit_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			
			// Verify the limit was actually increased.
			$new_limit = ini_get( 'memory_limit' );
			$new_limit_bytes = wp_convert_hr_to_bytes( $new_limit );
			
			if ( false === $set_result || $new_limit_bytes < $target_limit_bytes ) {
				$this->log( sprintf( 'CRITICAL ERROR - Unable to increase memory limit to %d MB (current: %s, %d MB). JSON reading will likely fail!', $target_limit_mb, $new_limit, round( $new_limit_bytes / ( 1024 * 1024 ) ) ) );
				if ( $json_file_size > 100 * 1024 * 1024 ) {
					$this->log( sprintf( 'CRITICAL: Large JSON file (%s MB) requires at least %d MB memory. You MUST increase PHP memory_limit in php.ini or wp-config.php to at least %d MB. Current limit (%s) is insufficient.', $json_file_size_mb, $target_limit_mb, $target_limit_mb, $new_limit ) );
					// Don't proceed if we can't increase memory limit for large files.
					return new WP_Error(
						'mksddn_mc_insufficient_memory',
						sprintf(
							/* translators: %1$s: JSON size in MB, %2$s: required memory in MB, %3$s: current limit */
							__( 'Insufficient memory: JSON file is %1$s MB and requires at least %2$s MB, but current limit is %3$s. Please increase PHP memory_limit in php.ini or wp-config.php.', 'mksddn-migrate-content' ),
							$json_file_size_mb,
							$target_limit_mb,
							$new_limit
						)
					);
				}
			} else {
				$this->log( sprintf( 'Memory limit successfully increased to %d MB (%s GB) before reading JSON.', $target_limit_mb, round( $target_limit_mb / 1024, 2 ) ) );
			}
		} else {
			if ( '-1' === $original_limit ) {
				$this->log( 'Memory limit is unlimited (-1). Proceeding with JSON read...' );
			} else {
				$this->log( sprintf( 'Memory limit is sufficient: %s (%d MB, required: %d MB)', $original_limit, round( $current_limit_bytes / ( 1024 * 1024 ) ), $target_limit_mb ) );
			}
		}
		
		// Use stream for better memory efficiency with large files.
		$payload_json = false;
		$stream = $zip->getStream( 'payload/content.json' );
		if ( false !== $stream ) {
			$this->log( 'Reading JSON payload via stream...' );
			$payload_json = stream_get_contents( $stream );
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		
		// Fallback to getFromName if stream fails.
		if ( false === $payload_json ) {
			$this->log( 'Stream read failed, falling back to getFromName...' );
			$payload_json = $zip->getFromName( 'payload/content.json' );
		}
		
		if ( false === $payload_json ) {
			$this->log( 'Failed to read payload/content.json from archive.' );
			$this->restore_memory_limit( $original_limit );
			return new WP_Error( 'mksddn_mc_payload_read_failed', __( 'Unable to read database payload from archive.', 'mksddn-migrate-content' ) );
		}
		
		$actual_json_size = strlen( $payload_json );
		if ( $actual_json_size !== $json_file_size ) {
			$this->log( sprintf( 'Warning: JSON size mismatch. Expected: %d bytes, Got: %d bytes', $json_file_size, $actual_json_size ) );
		}

		// JSON is already loaded, calculate sizes for logging and validation.
		$json_size      = strlen( $payload_json );
		$json_size_mb   = round( $json_size / ( 1024 * 1024 ), 2 );
		$json_size_gb   = round( $json_size / ( 1024 * 1024 * 1024 ), 2 );
		
		// Log large file import for monitoring.
		if ( $json_size > 100 * 1024 * 1024 ) { // > 100MB.
			if ( $json_size > 1024 * 1024 * 1024 ) { // > 1GB.
				$this->log( sprintf( 'Importing very large database file (%s GB). Memory management enabled.', $json_size_gb ) );
			} else {
				$this->log( sprintf( 'Importing large database file (%s MB). Memory management enabled.', $json_size_mb ) );
			}
		}

		// Check if file is too large to process safely.
		$absolute_max = PluginConfig::max_import_json_size();
		if ( $json_size > $absolute_max ) {
			unset( $payload_json );
			$this->restore_memory_limit( $original_limit );
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
		
		// Memory limit was already increased before reading JSON, but verify it's still sufficient for decoding.
		$current_limit_check = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$decoding_required = $json_size * 5; // JSON decode requires ~5x the size.
		if ( $current_limit_check < $decoding_required ) {
			$decoding_required_mb = ceil( $decoding_required / ( 1024 * 1024 ) );
			$this->log( sprintf( 'Warning: Current memory limit may be insufficient for JSON decoding. Required: %d MB', $decoding_required_mb ) );
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
				@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
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
		if ( $table_count > 0 ) {
			$this->log( sprintf( 'Table names: %s', implode( ', ', array_keys( $data['database']['tables'] ) ) ) );
		}

		$current_base  = function_exists( 'home_url' ) ? home_url() : (string) get_option( 'home' );
		$uploads       = wp_upload_dir();
		$current_paths = array(
			'root'    => function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH,
			'content' => WP_CONTENT_DIR,
			'uploads' => $uploads['basedir'],
		);

		$replacer = new DomainReplacer();
		$replacer->replace_dump_environment( $data['database'], $current_base, $current_paths );

		$this->log( 'Environment replacement completed.' );

		// Free memory after environment replacement.
		unset( $replacer );
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$user_merge      = isset( $options['user_merge'] ) && is_array( $options['user_merge'] ) ? $options['user_merge'] : array();
		$merge_enabled   = ! empty( $user_merge['enabled'] );
		$merge_plan      = isset( $user_merge['plan'] ) && is_array( $user_merge['plan'] ) ? $user_merge['plan'] : array();
		$user_tables     = isset( $user_merge['tables'] ) && is_array( $user_merge['tables'] ) ? $user_merge['tables'] : array();
		$user_applier    = null;
		$remote_snapshot = array();
		$needs_merge     = false;

		$this->log( sprintf( 'User merge enabled: %s, plan count: %d', $merge_enabled ? 'yes' : 'no', count( $merge_plan ) ) );

		// Always initialize user applier to ensure user tables are handled correctly.
		$user_applier = new UserMergeApplier();

		if ( $merge_enabled ) {
			// Check if at least one user is selected for import.
			$has_selected_users = false;
			foreach ( $merge_plan as $action ) {
				if ( ! empty( $action['import'] ) ) {
					$has_selected_users = true;
					break;
				}
			}

			$this->log( sprintf( 'Has selected users: %s', $has_selected_users ? 'yes' : 'no' ) );

			if ( $has_selected_users ) {
				// Extract and strip user tables for selective merge.
				$this->log( 'Extracting remote users for selective merge...' );
				$remote_snapshot = $user_applier->extract_remote_users( $data['database'], $user_tables );
				$user_applier->strip_user_tables( $data['database'], $user_tables );
				$needs_merge = true; // Keep user_applier for merge later.
			} else {
				// No users selected - remove user tables from dump to preserve current users.
				$this->log( 'No users selected for import - removing user tables from dump to preserve current users' );
				$user_applier->strip_user_tables( $data['database'], $user_tables );
				$merge_enabled = false; // Disable merge since no users to process.
			}
		} else {
			// User merge disabled - always remove user tables to preserve current users and their capabilities.
			$this->log( 'User merge disabled - removing user tables from dump to preserve current users and their capabilities' );
			$user_applier->strip_user_tables( $data['database'], $user_tables );
		}

		// Free memory after stripping user tables, but keep user_applier if merge is needed.
		if ( ! $needs_merge ) {
			unset( $user_applier );
		}
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Check if there are any tables left to import after stripping user tables.
		$remaining_tables = isset( $data['database']['tables'] ) && is_array( $data['database']['tables'] ) ? $data['database']['tables'] : array();
		$remaining_count = count( $remaining_tables );

		$this->log( sprintf( 'Tables remaining after stripping user tables: %d', $remaining_count ) );
		if ( $remaining_count > 0 ) {
			$this->log( sprintf( 'Remaining table names: %s', implode( ', ', array_keys( $remaining_tables ) ) ) );
		}

		if ( $remaining_count === 0 ) {
			$this->log( 'No tables to import after removing user tables. Skipping database import.' );
			unset( $data['database'] ); // Free database data immediately.
			unset( $data ); // Free remaining data.
			$this->restore_memory_limit( $original_limit );
			// Mark as imported even if no tables to import (user tables were intentionally excluded).
			$this->database_imported = true;
			// Return success even if no tables to import (user tables were intentionally excluded).
			return true;
		}

		$this->log( sprintf( 'Importing %d tables after removing user tables...', $remaining_count ) );

		// Import database.
		$result = $this->db_importer->import( $data['database'] );
		unset( $data['database'] ); // Free database data immediately.
		unset( $data ); // Free remaining data.

		if ( true !== $result ) {
			$this->restore_memory_limit( $original_limit );
			return $result;
		}

		$this->database_imported = true;

		// Only merge users if we actually extracted them (has_selected_users was true).
		if ( $needs_merge && isset( $user_applier ) && $merge_enabled && ! empty( $remote_snapshot['users'] ) ) {
			$merge_result = $user_applier->merge( $remote_snapshot['users'], $merge_plan, $remote_snapshot['prefix'] ?? '' );
			unset( $remote_snapshot, $user_applier ); // Free snapshot data and user applier.
			if ( is_wp_error( $merge_result ) ) {
				$this->restore_memory_limit( $original_limit );
				return $merge_result;
			}

			$this->user_merge_summary = $merge_result;
		} elseif ( isset( $user_applier ) ) {
			// Free user_applier if not used for merge.
			unset( $user_applier );
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

			$root_path   = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
			$target       = trailingslashit( $root_path ) . $normalized;
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
		// Always log to help debug import issues.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging.
		error_log( 'MksDdn Migrate: ' . $message );
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
			@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
	}
}


