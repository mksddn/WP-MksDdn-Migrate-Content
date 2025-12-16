<?php
/**
 * @file: FullDatabaseImporter.php
 * @description: Restores WordPress database tables from exported data
 * @dependencies: wpdb, WP_Error
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Database;

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

		global $wpdb;
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( $dump['tables'] as $table_name => $table_data ) {
			if ( ! $this->is_valid_table_name( $table_name ) ) {
				continue;
			}

			$this->ensure_table_exists( $wpdb, $table_name, $table_data['schema'] ?? '' );

			if ( ! $this->table_exists( $wpdb, $table_name ) ) {
				continue;
			}

			$auto_increment_key = $this->get_auto_increment_key( $wpdb, $table_name );

			$truncate = $wpdb->query( "TRUNCATE TABLE `{$table_name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name validated via is_valid_table_name()
			if ( false === $truncate ) {
				$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
				/* translators: %s: database table name. */
				return new WP_Error( 'mksddn_db_truncate_failed', sprintf( __( 'Unable to truncate table %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
			}

			$rows = isset( $table_data['rows'] ) && is_array( $table_data['rows'] ) ? $table_data['rows'] : array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				// Remove AUTO_INCREMENT PRIMARY KEY to let database generate new values.
				if ( null !== $auto_increment_key && isset( $row[ $auto_increment_key ] ) ) {
					unset( $row[ $auto_increment_key ] );
				}

				$inserted = $this->insert_row_safe( $wpdb, $table_name, $row );
				if ( false === $inserted ) {
					$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
					/* translators: %s: database table name. */
					return new WP_Error( 'mksddn_db_insert_failed', sprintf( __( 'Failed to insert row into %s.', 'mksddn-migrate-content' ), esc_html( $table_name ) ) );
				}
			}
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
	 * Get AUTO_INCREMENT PRIMARY KEY column name for a table.
	 *
	 * @param wpdb   $wpdb       Database object.
	 * @param string $table_name Table name.
	 * @return string|null Column name if found, null otherwise.
	 * @since 1.0.0
	 */
	private function get_auto_increment_key( wpdb $wpdb, string $table_name ): ?string {
		$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name validated via is_valid_table_name()

		if ( ! is_array( $columns ) ) {
			return null;
		}

		foreach ( $columns as $column ) {
			if ( ! isset( $column['Key'] ) || ! isset( $column['Extra'] ) ) {
				continue;
			}

			if ( 'PRI' === $column['Key'] && false !== strpos( strtolower( $column['Extra'] ), 'auto_increment' ) ) {
				return $column['Field'] ?? null;
			}
		}

		return null;
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
		$result = $wpdb->insert( $table_name, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		// If insert failed due to duplicate key, ignore it (row already exists).
		if ( false === $result && ! empty( $wpdb->last_error ) ) {
			if ( false !== strpos( $wpdb->last_error, 'Duplicate entry' ) ) {
				// Duplicate key error - row already exists, which is acceptable after removing AUTO_INCREMENT keys.
				// WordPress may have created records during import, so we ignore this error.
				return 0; // Return 0 to indicate no rows were inserted, but it's not a failure.
			}
		}

		return $result;
	}
}


