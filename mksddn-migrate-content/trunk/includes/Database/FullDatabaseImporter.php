<?php
/**
 * @file: FullDatabaseImporter.php
 * @description: Restores WordPress database tables from exported data
 * @dependencies: wpdb, WP_Error
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
 * Import previously exported database rows.
 *
 * @since 1.0.0
 */
class FullDatabaseImporter {

	/**
	 * Apply dump onto current database.
	 *
	 * @param array<string, mixed> $dump Database dump array with tables data.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function import( array $dump ) {
		if ( empty( $dump['tables'] ) || ! is_array( $dump['tables'] ) ) {
			return new WP_Error( 'mksddn_db_empty', __( 'Database dump is empty or invalid.', 'mksddn-migrate-content' ) );
		}

		// Disable time limit for database import.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.max_execution_time_Disallowed
		}

		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		$table_count = count( $dump['tables'] );
		$processed   = 0;

		$this->log( sprintf( 'FullDatabaseImporter::import() - Processing %d tables', $table_count ) );

		foreach ( $dump['tables'] as $table_name => $table_data ) {
			if ( ! $this->is_valid_table_name( $table_name ) ) {
				continue;
			}

			$this->log( sprintf( 'Importing table %d/%d: %s', $processed + 1, $table_count, $table_name ) );

			$this->ensure_table_exists( $wpdb, $table_name, $table_data['schema'] ?? '' );

			if ( ! $this->table_exists( $wpdb, $table_name ) ) {
				continue;
			}

			$truncate = $wpdb->query( "TRUNCATE TABLE `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name validated via is_valid_table_name()
			if ( false === $truncate ) {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
				/* translators: %s: database table name. */
				return new WP_Error( 'mksddn_db_truncate_failed', sprintf( __( 'Unable to truncate table %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
			}

			$rows      = isset( $table_data['rows'] ) && is_array( $table_data['rows'] ) ? $table_data['rows'] : array();
			$row_count = count( $rows );
			$row_index = 0;

			// For very large tables, process in chunks to save memory.
			$large_threshold = PluginConfig::large_table_threshold();
			$chunk_size      = $row_count > $large_threshold ? PluginConfig::db_row_chunk_size() : $row_count;

			// Process rows in chunks to avoid loading all into memory at once.
			$offset = 0;
			$row_keys = array_keys( $rows );

			while ( $offset < $row_count ) {
				$chunk_end = min( $offset + $chunk_size, $row_count );
				$chunk_keys = array_slice( $row_keys, $offset, $chunk_size );

				foreach ( $chunk_keys as $row_key ) {
					if ( ! isset( $rows[ $row_key ] ) || ! is_array( $rows[ $row_key ] ) ) {
						continue;
					}

					$row = $rows[ $row_key ];

					$inserted = $this->insert_row_safe( $wpdb, $table_name, $row );
					if ( false === $inserted ) {
						unset( $rows, $table_data, $row_keys, $chunk_keys ); // Free memory before returning error.
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
						/* translators: %s: database table name. */
						return new WP_Error( 'mksddn_db_insert_failed', sprintf( __( 'Failed to insert row into %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
					}

					// Remove processed row from array to free memory.
					unset( $rows[ $row_key ] );
					++$row_index;
				}

				// Free chunk memory immediately.
				unset( $chunk_keys );

				$offset = $chunk_end;

				// Force garbage collection after each chunk for large tables.
				if ( $row_count > $large_threshold && function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}

				// Log progress for very large tables.
				if ( $row_count > 10000 && 0 === $offset % 10000 ) {
					$this->log( sprintf( 'Processed %d/%d rows in table %s', min( $offset, $row_count ), $row_count, $table_name ) );
				}
			}

			unset( $row_keys ); // Free keys array.

			// Free memory after processing each table.
			unset( $rows, $table_data );
			++$processed;

			// Force garbage collection after every 5 tables to prevent memory buildup.
			if ( 0 === $processed % 5 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Final cleanup after all tables processed.
		unset( $dump );
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		return true;
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
			return;
		}

		$wpdb->query( $schema_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema originates from trusted export manifest
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
	 * Insert row safely, handling duplicate key errors.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param array  $row        Row data.
	 * @return bool|int Number of affected rows on success, false on failure.
	 * @since 1.0.0
	 */
	private function insert_row_safe( wpdb $wpdb, string $table_name, array $row ): bool|int {
		// For wp_options table, use INSERT ... ON DUPLICATE KEY UPDATE to handle transients
		// that may be created by WordPress during import.
		if ( $wpdb->options === $table_name ) {
			return $this->insert_option_safe( $wpdb, $row );
		}

		$result = $wpdb->insert( $table_name, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Check for duplicate key error.
		if ( false === $result ) {
			$error_code = $wpdb->last_error ? $wpdb->last_error : '';
			$error_num  = isset( $wpdb->last_error_no ) ? $wpdb->last_error_no : 0;

			// MySQL error 1062 is "Duplicate entry" error.
			// Also check error message for compatibility with older WordPress versions.
			if ( 1062 === $error_num || false !== strpos( strtolower( $error_code ), 'duplicate entry' ) ) {
				// Duplicate key error - row already exists, which is acceptable.
				// WordPress may have created records during import, so we ignore this error.
				$wpdb->last_error = ''; // Clear error to prevent logging.
				return 0; // Return 0 to indicate no rows were inserted, but it's not a failure.
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

		// Use INSERT ... ON DUPLICATE KEY UPDATE to handle transients and other options
		// that may be created by WordPress during import.
		$table_name = $wpdb->options;
		$query      = $wpdb->prepare(
			"INSERT INTO `{$table_name}` (`option_name`, `option_value`, `autoload`) 
			VALUES (%s, %s, %s) 
			ON DUPLICATE KEY UPDATE `option_value` = VALUES(`option_value`), `autoload` = VALUES(`autoload`)",
			$option_name,
			$option_value,
			$autoload
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$wpdb->query( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $wpdb->rows_affected;
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
}


