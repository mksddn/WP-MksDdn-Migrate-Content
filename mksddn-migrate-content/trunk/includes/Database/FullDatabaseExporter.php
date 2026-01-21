<?php
/**
 * @file: FullDatabaseExporter.php
 * @description: Dumps WordPress database tables into an array structure
 * @dependencies: wpdb
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Database;

use MksDdn\MigrateContent\Config\PluginConfig;
use wpdb;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export tables that belong to the current installation.
 *
 * @since 1.0.0
 */
class FullDatabaseExporter {

	/**
	 * Callback function to check if export should be cancelled.
	 *
	 * @var callable|null
	 */
	private $cancellation_check = null;

	/**
	 * Set cancellation check callback.
	 *
	 * @param callable $callback Callback that returns true if export should be cancelled.
	 * @return void
	 */
	public function set_cancellation_check( callable $callback ): void {
		$this->cancellation_check = $callback;
	}

	/**
	 * Check if export should be cancelled.
	 *
	 * @return bool True if export should be cancelled.
	 */
	private function is_cancelled(): bool {
		if ( null === $this->cancellation_check ) {
			// Log if callback is not set (for debugging).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				static $logged = false;
				if ( ! $logged ) {
					$this->log( 'Export cancellation check callback is not set' );
					$logged = true;
				}
			}
			return false;
		}

		try {
			$result = (bool) call_user_func( $this->cancellation_check );
			
			// Log cancellation check result for debugging.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				static $check_count = 0;
				$check_count++;
				if ( $result || 0 === $check_count % 1000 ) {
					$this->log( sprintf( 'Export cancellation check #%d returned: %s', $check_count, $result ? 'CANCELLED' : 'continue' ) );
				}
			}
			
