<?php
/**
 * @file: FullDatabaseImporter.php
 * @description: Restores WordPress database tables from exported data
 * @dependencies: wpdb, WP_Error
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Database;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Services\PluginLogger;
use wpdb;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import previously exported database rows.
 *
 * @since 1.0.0
 */
class FullDatabaseImporter {

	/**
	 * True after first successful TRUNCATE or INSERT batch in the current import() run.
	 *
	 * @var bool
	 */
	private bool $database_mutated = false;

	/**
	 * Cached column metadata per table for the current import() run.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $table_columns_cache = array();

	/**
	 * One-shot schema/row-normalization warnings already logged (per import run).
	 *
	 * @var array<string, true>
	 */
	private array $row_norm_warnings = array();

	/**
	 * Apply dump onto current database.
	 *
	 * @param array<string, mixed> $dump Database dump array with tables data.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function import( array $dump ) {
		$this->database_mutated    = false;
		$this->table_columns_cache = array();
		$this->row_norm_warnings   = array();

		if ( empty( $dump['tables'] ) || ! is_array( $dump['tables'] ) ) {
			return new WP_Error( 'mksddn_db_empty', __( 'Database dump is empty or invalid.', 'mksddn-migrate-content' ) );
		}

		// Disable time limit for database import.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Reduce cache pressure during large imports to avoid memory spikes.
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( true );
		}
		if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
			wp_suspend_cache_invalidation( true );
		}
		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( true );
		}
		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			wp_defer_comment_counting( true );
		}

		try {
			global $wpdb;
			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

			// Backup critical options before import (to preserve user access if user tables not imported).
			$preserved_options = $this->backup_critical_options( $wpdb );

			// Replace table prefix if source differs from target.
			$source_prefix = isset( $dump['table_prefix'] ) ? (string) $dump['table_prefix'] : '';

			// Auto-detect prefix from table names if not provided (backward compatibility).
			if ( ! $source_prefix && ! empty( $dump['tables'] ) ) {
				$source_prefix = $this->detect_prefix_from_tables( array_keys( $dump['tables'] ) );
				if ( $source_prefix ) {
					$this->log( sprintf( 'Auto-detected source prefix: "%s"', $source_prefix ) );
				}
			}

			$target_prefix  = $wpdb->prefix;
			$replace_prefix = $source_prefix && $target_prefix && $source_prefix !== $target_prefix;

			if ( $replace_prefix ) {
				$this->log( sprintf( 'Replacing table prefix from "%s" to "%s"', $source_prefix, $target_prefix ) );
			}

			$table_count = count( $dump['tables'] );
			$processed   = 0;

			$this->log( sprintf( 'FullDatabaseImporter::import() - Processing %d tables', $table_count ) );

			// Identify user tables in dump to protect existing ones from truncation if not present.
			$protected_tables = array();
			$users_table      = $this->detect_table_by_suffix( array_keys( $dump['tables'] ), 'users' );
			$usermeta_table   = $this->detect_table_by_suffix( array_keys( $dump['tables'] ), 'usermeta' );

			// If user tables are not in dump, protect existing ones from truncation.
			// This ensures current users and their capabilities are preserved when not importing users.
			if ( ! $users_table && $this->table_exists( $wpdb, $wpdb->users ) ) {
				$protected_tables[] = $wpdb->users;
				$this->log( sprintf( 'Protecting existing users table: %s (not in dump)', $wpdb->users ) );
			}
			if ( ! $usermeta_table && $this->table_exists( $wpdb, $wpdb->usermeta ) ) {
				$protected_tables[] = $wpdb->usermeta;
				$this->log( sprintf( 'Protecting existing usermeta table: %s (not in dump)', $wpdb->usermeta ) );
			}

			// Get table names to iterate (will remove from dump as we process to save memory).
			$table_names = array_keys( $dump['tables'] );

			foreach ( $table_names as $original_table_name ) {
			// Get table data and immediately remove from dump to free memory.
			if ( ! isset( $dump['tables'][ $original_table_name ] ) ) {
				continue;
			}
			$table_data = $dump['tables'][ $original_table_name ];
			unset( $dump['tables'][ $original_table_name ] ); // Free memory immediately.
			
			// Replace prefix in table name if needed.
			$table_name = $replace_prefix
				? $this->replace_table_prefix( $original_table_name, $source_prefix, $target_prefix )
				: $original_table_name;
			if ( ! $this->is_valid_table_name( $table_name ) ) {
				$this->log( sprintf( 'Skipping table with invalid name: %s', $table_name ) );
				continue;
			}

			$this->log( sprintf( 'Importing table %d/%d: %s', $processed + 1, $table_count, $table_name ) );

			// Replace prefix in schema if needed.
			$schema = $table_data['schema'] ?? '';
			if ( $replace_prefix && $schema ) {
				$schema = str_replace( "`{$source_prefix}", "`{$target_prefix}", $schema );
			}
			if ( empty( $schema ) ) {
				$this->log( sprintf( 'Warning: Missing schema for table %s; table will not be created if absent.', $table_name ) );
			} elseif ( false === stripos( $schema, 'CREATE TABLE' ) ) {
				$this->log( sprintf( 'Warning: Schema for table %s does not contain CREATE TABLE.', $table_name ) );
			}

			$is_protected = in_array( $table_name, $protected_tables, true );
			$prepared     = $this->prepare_table_for_import( $wpdb, $table_name, $schema, $is_protected );
			if ( is_wp_error( $prepared ) ) {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
				return $prepared;
			}

			if ( ! $this->table_exists( $wpdb, $table_name ) ) {
				$this->log( sprintf( 'Error: Table %s still missing after creation attempt; skipping import for this table.', $table_name ) );
				continue;
			}

			// Invalidate column cache after possible DROP/CREATE.
			unset( $this->table_columns_cache[ $table_name ] );

			$rows      = isset( $table_data['rows'] ) && is_array( $table_data['rows'] ) ? $table_data['rows'] : array();
			$row_count = count( $rows );
			$row_index = 0;

			// Thresholds for memory-safe chunk sizes with ultra-conservative defaults for huge files.
			$large_threshold = PluginConfig::large_table_threshold();
			$very_large_threshold = 50000;   // Tables with >50k rows: smaller chunks
			$extremely_large_threshold = 150000; // Tables with >150k rows: tiny chunks
			$massive_threshold = 500000;     // Tables with >500k rows: micro chunks
			
			// Check current memory before deciding chunk size (adaptive memory management).
			$current_memory_percent = 0;
			if ( function_exists( 'memory_get_usage' ) && function_exists( 'ini_get' ) ) {
				$memory_used = memory_get_usage( true );
				$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
				$current_memory_percent = ( $memory_limit > 0 ) ? ( $memory_used / $memory_limit ) * 100 : 0;
			}
			
			// Adjust chunk size based on table size AND current memory usage.
			if ( $current_memory_percent > 80 ) {
				// If already at 80%+, use ultra-small chunks to prevent exhaustion.
				$chunk_size = min( 500, max( 100, (int) ( $row_count / 100 ) ) );
			} elseif ( $row_count > $massive_threshold ) {
				$chunk_size = 500;
			} elseif ( $row_count > $extremely_large_threshold ) {
				$chunk_size = 1000;
			} elseif ( $row_count > $very_large_threshold ) {
				$chunk_size = 2000;
			} elseif ( $row_count > $large_threshold ) {
				$chunk_size = PluginConfig::db_row_chunk_size();
			} else {
				$chunk_size = $row_count;
			}

			// Process rows in batches using multi-row INSERT for better performance.
			$offset = 0;
			$row_keys = array_keys( $rows );
			
			// Adaptive batch size based on table, memory usage, and row count.
			$base_batch_size = min( 500, max( 50, (int) $chunk_size / 2 ) );
			
			// Reduce batch size if memory usage is high.
			if ( $current_memory_percent > 75 ) {
				$base_batch_size = max( 50, (int) ( $base_batch_size / 4 ) );
			} elseif ( $current_memory_percent > 60 ) {
				$base_batch_size = max( 50, (int) ( $base_batch_size / 2 ) );
			}
			
			$batch_size = $base_batch_size;
			if ( $wpdb->options === $table_name ) {
				$batch_size = min( 100, $batch_size );
			}

			while ( $offset < $row_count ) {
				$batch_end = (int) min( $offset + $batch_size, $row_count );
				$batch_rows = array();
				
				// Collect rows for batch insert.
				$offset_int = (int) $offset;
				for ( $i = $offset_int; $i < $batch_end; ++$i ) {
					if ( ! isset( $row_keys[ $i ] ) ) {
						continue;
					}
					
					$row_key = $row_keys[ $i ];
					if ( isset( $rows[ $row_key ] ) && is_array( $rows[ $row_key ] ) ) {
						$batch_rows[] = $rows[ $row_key ];
						unset( $rows[ $row_key ] ); // Free immediately.
						++$row_index;
					}
				}
				
				// Batch insert all rows at once (much faster than individual inserts).
				if ( ! empty( $batch_rows ) ) {
					$result = $this->batch_insert_rows( $wpdb, $table_name, $batch_rows );
					if ( false === $result ) {
						$db_error = $this->format_db_error( $wpdb );
						$this->log( sprintf( 'Insert failed for table %s. MySQL error: %s', $table_name, $db_error ) );
						unset( $rows, $table_data, $row_keys, $batch_rows );
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
						/* translators: 1: database table name, 2: MySQL error message. */
						return new WP_Error(
							'mksddn_db_insert_failed',
							sprintf(
								__( 'Failed to insert rows into %1$s. Database error: %2$s', 'mksddn-migrate-content' ),
								esc_html( $table_name ),
								esc_html( $db_error )
							)
						);
					}
					$this->mark_database_mutated();
				}
				unset( $batch_rows );

				$offset = $batch_end;

				// Force garbage collection aggressively for large tables.
				if ( $row_count > $large_threshold && function_exists( 'gc_collect_cycles' ) ) {
					if ( $row_count > $massive_threshold ) {
						gc_collect_cycles();
					} elseif ( $row_count > $extremely_large_threshold ) {
						gc_collect_cycles();
					} elseif ( $row_count > $very_large_threshold && 0 === $offset % $chunk_size ) {
						gc_collect_cycles();
					} elseif ( 0 === $offset % ( $chunk_size * 5 ) ) {
						gc_collect_cycles();
					}
				}

				// Log progress for very large tables (less frequently for massive tables).
				if ( $row_count > 100000 && 0 === $offset % 20000 ) {
					$this->log( sprintf( 'Processed %d/%d rows in table %s', min( $offset, $row_count ), $row_count, $table_name ) );
				} elseif ( $row_count > 50000 && 0 === $offset % 10000 ) {
					$this->log( sprintf( 'Processed %d/%d rows in table %s', min( $offset, $row_count ), $row_count, $table_name ) );
				}
			}

		// Free keys array and remaining rows.
		unset( $row_keys, $rows );

		// Free memory after processing each table.
		unset( $rows, $table_data );
		++$processed;

		// Clear wpdb result cache to release memory.
		if ( method_exists( $wpdb, 'flush' ) ) {
			$wpdb->flush();
		}

		// Force garbage collection more aggressively for very large tables.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			if ( $row_count > $massive_threshold ) {
				// For massive tables, multiple GC passes.
				gc_collect_cycles();
				gc_collect_cycles();
			} elseif ( $row_count > $extremely_large_threshold ) {
				// For extremely large tables, collect garbage multiple times.
				gc_collect_cycles();
				gc_collect_cycles();
			} elseif ( $row_count > $very_large_threshold ) {
				// For very large tables, collect garbage after every table.
				gc_collect_cycles();
			} elseif ( 0 === $processed % 5 ) {
				// For regular tables, collect garbage after every 5 tables.
				gc_collect_cycles();
			}
		}
			
			// Check memory usage and log warning if approaching limit.
			if ( function_exists( 'memory_get_usage' ) && function_exists( 'ini_get' ) ) {
				$memory_used = memory_get_usage( true );
				$memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
				$memory_percent = ( $memory_limit > 0 ) ? ( $memory_used / $memory_limit ) * 100 : 0;
				
				// Log warning if memory usage exceeds 70% for large tables (more aggressive).
				if ( $memory_percent > 70 && $row_count > $large_threshold ) {
					$this->log( sprintf( 'Warning: Memory usage is high (%d%% used, %s / %s) after processing table %s', 
						round( $memory_percent ), 
						size_format( $memory_used, 2 ),
						size_format( $memory_limit, 2 ),
						$table_name
					) );
				}
				
				// Emergency cleanup if memory exceeds 85%.
				if ( $memory_percent > 85 ) {
					$this->log( sprintf( 'Critical: Memory usage critical (%d%% used). Forcing garbage collection.', round( $memory_percent ) ) );
					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}
				}
			}
		}

		// Final cleanup after all tables processed.
		unset( $dump );
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		// Restore critical options after import to preserve user access.
		$this->restore_critical_options( $wpdb, $preserved_options );

			$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

			return true;
		} finally {
			$this->restore_cache_behavior();
		}
	}

	/**
	 * Whether the last import() started mutating table data (TRUNCATE or INSERT).
	 *
	 * @return bool
	 */
	public function was_database_mutated(): bool {
		return $this->database_mutated;
	}

	/**
	 * Mark that database content was modified.
	 *
	 * @return void
	 */
	private function mark_database_mutated(): void {
		$this->database_mutated = true;
	}

	/**
	 * Restore cache behavior after import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function restore_cache_behavior(): void {
		if ( function_exists( 'wp_suspend_cache_addition' ) ) {
			wp_suspend_cache_addition( false );
		}
		if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
			wp_suspend_cache_invalidation( false );
		}
		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( false );
		}
		if ( function_exists( 'wp_defer_comment_counting' ) ) {
			wp_defer_comment_counting( false );
		}
	}

	/**
	 * Validate table name to avoid SQL injection.
	 *
	 * @param string $table_name Candidate table name.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	private function is_valid_table_name( string $table_name ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9_]+$/', $table_name );
	}

	/**
	 * Prepare a table for import: recreate from schema when possible, otherwise truncate.
	 *
	 * Full-site dumps carry SHOW CREATE TABLE output. Recreating avoids schema drift
	 * (extra plugin columns, charset/collation mismatches) that breaks INSERTs.
	 *
	 * @param wpdb   $wpdb         Database object.
	 * @param string $table_name   Validated table name.
	 * @param string $schema_sql   CREATE TABLE statement from the dump.
	 * @param bool   $is_protected Whether truncation/drop must be skipped.
	 * @return true|WP_Error
	 * @since 2.7.1
	 */
	private function prepare_table_for_import( wpdb $wpdb, string $table_name, string $schema_sql, bool $is_protected ) {
		if ( $is_protected ) {
			$this->log( sprintf( 'Skipping drop/truncate of protected table: %s (preserving current users)', $table_name ) );
			return true;
		}

		$escaped_table = esc_sql( $table_name );
		$has_schema    = ! empty( $schema_sql ) && false !== stripos( $schema_sql, 'CREATE TABLE' );

		if ( $has_schema ) {
			$schema_sql = $this->normalize_create_table_sql( $schema_sql, $table_name );
			$recreated  = $this->recreate_table_from_schema( $wpdb, $table_name, $schema_sql );
			if ( true === $recreated ) {
				return true;
			}
			if ( is_wp_error( $recreated ) ) {
				return $recreated;
			}
			// false = could not safely swap; fall through to truncate path.
		}

		$this->ensure_table_exists( $wpdb, $table_name, $schema_sql );

		if ( ! $this->table_exists( $wpdb, $table_name ) ) {
			/* translators: %s: database table name. */
			return new WP_Error(
				'mksddn_db_table_missing',
				sprintf( __( 'Unable to create or find table %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) )
			);
		}

		$truncate = $wpdb->query( "TRUNCATE TABLE `{$escaped_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name validated via is_valid_table_name()
		if ( false === $truncate ) {
			/* translators: 1: database table name, 2: MySQL error message. */
			return new WP_Error(
				'mksddn_db_truncate_failed',
				sprintf(
					__( 'Unable to truncate table %1$s. Database error: %2$s', 'mksddn-migrate-content' ),
					esc_html( $table_name ),
					esc_html( $this->format_db_error( $wpdb ) )
				)
			);
		}

		$this->mark_database_mutated();
		return true;
	}

	/**
	 * Recreate a table from dump schema without discarding the old table until CREATE succeeds.
	 *
	 * Existing table is renamed aside first. On CREATE failure it is renamed back.
	 * Returns false when rename/swap is unavailable so the caller can truncate instead.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Validated table name.
	 * @param string $schema_sql Normalized CREATE TABLE SQL.
	 * @return true|false|WP_Error True on success, false to fall back, WP_Error on hard failure after rename.
	 * @since 2.7.1
	 */
	private function recreate_table_from_schema( wpdb $wpdb, string $table_name, string $schema_sql ) {
		$escaped_table = esc_sql( $table_name );
		$table_existed = $this->table_exists( $wpdb, $table_name );
		$backup_name   = '';
		$escaped_bak   = '';

		if ( $table_existed ) {
			$backup_name = $this->make_backup_table_name( $table_name );
			if ( ! $this->is_valid_table_name( $backup_name ) || $backup_name === $table_name ) {
				$this->log( sprintf( 'Cannot build safe backup name for %s; falling back to truncate.', $table_name ) );
				return false;
			}
			$escaped_bak = esc_sql( $backup_name );

			// Remove leftover backup from a previous interrupted import.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated table name
			$wpdb->query( "DROP TABLE IF EXISTS `{$escaped_bak}`" );

			$previous_suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated table names
			$renamed = $wpdb->query( "RENAME TABLE `{$escaped_table}` TO `{$escaped_bak}`" );
			$wpdb->suppress_errors( $previous_suppress );

			if ( false === $renamed ) {
				$this->log( sprintf( 'RENAME TABLE failed for %s: %s. Falling back to truncate.', $table_name, $this->format_db_error( $wpdb ) ) );
				return false;
			}

			$this->mark_database_mutated();
		}

		if ( $this->try_create_table( $wpdb, $table_name, $schema_sql ) ) {
			if ( '' !== $backup_name && $this->table_exists( $wpdb, $backup_name ) ) {
				$previous_suppress = $wpdb->suppress_errors( true );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated backup name
				$dropped_bak = $wpdb->query( "DROP TABLE IF EXISTS `{$escaped_bak}`" );
				$wpdb->suppress_errors( $previous_suppress );
				if ( false === $dropped_bak ) {
					$this->log(
						sprintf(
							'Warning: recreated %s but could not drop backup table %s: %s',
							$table_name,
							$backup_name,
							$this->format_db_error( $wpdb )
						)
					);
				}
			}
			$this->mark_database_mutated();
			$this->log( sprintf( 'Recreated table from dump schema: %s', $table_name ) );
			return true;
		}

		$create_error = $this->format_db_error( $wpdb );

		// CREATE failed after rename — restore the original table.
		if ( '' !== $backup_name && $this->table_exists( $wpdb, $backup_name ) ) {
			if ( $this->table_exists( $wpdb, $table_name ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated table name
				$wpdb->query( "DROP TABLE IF EXISTS `{$escaped_table}`" );
			}

			$previous_suppress = $wpdb->suppress_errors( true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated table names
			$restored = $wpdb->query( "RENAME TABLE `{$escaped_bak}` TO `{$escaped_table}`" );
			$wpdb->suppress_errors( $previous_suppress );

			if ( false === $restored ) {
				$this->log(
					sprintf(
						'CREATE TABLE failed for %s (%s) and restore from %s also failed: %s',
						$table_name,
						$create_error,
						$backup_name,
						$this->format_db_error( $wpdb )
					)
				);
				/* translators: 1: database table name, 2: MySQL error message. */
				return new WP_Error(
					'mksddn_db_create_failed',
					sprintf(
						__( 'Unable to recreate table %1$s from dump schema. Database error: %2$s', 'mksddn-migrate-content' ),
						esc_html( $table_name ),
						esc_html( $create_error )
					)
				);
			}

			$this->log( sprintf( 'CREATE TABLE failed for %s (%s); restored previous table. Falling back to truncate.', $table_name, $create_error ) );
			return false;
		}

		/* translators: 1: database table name, 2: MySQL error message. */
		return new WP_Error(
			'mksddn_db_create_failed',
			sprintf(
				__( 'Unable to recreate table %1$s from dump schema. Database error: %2$s', 'mksddn-migrate-content' ),
				esc_html( $table_name ),
				esc_html( $create_error )
			)
		);
	}

	/**
	 * Build a temporary backup table name within MySQL's 64-char identifier limit.
	 *
	 * @param string $table_name Original table name.
	 * @return string
	 * @since 2.7.1
	 */
	private function make_backup_table_name( string $table_name ): string {
		$suffix = '_mksddn_bak';
		$max    = 64;

		if ( strlen( $table_name ) + strlen( $suffix ) <= $max ) {
			return $table_name . $suffix;
		}

		return substr( $table_name, 0, $max - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Normalize CREATE TABLE SQL so the statement targets the expected table name.
	 *
	 * @param string $schema_sql CREATE TABLE SQL.
	 * @param string $table_name Expected table name (already prefix-replaced).
	 * @return string
	 * @since 2.7.1
	 */
	private function normalize_create_table_sql( string $schema_sql, string $table_name ): string {
		$schema_sql = trim( $schema_sql );

		// Force IF NOT EXISTS off — table is moved aside / created fresh; keep statement deterministic.
		$schema_sql = preg_replace( '/^CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE TABLE ', $schema_sql, 1 ) ?? $schema_sql;

		// Ensure the CREATE targets the (prefix-replaced) table name.
		$replaced = preg_replace(
			'/^CREATE\s+TABLE\s+`?([a-zA-Z0-9_]+)`?/i',
			'CREATE TABLE `' . str_replace( '`', '``', $table_name ) . '`',
			$schema_sql,
			1
		);

		return is_string( $replaced ) ? $replaced : $schema_sql;
	}

	/**
	 * Run CREATE TABLE, retrying with host-incompatible clauses stripped.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param string $schema_sql Normalized CREATE TABLE SQL.
	 * @return bool
	 * @since 2.7.1
	 */
	private function try_create_table( wpdb $wpdb, string $table_name, string $schema_sql ): bool {
		if ( '' === trim( $schema_sql ) ) {
			return false;
		}

		$previous_suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema originates from trusted export manifest
		$result = $wpdb->query( $schema_sql );
		$wpdb->suppress_errors( $previous_suppress );

		if ( false !== $result && $this->table_exists( $wpdb, $table_name ) ) {
			return true;
		}

		$first_error = $this->format_db_error( $wpdb );
		$stripped    = $this->strip_incompatible_create_clauses( $schema_sql );
		if ( $stripped === $schema_sql ) {
			$this->log( sprintf( 'CREATE TABLE failed for %s: %s', $table_name, $first_error ) );
			return false;
		}

		$this->log( sprintf( 'CREATE TABLE failed for %s (%s). Retrying without host-specific clauses.', $table_name, $first_error ) );

		$previous_suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema originates from trusted export manifest
		$result = $wpdb->query( $stripped );
		$wpdb->suppress_errors( $previous_suppress );

		if ( false !== $result && $this->table_exists( $wpdb, $table_name ) ) {
			$this->log( sprintf( 'Created %s after stripping collation/tablespace clauses.', $table_name ) );
			return true;
		}

		$this->log( sprintf( 'CREATE TABLE retry failed for %s: %s', $table_name, $this->format_db_error( $wpdb ) ) );
		return false;
	}

	/**
	 * Strip CREATE TABLE clauses that often fail across MySQL/MariaDB hosts.
	 *
	 * @param string $schema_sql CREATE TABLE SQL.
	 * @return string
	 * @since 2.7.1
	 */
	private function strip_incompatible_create_clauses( string $schema_sql ): string {
		$stripped = preg_replace( '/\s*\/\*![\s\S]*?\*\/\s*/', ' ', $schema_sql ) ?? $schema_sql;
		$stripped = preg_replace( '/\s+TABLESPACE\s+[^\s,)]+/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+DATA DIRECTORY\s*=\s*\'[^\']*\'/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+INDEX DIRECTORY\s*=\s*\'[^\']*\'/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+ROW_FORMAT\s*=\s*\w+/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+COLLATE\s*=\s*[`\']?[a-z0-9_]+[`\']?/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+COLLATE\s+[`\']?[a-z0-9_]+[`\']?/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+CHARACTER SET\s+[`\']?[a-z0-9_]+[`\']?/i', '', $stripped ) ?? $stripped;
		$stripped = preg_replace( '/\s+CHARSET\s*=\s*[`\']?[a-z0-9_]+[`\']?/i', '', $stripped ) ?? $stripped;

		return is_string( $stripped ) ? $stripped : $schema_sql;
	}

	/**
	 * Ensure table exists by running CREATE statement if necessary.
	 *
	 * @param wpdb   $wpdb        Database object.
	 * @param string $table_name  Table name.
	 * @param string $schema_sql  CREATE TABLE statement.
	 * @return void
	 * @since 1.0.0
	 */
	private function ensure_table_exists( wpdb $wpdb, string $table_name, string $schema_sql ): void {
		if ( $this->table_exists( $wpdb, $table_name ) ) {
			return;
		}

		if ( empty( $schema_sql ) ) {
			$this->log( sprintf( 'Warning: Empty schema for table %s; cannot create table.', $table_name ) );
			return;
		}

		$schema_sql = $this->normalize_create_table_sql( $schema_sql, $table_name );
		if ( ! $this->try_create_table( $wpdb, $table_name, $schema_sql ) ) {
			$this->log( sprintf( 'Error: Failed to create table %s. MySQL error: %s', $table_name, $this->format_db_error( $wpdb ) ) );
		}
	}

	/**
	 * Check if table exists in the current database.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @return bool True if table exists, false otherwise.
	 * @since 1.0.0
	 */
	private function table_exists( wpdb $wpdb, string $table_name ): bool {
		$like  = $wpdb->esc_like( $table_name );
		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var( $query ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		return ( $found === $table_name );
	}

	/**
	 * Batch insert multiple rows for better performance.
	 *
	 * NULL values are emitted as SQL NULL (not empty strings). Using %s for null
	 * breaks UNIQUE indexes that allow multiple NULLs but only one empty string
	 * (e.g. Robin Image Optimizer `index-hash` on item_hash).
	 *
	 * Values are escaped via wpdb::_real_escape instead of multi-arg prepare() so
	 * literal "%" in content cannot break placeholder counting (WP < 6.2).
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param array  $rows       Array of row arrays.
	 * @return bool|int Rows affected on success, false on failure.
	 * @since 1.0.0
	 */
	private function batch_insert_rows( wpdb $wpdb, string $table_name, array $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		// For options table, insert row-by-row to handle special logic.
		if ( $wpdb->options === $table_name ) {
			$total_inserted = 0;
			foreach ( $rows as $row ) {
				$result = $this->insert_option_safe( $wpdb, $row );
				if ( false === $result ) {
					return false;
				}
				$total_inserted += (int) $result;
			}
			return $total_inserted;
		}

		$columns_meta = $this->get_table_columns( $wpdb, $table_name );
		$known_cols   = array_keys( $columns_meta );

		$normalized_rows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = $this->normalize_row_for_insert( $row, $columns_meta, $table_name );
			if ( ! empty( $normalized ) ) {
				$normalized_rows[] = $normalized;
			}
		}

		if ( empty( $normalized_rows ) ) {
			return 0;
		}

		// Build multi-row INSERT query for other tables.
		$first_row = reset( $normalized_rows );
		if ( ! is_array( $first_row ) || empty( $first_row ) ) {
			return 0;
		}

		// Union of columns present on any row (ordered by table schema when available).
		$present = array();
		foreach ( $normalized_rows as $row ) {
			foreach ( $row as $col => $_value ) {
				$present[ (string) $col ] = true;
			}
		}

		if ( ! empty( $known_cols ) ) {
			$columns = array();
			foreach ( $known_cols as $col ) {
				if ( isset( $present[ $col ] ) ) {
					$columns[] = $col;
				}
			}
		} else {
			$columns = array_keys( $present );
		}

		if ( empty( $columns ) ) {
			$this->log( sprintf( 'No matching columns to insert into %s.', $table_name ) );
			return false;
		}

		// Sanitize and escape column names for safe SQL usage.
		$sanitized_columns = array_map( 'sanitize_key', $columns );
		$escaped_columns   = array_map( 'esc_sql', $sanitized_columns );
		$column_names      = '`' . implode( '`, `', $escaped_columns ) . '`';

		$values_clauses = array();
		foreach ( $normalized_rows as $row ) {
			$row_parts = array();
			foreach ( $columns as $col ) {
				$value       = array_key_exists( $col, $row ) ? $row[ $col ] : null;
				$row_parts[] = $this->sql_literal( $wpdb, $value );
			}
			$values_clauses[] = '(' . implode( ', ', $row_parts ) . ')';
		}

		// Escape table name for safe SQL usage.
		// Table name is validated via is_valid_table_name() before calling this method.
		$escaped_table_name = esc_sql( $table_name );

		$query = sprintf(
			'INSERT INTO `%s` (%s) VALUES %s',
			$escaped_table_name,
			$column_names,
			implode( ', ', $values_clauses )
		);

		// Avoid flooding the admin UI when a batch fails and we recover below.
		$previous_suppress = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifiers escaped; values escaped via sql_literal().
		$result = $wpdb->query( $query );
		$wpdb->suppress_errors( $previous_suppress );

		if ( false === $result ) {
			$error = $this->format_db_error( $wpdb );
			if ( $this->is_duplicate_key_error( $wpdb ) ) {
				$wpdb->last_error = '';
				// Multi-row INSERT is atomic: one duplicate aborts the whole batch.
				// Fall back to row-by-row so valid rows are still imported.
				return $this->insert_rows_individually( $wpdb, $table_name, $normalized_rows );
			}

			// Other batch failures (packet size, strict mode on one row, etc.): try row-by-row.
			$this->log( sprintf( 'Batch INSERT failed for %s (%s); retrying row-by-row.', $table_name, $error ) );
			$wpdb->last_error = '';
			return $this->insert_rows_individually( $wpdb, $table_name, $normalized_rows );
		}

		return $result;
	}

	/**
	 * Build a SQL literal for a PHP value (NULL or quoted/escaped scalar).
	 *
	 * @param wpdb  $wpdb  Database object.
	 * @param mixed $value Value to escape.
	 * @return string
	 * @since 2.7.1
	 */
	private function sql_literal( wpdb $wpdb, $value ): string {
		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		if ( is_array( $value ) || is_object( $value ) ) {
			$value = maybe_serialize( $value );
		}

		$value = (string) $value;

		if ( method_exists( $wpdb, '_real_escape' ) ) {
			// phpcs:ignore WordPress.DB.RestrictedClasses.UseWpdb, WordPress.DB.RestrictedFunctions.mysql_mysql_real_escape_string -- bulk import escape; avoids wpdb::prepare() "%" placeholder bugs on WP < 6.2
			return "'" . $wpdb->_real_escape( $value ) . "'";
		}

		return "'" . esc_sql( $value ) . "'";
	}

	/**
	 * Load and cache SHOW COLUMNS metadata for a table.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @return array<string, array<string, mixed>> Map of column name => column info.
	 * @since 2.7.1
	 */
	private function get_table_columns( wpdb $wpdb, string $table_name ): array {
		if ( isset( $this->table_columns_cache[ $table_name ] ) ) {
			return $this->table_columns_cache[ $table_name ];
		}

		$escaped = esc_sql( $table_name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name validated and escaped
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$escaped}`", ARRAY_A );
		$map     = array();

		if ( is_array( $columns ) ) {
			foreach ( $columns as $column ) {
				if ( empty( $column['Field'] ) ) {
					continue;
				}
				$map[ (string) $column['Field'] ] = $column;
			}
		}

		$this->table_columns_cache[ $table_name ] = $map;
		return $map;
	}

	/**
	 * Keep only known columns and coerce NULL for NOT NULL fields.
	 *
	 * @param array<string, mixed>                $row          Source row.
	 * @param array<string, array<string, mixed>> $columns_meta Column metadata.
	 * @param string                              $table_name   Table name for one-shot warning logs.
	 * @return array<string, mixed>
	 * @since 2.7.1
	 */
	private function normalize_row_for_insert( array $row, array $columns_meta, string $table_name = '' ): array {
		if ( empty( $columns_meta ) ) {
			return $row;
		}

		$normalized = array();
		$discarded  = array();
		$coerced    = array();

		foreach ( $row as $column => $value ) {
			$col_key = (string) $column;
			if ( ! isset( $columns_meta[ $col_key ] ) ) {
				$discarded[] = $col_key;
				continue;
			}

			$meta    = $columns_meta[ $col_key ];
			$null_ok = isset( $meta['Null'] ) && 'YES' === strtoupper( (string) $meta['Null'] );

			if ( null === $value && ! $null_ok ) {
				if ( array_key_exists( 'Default', $meta ) && null !== $meta['Default'] ) {
					$value = $meta['Default'];
				} else {
					$type = isset( $meta['Type'] ) ? strtolower( (string) $meta['Type'] ) : '';
					if ( false !== strpos( $type, 'int' ) || false !== strpos( $type, 'decimal' ) || false !== strpos( $type, 'float' ) || false !== strpos( $type, 'double' ) ) {
						$value = 0;
					} else {
						$value = '';
					}
				}
				$coerced[] = $col_key;
			}

			$normalized[ $col_key ] = $value;
		}

		if ( '' !== $table_name ) {
			$this->log_row_norm_warning_once(
				'discard:' . $table_name,
				$discarded,
				'Dropping unknown columns for ' . $table_name . ' (not in target schema): %s'
			);
			$this->log_row_norm_warning_once(
				'nullcoerce:' . $table_name,
				$coerced,
				'Coerced NULL to default/empty for NOT NULL columns on ' . $table_name . ': %s'
			);
		}

		return $normalized;
	}

	/**
	 * Log a one-shot warning listing column names (avoids flooding the import log).
	 *
	 * @param string        $warning_key Unique key for this warning within the import run.
	 * @param array<string> $columns     Column names involved.
	 * @param string        $message_fmt sprintf format with one %%s for the column list.
	 * @return void
	 * @since 2.7.1
	 */
	private function log_row_norm_warning_once( string $warning_key, array $columns, string $message_fmt ): void {
		if ( empty( $columns ) || isset( $this->row_norm_warnings[ $warning_key ] ) ) {
			return;
		}

		$this->row_norm_warnings[ $warning_key ] = true;
		$unique                                  = array_values( array_unique( $columns ) );
		$shown                                   = array_slice( $unique, 0, 20 );
		$list                                    = implode( ', ', $shown );
		if ( count( $unique ) > 20 ) {
			$list .= ', ...';
		}

		$this->log( sprintf( $message_fmt, $list ) );
	}

	/**
	 * Human-readable last database error for logs and WP_Error messages.
	 *
	 * @param wpdb $wpdb Database object.
	 * @return string
	 * @since 2.7.1
	 */
	private function format_db_error( wpdb $wpdb ): string {
		$error = trim( (string) $wpdb->last_error );
		if ( '' !== $error ) {
			return $error;
		}

		return __( 'unknown database error', 'mksddn-migrate-content' );
	}

	/**
	 * Insert rows one at a time (fallback when a multi-row INSERT fails).
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param array  $rows       Array of row arrays (preferably already normalized).
	 * @return bool|int Total affected rows, or false on hard failure.
	 * @since 1.0.0
	 */
	private function insert_rows_individually( wpdb $wpdb, string $table_name, array $rows ) {
		$total = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$result = $this->insert_row_safe( $wpdb, $table_name, $row );
			if ( false === $result ) {
				$this->log(
					sprintf(
						'Row insert failed for %s. MySQL error: %s. Row keys: %s',
						$table_name,
						$this->format_db_error( $wpdb ),
						implode( ', ', array_keys( $row ) )
					)
				);
				return false;
			}
			$total += (int) $result;
		}
		return $total;
	}

	/**
	 * Whether the last wpdb error is a MySQL duplicate-key (1062).
	 *
	 * @param wpdb $wpdb Database object.
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_duplicate_key_error( wpdb $wpdb ): bool {
		$error_code = $wpdb->last_error ? $wpdb->last_error : '';
		$error_num  = 0;
		if ( isset( $wpdb->last_errno ) ) {
			$error_num = (int) $wpdb->last_errno;
		} elseif ( isset( $wpdb->last_error_no ) ) {
			// Legacy typo kept for older forks / compatibility.
			$error_num = (int) $wpdb->last_error_no;
		}

		return ( 1062 === $error_num || false !== strpos( strtolower( $error_code ), 'duplicate entry' ) );
	}

	/**
	 * Insert row safely, handling duplicate key errors.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param array  $row        Row data.
	 * @return bool|int Number of affected rows on success, false on failure.
	 * @since 1.0.0
	 */
	private function insert_row_safe( wpdb $wpdb, string $table_name, array $row ) {
		// For wp_options table, use INSERT ... ON DUPLICATE KEY UPDATE to handle transients
		// that may be created by WordPress during import.
		if ( $wpdb->options === $table_name ) {
			return $this->insert_option_safe( $wpdb, $row );
		}

		$columns_meta = $this->get_table_columns( $wpdb, $table_name );
		if ( ! empty( $columns_meta ) ) {
			$row = $this->normalize_row_for_insert( $row, $columns_meta, $table_name );
			if ( empty( $row ) ) {
				return 0;
			}
		}

		// $wpdb->insert() preserves PHP null as SQL NULL (unlike prepare('%s')).
		$previous_suppress = $wpdb->suppress_errors( true );
		$result            = $wpdb->insert( $table_name, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $previous_suppress );

		if ( false === $result ) {
			if ( $this->is_duplicate_key_error( $wpdb ) ) {
				// Duplicate key — row already exists; acceptable during import.
				$wpdb->last_error = '';
				return 0;
			}
		}

		return $result;
	}

	/**
	 * Insert option row safely using INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * @param wpdb  $wpdb Database object.
	 * @param array $row  Row data with option_name and option_value.
	 * @return int Number of affected rows.
	 * @since 1.0.0
	 */
	private function insert_option_safe( wpdb $wpdb, array $row ): int {
		if ( ! isset( $row['option_name'] ) || ! isset( $row['option_value'] ) ) {
			return 0;
		}

		$option_name  = $row['option_name'];
		$option_value = $row['option_value'];
		$autoload     = isset( $row['autoload'] ) ? $row['autoload'] : 'yes';

		// Skip sensitive options that could break current user access or invalidate sessions.
		// Check both exact name and suffix (for prefixed options like wp_user_roles).
		$skip_suffixes = array(
			'user_roles',           // WordPress user roles configuration (e.g., wp_user_roles).
		);

		$skip_exact = array(
			'default_role',         // Default user role.
			'admin_email',          // Keep current admin email.
		);

		// Skip auth keys/salts to preserve current sessions (prevents logout after import).
		$auth_keys = array(
			'auth_key',
			'secure_auth_key',
			'logged_in_key',
			'nonce_key',
			'auth_salt',
			'secure_auth_salt',
			'logged_in_salt',
			'nonce_salt',
		);

		// Check suffix-based options (like wp_user_roles, masa_user_roles, etc.).
		foreach ( $skip_suffixes as $suffix ) {
			if ( substr( $option_name, -strlen( $suffix ) ) === $suffix ) {
				return 0;
			}
		}

		// Check exact name options.
		foreach ( array_merge( $skip_exact, $auth_keys ) as $skip_key ) {
			if ( $skip_key === $option_name ) {
				return 0;
			}
		}

		// Special handling for active_plugins - merge to keep this plugin active.
		if ( 'active_plugins' === $option_name ) {
			return $this->merge_active_plugins( $wpdb, $option_value );
		}

		// Use INSERT ... ON DUPLICATE KEY UPDATE to handle transients and other options
		// that may be created by WordPress during import.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$table_name = $wpdb->options;
		$query      = $wpdb->prepare(
			"INSERT INTO `{$table_name}` (`option_name`, `option_value`, `autoload`) 
			VALUES (%s, %s, %s) 
			ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)",
			$option_name,
			$option_value,
			$autoload
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $wpdb->rows_affected;
	}

	/**
	 * Merge active_plugins: import new plugins but keep this plugin active.
	 *
	 * @param wpdb   $wpdb         Database object.
	 * @param string $option_value Serialized array of plugins from import.
	 * @return int Number of affected rows.
	 * @since 1.0.0
	 */
	private function merge_active_plugins( wpdb $wpdb, string $option_value ): int {
		$imported_plugins = maybe_unserialize( $option_value );
		if ( ! is_array( $imported_plugins ) ) {
			$imported_plugins = array();
		}

		// Our plugin path (must stay active).
		$our_plugin = defined( 'MKSDDN_MC_BASENAME' ) ? MKSDDN_MC_BASENAME : 'mksddn-migrate-content/mksddn-migrate-content.php';

		// Remove duplicates and ensure our plugin is in the list.
		$imported_plugins = array_values( array_unique( array_filter( $imported_plugins ) ) );
		if ( ! in_array( $our_plugin, $imported_plugins, true ) ) {
			$imported_plugins[] = $our_plugin;
		}

		$new_value = maybe_serialize( $imported_plugins );

		// Use INSERT ... ON DUPLICATE KEY UPDATE (UPDATE alone fails after TRUNCATE).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$query = $wpdb->prepare(
			"INSERT INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) 
			VALUES (%s, %s, %s) 
			ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`)",
			'active_plugins',
			$new_value,
			'yes'
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query is already prepared via $wpdb->prepare() above.
		$wpdb->query( $query );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->log( sprintf( 'Merged active_plugins: %d plugins, our_plugin=%s', count( $imported_plugins ), $our_plugin ) );

		return 1;
	}

	/**
	 * Backup critical WordPress options before import.
	 * Reads directly from database to avoid cache issues.
	 *
	 * @param wpdb $wpdb Database object.
	 * @return array Backed up options.
	 * @since 1.0.0
	 */
	private function backup_critical_options( wpdb $wpdb ): array {
		// Options that must be preserved to maintain user access and sessions.
		// Note: active_plugins is handled separately via merge_active_plugins().
		$critical_keys = array(
			$wpdb->prefix . 'user_roles',  // User roles and capabilities (most critical!).
			'default_role',                 // Default user role.
			'admin_email',                  // Admin email.
		);

		$backup = array();
		
		// Read directly from database (not through get_option cache).
		foreach ( $critical_keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
					$key
				)
			);
			
			if ( null !== $value ) {
				$backup[ $key ] = $value;
				$this->log( sprintf( 'Backed up option: %s', $key ) );
			} else {
				$this->log( sprintf( 'Warning: Critical option not found: %s', $key ) );
			}
		}

		if ( ! empty( $backup ) ) {
			$this->log( sprintf( 'Total backed up %d critical options for preservation', count( $backup ) ) );
		} else {
			$this->log( 'Warning: No critical options found to backup!' );
		}

		return $backup;
	}

	/**
	 * Restore critical options after import.
	 * Writes directly to database and clears cache.
	 *
	 * @param wpdb  $wpdb           Database object.
	 * @param array $preserved_options Backed up options.
	 * @return void
	 * @since 1.0.0
	 */
	private function restore_critical_options( wpdb $wpdb, array $preserved_options ): void {
		if ( empty( $preserved_options ) ) {
			$this->log( 'Warning: No critical options to restore!' );
			return;
		}

		foreach ( $preserved_options as $key => $value ) {
			// Delete existing value first to ensure clean update.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$wpdb->options,
				array( 'option_name' => $key ),
				array( '%s' )
			);
			
			// Insert preserved value.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $key,
					'option_value' => $value,
					'autoload'     => 'yes',
				),
				array( '%s', '%s', '%s' )
			);
			
			$this->log( sprintf( 'Restored option: %s', $key ) );
		}

		// Clear WordPress options cache aggressively to ensure fresh data.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		// Also clear specific options caches.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$this->log( 'Flushed WordPress cache after restoring critical options' );

		$this->log( sprintf( 'Total restored %d critical options to preserve user access', count( $preserved_options ) ) );
	}

	/**
	 * Detect table name by suffix in array of table names.
	 *
	 * @param array  $table_names Array of table names.
	 * @param string $suffix      Table suffix (e.g., 'users', 'usermeta').
	 * @return string|null Table name or null if not found.
	 * @since 1.0.0
	 */
	private function detect_table_by_suffix( array $table_names, string $suffix ): ?string {
		$suffix_length = strlen( $suffix );
		if ( $suffix_length <= 0 ) {
			return null;
		}

		foreach ( $table_names as $name ) {
			$normalized = sanitize_text_field( (string) $name );
			if ( substr( $normalized, -$suffix_length ) === $suffix ) {
				return $normalized;
			}
		}

		return null;
	}

	/**
	 * Auto-detect table prefix from table names (backward compatibility).
	 *
	 * @param array $table_names Array of table names.
	 * @return string Detected prefix or empty string.
	 * @since 1.0.0
	 */
	private function detect_prefix_from_tables( array $table_names ): string {
		if ( empty( $table_names ) ) {
			return '';
		}

		// Look for common WordPress core tables to detect prefix.
		$core_suffixes = array( 'posts', 'options', 'users', 'usermeta', 'terms', 'term_taxonomy' );
		
		foreach ( $table_names as $table_name ) {
			foreach ( $core_suffixes as $suffix ) {
				// Check if table ends with core suffix.
				if ( substr( $table_name, -strlen( $suffix ) ) === $suffix ) {
					// Extract prefix (everything before the suffix).
					$prefix = substr( $table_name, 0, -strlen( $suffix ) );
					
					// Verify this prefix works for other core tables.
					$matches = 0;
					foreach ( $core_suffixes as $test_suffix ) {
						if ( in_array( $prefix . $test_suffix, $table_names, true ) ) {
							++$matches;
						}
					}
					
					// If at least 3 core tables match, we found the prefix.
					if ( $matches >= 3 ) {
						return $prefix;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Replace table prefix in table name.
	 *
	 * @param string $table_name    Original table name.
	 * @param string $source_prefix Source prefix to replace.
	 * @param string $target_prefix Target prefix.
	 * @return string Table name with replaced prefix.
	 * @since 1.0.0
	 */
	private function replace_table_prefix( string $table_name, string $source_prefix, string $target_prefix ): string {
		// Only replace if table starts with source prefix.
		if ( 0 === strpos( $table_name, $source_prefix ) ) {
			return $target_prefix . substr( $table_name, strlen( $source_prefix ) );
		}

		return $table_name;
	}

	/**
	 * Log message via centralized plugin logger.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.0.0
	 */
	private function log( string $message ): void {
		PluginLogger::log( $message, 'FullDatabaseImporter' );
	}
}
