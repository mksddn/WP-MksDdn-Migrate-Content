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
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

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
		
		$target_prefix = $wpdb->prefix;
		$replace_prefix = $source_prefix && $target_prefix && $source_prefix !== $target_prefix;

		if ( $replace_prefix ) {
			$this->log( sprintf( 'Replacing table prefix from "%s" to "%s"', $source_prefix, $target_prefix ) );
		}

		$table_count = count( $dump['tables'] );
		$processed   = 0;

		$this->log( sprintf( 'FullDatabaseImporter::import() - Processing %d tables', $table_count ) );

		foreach ( $dump['tables'] as $original_table_name => $table_data ) {
			// Replace prefix in table name if needed.
			$table_name = $replace_prefix
				? $this->replace_table_prefix( $original_table_name, $source_prefix, $target_prefix )
				: $original_table_name;
			if ( ! $this->is_valid_table_name( $table_name ) ) {
				continue;
			}

			$this->log( sprintf( 'Importing table %d/%d: %s', $processed + 1, $table_count, $table_name ) );

			// Replace prefix in schema if needed.
			$schema = $table_data['schema'] ?? '';
			if ( $replace_prefix && $schema ) {
				$schema = str_replace( "`{$source_prefix}", "`{$target_prefix}", $schema );
			}

			$this->ensure_table_exists( $wpdb, $table_name, $schema );

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

		// Restore critical options after import to preserve user access.
		$this->restore_critical_options( $wpdb, $preserved_options );

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


