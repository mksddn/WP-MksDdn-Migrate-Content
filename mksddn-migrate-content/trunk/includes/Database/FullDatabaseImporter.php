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

				$this->ensure_table_exists( $wpdb, $table_name, $schema );

				if ( ! $this->table_exists( $wpdb, $table_name ) ) {
					$this->log( sprintf( 'Error: Table %s still missing after creation attempt; skipping import for this table.', $table_name ) );
					continue;
				}

				// Skip truncation for protected tables (user tables not in dump).
				if ( ! in_array( $table_name, $protected_tables, true ) ) {
					$truncate = $wpdb->query( "TRUNCATE TABLE `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name validated via is_valid_table_name()
					if ( false === $truncate ) {
						$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
						/* translators: %s: database table name. */
						return new WP_Error( 'mksddn_db_truncate_failed', sprintf( __( 'Unable to truncate table %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
					}
				} else {
					$this->log( sprintf( 'Skipping truncation of protected table: %s (preserving current users)', $table_name ) );
				}

				$rows      = isset( $table_data['rows'] ) && is_array( $table_data['rows'] ) ? $table_data['rows'] : array();
				$row_count = count( $rows );

				$large_threshold = PluginConfig::large_table_threshold();
				$chunk_size      = $row_count > $large_threshold ? PluginConfig::db_row_chunk_size() : $row_count;

				// Process rows in batches using multi-row INSERT for better performance.
				$offset   = 0;
				$row_keys = array_keys( $rows );

				$batch_size = min( 500, max( 50, (int) ( $chunk_size / 2 ) ) );
				if ( $wpdb->options === $table_name ) {
					$batch_size = min( 100, $batch_size );
				}

				while ( $offset < $row_count ) {
					$batch_end  = min( $offset + $batch_size, $row_count );
					$batch_rows = array();

					// Collect rows for batch insert.
					for ( $i = $offset; $i < $batch_end; ++$i ) {
						if ( ! isset( $row_keys[ $i ] ) ) {
							continue;
						}

						$row_key = $row_keys[ $i ];
						if ( isset( $rows[ $row_key ] ) && is_array( $rows[ $row_key ] ) ) {
							$batch_rows[] = $rows[ $row_key ];
							unset( $rows[ $row_key ] ); // Free immediately.
						}
					}

					// Batch insert all rows at once (much faster than individual inserts).
					if ( ! empty( $batch_rows ) ) {
						$result = $this->batch_insert_rows( $wpdb, $table_name, $batch_rows );
						if ( false === $result ) {
							unset( $rows, $table_data, $row_keys, $batch_rows );
							$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
							/* translators: %s: Table name. */
							return new WP_Error( 'mksddn_db_insert_failed', sprintf( __( 'Failed to insert rows into %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
						}
					}
					unset( $batch_rows );

					$offset = $batch_end;

					if ( $row_count > $large_threshold && function_exists( 'gc_collect_cycles' ) && 0 === $offset % ( $chunk_size * 5 ) ) {
						gc_collect_cycles();
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

				if ( function_exists( 'gc_collect_cycles' ) && ( $row_count > $large_threshold || 0 === $processed % 5 ) ) {
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
		} finally {
			$this->restore_cache_behavior();
		}
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

		$create_result = $wpdb->query( $schema_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema originates from trusted export manifest
		if ( false === $create_result ) {
			$this->log( sprintf( 'Error: Failed to create table %s. MySQL error: %s', $table_name, $wpdb->last_error ) );
		} elseif ( ! $this->table_exists( $wpdb, $table_name ) ) {
			$this->log( sprintf( 'Error: Table %s not found after CREATE TABLE statement.', $table_name ) );
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
	 * Insert rows in batches using safe inserts.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @param array  $rows       Array of row arrays.
	 * @return bool|int Rows affected on success, false on failure.
	 * @since 1.0.0
	 */
	private function batch_insert_rows( wpdb $wpdb, string $table_name, array $rows ): bool|int {
		if ( empty( $rows ) ) {
			return 0;
		}

		$total_inserted = 0;
		foreach ( $rows as $row ) {
			$result = $this->insert_row_safe( $wpdb, $table_name, $row );
			if ( false === $result ) {
				return false;
			}
			$total_inserted += (int) $result;
		}

		return $total_inserted;
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