			return $result;
		} catch ( \Throwable $e ) {
			// If callback fails, log error but don't cancel export.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->log( sprintf( 'Export cancellation check failed: %s', $e->getMessage() ) );
			}
			return false;
		}
	}

	/**
	 * Export database directly to JSON file (memory efficient for large databases).
	 *
	 * @param string $file_path Path to JSON file.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function export_to_file( string $file_path ) {
		global $wpdb;

		// Disable time limit for database export.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Try to increase memory limit for large exports.
		$original_limit = ini_get( 'memory_limit' );
		$current_limit  = wp_convert_hr_to_bytes( $original_limit );
		$min_limit      = 512 * 1024 * 1024; // 512MB minimum.

		if ( $current_limit < $min_limit && '-1' !== $original_limit ) {
			$target_limit_mb = 512;
			$set_result      = @ini_set( 'memory_limit', $target_limit_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			if ( false !== $set_result ) {
				$this->log( sprintf( 'Memory limit increased to %d MB for export', $target_limit_mb ) );
			}
		}

		$tables = $this->detect_tables( $wpdb );
		$uploads = wp_upload_dir();
		
		// Open file for writing.
		$handle = fopen( $file_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large data requires native handle
		if ( ! $handle ) {
			return new WP_Error( 'mksddn_mc_file_open', __( 'Unable to open file for database export.', 'mksddn-migrate-content' ) );
		}

		// Write opening JSON structure - full payload format expected by importer.
		$write_result = fwrite( $handle, '{"type":"full-site","database":{' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === $write_result ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
		}

		$header_parts = array(
			'"site_url":' . wp_json_encode( \get_option( 'siteurl' ) ) . ',',
			'"home_url":' . wp_json_encode( \home_url() ) . ',',
			'"table_prefix":' . wp_json_encode( $wpdb->prefix ) . ',',
			'"paths":{',
			'"root":' . wp_json_encode( function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH ) . ',',
			'"content":' . wp_json_encode( WP_CONTENT_DIR ) . ',',
			'"uploads":' . wp_json_encode( $uploads['basedir'] ),
			'},"tables":{',
		);

		foreach ( $header_parts as $part ) {
			if ( false === fwrite( $handle, $part ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
			}
		}

		$table_count = count( $tables );
		$processed   = 0;
		$first_table = true;

		foreach ( $tables as $table_name ) {
			// Check if export was cancelled.
			if ( $this->is_cancelled() ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return new WP_Error( 'mksddn_mc_export_cancelled', __( 'Export was cancelled.', 'mksddn-migrate-content' ) );
			}

			++$processed;
			$this->log( sprintf( 'Exporting table %d/%d: %s', $processed, $table_count, $table_name ) );

			// Add comma separator between tables.
			if ( ! $first_table ) {
				if ( false === fwrite( $handle, ',' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
				}
			}
			$first_table = false;

			// Write table name key.
			$table_header = wp_json_encode( $table_name ) . ':{';
			if ( false === fwrite( $handle, $table_header ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
			}

			// Get table schema.
			$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema export for backup; table name sanitized
			$schema     = is_array( $schema_row ) && isset( $schema_row[1] ) ? (string) $schema_row[1] : '';

			// Write schema.
			$schema_header = '"schema":' . wp_json_encode( $schema ) . ',"rows":[';
			if ( false === fwrite( $handle, $schema_header ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
			}

			// Get row count.
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- count query for memory optimization
			
			$large_threshold = PluginConfig::large_table_threshold();
			$base_chunk_size = PluginConfig::db_row_chunk_size();

			// For very large tables, use smaller chunks (check larger tables first).
			$chunk_size = $base_chunk_size;
			if ( $row_count > 100000 ) {
				// For tables with more than 100k rows, use even smaller chunks.
				$chunk_size = max( 250, intval( $base_chunk_size / 4 ) );
			} elseif ( $row_count > 50000 ) {
				// For tables with more than 50k rows, use smaller chunks.
				$chunk_size = max( 500, intval( $base_chunk_size / 2 ) );
			}

			// Export table rows directly to file.
			if ( $row_count > $large_threshold ) {
				$this->log( sprintf( 'Large table detected (%d rows), exporting in chunks of %d', $row_count, $chunk_size ) );
				$result = $this->export_table_to_file( $wpdb, $table_name, $chunk_size, $row_count, $handle );
				if ( is_wp_error( $result ) ) {
					fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
					return $result;
				}
			} else {
				// Small table - load and write all at once.
				$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- export requires full table scan; $table_name is sanitized prefix match
				if ( null !== $rows && ! empty( $rows ) ) {
					$first_row = true;
					foreach ( $rows as $row ) {
						if ( ! $first_row ) {
							if ( false === fwrite( $handle, ',' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
								fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
								@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
								return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
							}
						}
						$row_json = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
						if ( false === $row_json || false === fwrite( $handle, $row_json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
							fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
							@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
							return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
						}
						$first_row = false;
					}
					unset( $rows );
				}
			}

			// Close rows array and table object.
			if ( false === fwrite( $handle, ']}' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
			}

			// Force garbage collection after every 5 tables.
			if ( 0 === $processed % 5 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Close JSON structure.
		if ( false === fwrite( $handle, '}}}' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
		}

		if ( false === fclose( $handle ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error( 'mksddn_mc_file_close', __( 'Failed to close export file.', 'mksddn-migrate-content' ) );
		}

		// Restore original memory limit.
		if ( isset( $original_limit ) && '-1' !== $original_limit ) {
			@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		return true;
	}

	/**
	 * Export table rows directly to file in chunks (memory efficient).
	 *
	 * @param wpdb    $wpdb       Database object.
	 * @param string  $table_name Table name.
	 * @param int     $chunk_size Chunk size.
	 * @param int     $row_count  Total row count.
	 * @param resource $file_handle File handle.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function export_table_to_file( wpdb $wpdb, string $table_name, int $chunk_size, int $row_count, $file_handle ) {
		$offset = 0;
		$first_row = true;

		// Handle empty table.
		if ( 0 === $row_count ) {
			return true;
		}

		// Get primary key or first column for ordering.
		$order_column = $this->get_primary_key_or_first_column( $wpdb, $table_name );

		// Validate column name for security.
		if ( ! $this->is_valid_column_name( $order_column ) ) {
			// Fallback: use LIMIT without ORDER BY.
			$this->log( sprintf( 'Warning: Could not determine order column for table %s, using LIMIT without ORDER BY', $table_name ) );
			return $this->export_table_to_file_without_order( $wpdb, $table_name, $chunk_size, $row_count, $file_handle );
		}

		while ( $offset < $row_count ) {
			// Check if export was cancelled before processing each chunk.
			if ( $this->is_cancelled() ) {
				$this->log( sprintf( 'Export cancelled at %d/%d rows from table %s', $offset, $row_count, $table_name ) );
				return new WP_Error( 'mksddn_mc_export_cancelled', __( 'Export was cancelled.', 'mksddn-migrate-content' ) );
			}

			// Use LIMIT/OFFSET for chunking.
			$escaped_table = esc_sql( $table_name );
			$escaped_column = esc_sql( $order_column );
			$query = "SELECT * FROM `{$escaped_table}` ORDER BY `{$escaped_column}` LIMIT %d OFFSET %d";
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$query = $wpdb->prepare( $query, $chunk_size, $offset );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$chunk = $wpdb->get_results( $query, ARRAY_A );

			if ( null === $chunk || empty( $chunk ) ) {
				break;
			}

			// Write chunk directly to file.
			foreach ( $chunk as $row ) {
				if ( ! $first_row ) {
					if ( false === fwrite( $file_handle, ',' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
					}
				}
				$row_json = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
				if ( false === $row_json || false === fwrite( $file_handle, $row_json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
				}
				$first_row = false;
			}

			// Free chunk memory immediately.
			unset( $chunk );

			$offset += $chunk_size;

			// Log progress for very large tables (every 10k rows).
			if ( $row_count > 10000 && 0 === $offset % 10000 ) {
				$this->log( sprintf( 'Exported %d/%d rows from table %s', min( $offset, $row_count ), $row_count, $table_name ) );
			}

			// Force garbage collection after each chunk for large tables.
			if ( $row_count > 5000 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return true;
	}

	/**
	 * Export table to file without ORDER BY (fallback).
	 *
	 * @param wpdb     $wpdb        Database object.
	 * @param string   $table_name  Table name.
	 * @param int      $chunk_size  Chunk size.
	 * @param int      $row_count   Total row count.
	 * @param resource $file_handle File handle.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	private function export_table_to_file_without_order( wpdb $wpdb, string $table_name, int $chunk_size, int $row_count, $file_handle ) {
		$offset = 0;
		$first_row = true;

		while ( $offset < $row_count ) {
			// Check if export was cancelled.
			if ( $this->is_cancelled() ) {
				return new WP_Error( 'mksddn_mc_export_cancelled', __( 'Export was cancelled.', 'mksddn-migrate-content' ) );
			}

			$escaped_table = esc_sql( $table_name );
			$query = "SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d";
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$query = $wpdb->prepare( $query, $chunk_size, $offset );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$chunk = $wpdb->get_results( $query, ARRAY_A );

			if ( null === $chunk || empty( $chunk ) ) {
				break;
			}

			// Write chunk directly to file.
			foreach ( $chunk as $row ) {
				if ( ! $first_row ) {
					if ( false === fwrite( $file_handle, ',' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
						return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
					}
				}
				$row_json = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
				if ( false === $row_json || false === fwrite( $file_handle, $row_json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
					return new WP_Error( 'mksddn_mc_file_write', __( 'Failed to write to export file.', 'mksddn-migrate-content' ) );
				}
				$first_row = false;
			}

			// Free chunk memory immediately.
			unset( $chunk );

			$offset += $chunk_size;

			// Force garbage collection after each chunk.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return true;
	}

	/**
	 * Export all tables using the current blog prefix.
	 *
	 * @global wpdb $wpdb WordPress DB abstraction.
	 * @return array<string, mixed> Database dump with tables, site URLs, and paths.
	 * @since 1.0.0
	 */
	public function export(): array {
		global $wpdb;

		// Disable time limit for database export.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Try to increase memory limit for large exports.
		$original_limit = ini_get( 'memory_limit' );
		$current_limit  = wp_convert_hr_to_bytes( $original_limit );
		$min_limit      = 512 * 1024 * 1024; // 512MB minimum.

		if ( $current_limit < $min_limit && '-1' !== $original_limit ) {
			$target_limit_mb = 512;
			$set_result      = @ini_set( 'memory_limit', $target_limit_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			if ( false !== $set_result ) {
				$this->log( sprintf( 'Memory limit increased to %d MB for export', $target_limit_mb ) );
			}
		}

		$tables = $this->detect_tables( $wpdb );
		$uploads = wp_upload_dir();
		$dump   = array(
			'site_url'     => \get_option( 'siteurl' ),
			'home_url'     => \home_url(),
			'table_prefix' => $wpdb->prefix,
			'paths'        => array(
				'root'    => function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH,
				'content' => WP_CONTENT_DIR,
				'uploads' => $uploads['basedir'],
			),
			'tables'       => array(),
		);

		$table_count = count( $tables );
		$processed   = 0;

		foreach ( $tables as $table_name ) {
			// Check if export was cancelled.
			if ( $this->is_cancelled() ) {
				return array(); // Return empty array to signal cancellation.
			}

			++$processed;
			$this->log( sprintf( 'Exporting table %d/%d: %s', $processed, $table_count, $table_name ) );

			// Get table schema first.
			$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema export for backup; table name sanitized
			$schema     = is_array( $schema_row ) && isset( $schema_row[1] ) ? (string) $schema_row[1] : '';

			// Get row count to determine if we need chunking.
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- count query for memory optimization
			
			$large_threshold = PluginConfig::large_table_threshold();
			$base_chunk_size = PluginConfig::db_row_chunk_size();

			// For very large tables, use smaller chunks to reduce memory usage (check larger tables first).
			$chunk_size = $base_chunk_size;
			if ( $row_count > 100000 ) {
				// For tables with more than 100k rows, use even smaller chunks.
				$chunk_size = max( 250, intval( $base_chunk_size / 4 ) );
			} elseif ( $row_count > 50000 ) {
				// For tables with more than 50k rows, use smaller chunks.
				$chunk_size = max( 500, intval( $base_chunk_size / 2 ) );
			}

			// For large tables, export in chunks to save memory.
			if ( $row_count > $large_threshold ) {
				$this->log( sprintf( 'Large table detected (%d rows), exporting in chunks of %d', $row_count, $chunk_size ) );
				$rows = $this->export_table_in_chunks( $wpdb, $table_name, $chunk_size, $row_count );
			} else {
				// Small table - load all at once.
				$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- export requires full table scan; $table_name is sanitized prefix match
			}

			if ( null === $rows ) {
				$this->log( sprintf( 'Warning: Failed to export table %s', $table_name ) );
				continue;
			}

			$dump['tables'][ $table_name ] = array(
				'schema' => $schema,
				'rows'   => $rows,
			);

			// Free memory after each table.
			unset( $rows, $schema_row );

			// Force garbage collection after every 5 tables to prevent memory buildup.
			if ( 0 === $processed % 5 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Final cleanup.
		unset( $tables );
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Restore original memory limit.
		if ( isset( $original_limit ) && '-1' !== $original_limit ) {
			@ini_set( 'memory_limit', $original_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		return $dump;
	}

	/**
	 * Export table in chunks to save memory.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param int    $chunk_size Chunk size.
	 * @param int    $row_count  Total row count.
	 * @return array<int, array> All rows from table.
	 * @since 1.0.0
	 */
	private function export_table_in_chunks( wpdb $wpdb, string $table_name, int $chunk_size, int $row_count ): array {
		$all_rows = array();
		$offset   = 0;

		// Handle empty table.
		if ( 0 === $row_count ) {
			return $all_rows;
		}

		// Get primary key or first column for ordering (needed for LIMIT/OFFSET).
		$order_column = $this->get_primary_key_or_first_column( $wpdb, $table_name );

		// Validate column name for security.
		if ( ! $this->is_valid_column_name( $order_column ) ) {
			// Fallback: use LIMIT without ORDER BY (less efficient but safe).
			$this->log( sprintf( 'Warning: Could not determine order column for table %s, using LIMIT without ORDER BY', $table_name ) );
			return $this->export_table_without_order( $wpdb, $table_name, $chunk_size, $row_count );
		}

		while ( $offset < $row_count ) {
			// Check if export was cancelled.
			if ( $this->is_cancelled() ) {
				return array(); // Return empty array to signal cancellation.
			}

			// Use LIMIT/OFFSET for chunking. Order by column for consistency.
			$escaped_table = esc_sql( $table_name );
			$escaped_column = esc_sql( $order_column );
			$query = "SELECT * FROM `{$escaped_table}` ORDER BY `{$escaped_column}` LIMIT %d OFFSET %d";
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$query = $wpdb->prepare( $query, $chunk_size, $offset );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$chunk = $wpdb->get_results( $query, ARRAY_A );

			if ( null === $chunk || empty( $chunk ) ) {
				break;
			}

			// Append chunk to result array efficiently (avoids array_merge memory overhead).
			foreach ( $chunk as $row ) {
				$all_rows[] = $row;
			}

			// Free chunk memory immediately.
			unset( $chunk );

			$offset += $chunk_size;

			// Check cancellation more frequently for large tables (every 10k rows).
			if ( $row_count > 10000 && 0 === $offset % 10000 ) {
				if ( $this->is_cancelled() ) {
					return array(); // Return empty array to signal cancellation.
				}
				$this->log( sprintf( 'Exported %d/%d rows from table %s', min( $offset, $row_count ), $row_count, $table_name ) );
			}

			// Force garbage collection after each chunk for large tables.
			if ( $row_count > 5000 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return $all_rows;
	}

	/**
	 * Export table without ORDER BY (fallback when order column cannot be determined).
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param int    $chunk_size Chunk size.
	 * @param int    $row_count  Total row count.
	 * @return array<int, array> All rows from table.
	 * @since 1.0.0
	 */
	private function export_table_without_order( wpdb $wpdb, string $table_name, int $chunk_size, int $row_count ): array {
		$all_rows = array();
		$offset   = 0;

		while ( $offset < $row_count ) {
			// Use LIMIT/OFFSET without ORDER BY (less efficient but safe).
			$escaped_table = esc_sql( $table_name );
			$query = "SELECT * FROM `{$escaped_table}` LIMIT %d OFFSET %d";
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
			$query = $wpdb->prepare( $query, $chunk_size, $offset );
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
			
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$chunk = $wpdb->get_results( $query, ARRAY_A );

			if ( null === $chunk || empty( $chunk ) ) {
				break;
			}

			// Append chunk to result array efficiently (avoids array_merge memory overhead).
			foreach ( $chunk as $row ) {
				$all_rows[] = $row;
			}

			// Free chunk memory immediately.
			unset( $chunk );

			$offset += $chunk_size;

			// Force garbage collection after each chunk.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return $all_rows;
	}

	/**
	 * Get primary key column name or first column for ordering.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @return string Column name.
	 * @since 1.0.0
	 */
	private function get_primary_key_or_first_column( wpdb $wpdb, string $table_name ): string {
		// Try to get primary key first.
		$escaped_table = esc_sql( $table_name );
		$primary_key_query = "SHOW KEYS FROM `{$escaped_table}` WHERE Key_name = 'PRIMARY'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$primary_key_result = $wpdb->get_row( $primary_key_query, ARRAY_A );

		if ( $primary_key_result && isset( $primary_key_result['Column_name'] ) ) {
			$column_name = $primary_key_result['Column_name'];
			if ( $this->is_valid_column_name( $column_name ) ) {
				return $column_name;
			}
		}

		// Fallback to first column.
		$columns_query = "SHOW COLUMNS FROM `{$escaped_table}` LIMIT 1";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
		$first_column = $wpdb->get_row( $columns_query, ARRAY_A );

		if ( $first_column && isset( $first_column['Field'] ) ) {
			$column_name = $first_column['Field'];
			if ( $this->is_valid_column_name( $column_name ) ) {
				return $column_name;
			}
		}

		// Ultimate fallback - use ID column (common in WordPress tables).
		return 'ID';
	}

	/**
	 * Validate column name to prevent SQL injection.
	 *
	 * @param string $column_name Column name to validate.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	private function is_valid_column_name( string $column_name ): bool {
		// Column names should only contain alphanumeric characters, underscores, and backticks.
		return (bool) preg_match( '/^[a-zA-Z0-9_`]+$/', $column_name );
	}

	/**
	 * Find tables for current site prefix.
	 *
	 * @param wpdb $wpdb Database object.
	 * @return array<int, string> Array of table names.
	 * @since 1.0.0
	 */
	private function detect_tables( wpdb $wpdb ): array {
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$result = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- required to enumerate tables for backup

		return array_filter(
			array_map( 'sanitize_text_field', (array) $result )
		);
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
			error_log( 'MksDdn Migrate Export: ' . $message );
		}
	}
}


