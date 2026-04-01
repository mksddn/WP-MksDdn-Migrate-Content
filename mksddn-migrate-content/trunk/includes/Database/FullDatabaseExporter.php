<?php
/**
 * @file: FullDatabaseExporter.php
 * @description: Dumps WordPress database tables into an array structure
 * @dependencies: wpdb
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Database;

use wpdb;

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
	 * Cached table list for the current request (avoids repeated SHOW TABLES).
	 *
	 * @var array<int, string>|null
	 */
	private $table_names_cache = null;

	/**
	 * Export all tables using the current blog prefix.
	 *
	 * @global wpdb $wpdb WordPress DB abstraction.
	 * @return array<string, mixed> Database dump with tables, site URLs, and paths.
	 * @since 1.0.0
	 */
	public function export(): array {
		global $wpdb;

		$tables = $this->get_table_names();
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

		foreach ( $tables as $table_name ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- export requires full table scan; $table_name is sanitized prefix match
			if ( null === $rows ) {
				continue;
			}

			$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema export for backup; table name sanitized
			$schema     = is_array( $schema_row ) && isset( $schema_row[1] ) ? (string) $schema_row[1] : '';

			$dump['tables'][ $table_name ] = array(
				'schema' => $schema,
				'rows'   => $rows,
			);
		}

		return $dump;
	}

	/**
	 * List prefixed tables for the current site (for incremental export).
	 *
	 * @global wpdb $wpdb
	 * @return array<int, string>
	 * @since 2.1.5
	 */
	public function get_table_names(): array {
		if ( null !== $this->table_names_cache ) {
			return $this->table_names_cache;
		}

		global $wpdb;

		$this->table_names_cache = $this->detect_tables( $wpdb );

		return $this->table_names_cache;
	}

	/**
	 * SHOW CREATE TABLE result for a table.
	 *
	 * @global wpdb $wpdb
	 * @param string $table_name Table name.
	 * @return string
	 * @since 2.1.5
	 */
	public function get_create_table_ddl( string $table_name ): string {
		global $wpdb;

		if ( ! $this->is_allowed_table( $table_name ) ) {
			return '';
		}

		$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return is_array( $schema_row ) && isset( $schema_row[1] ) ? (string) $schema_row[1] : '';
	}

	/**
	 * Fetch rows slice for export.
	 *
	 * @global wpdb $wpdb
	 * @param string $table_name Table name.
	 * @param int    $offset     Offset.
	 * @param int    $limit      Limit.
	 * @return array<int, array<string, mixed>>|null Null when the query fails.
	 * @since 2.1.5
	 */
	public function fetch_rows_slice( string $table_name, int $offset, int $limit ): ?array {
		global $wpdb;

		if ( ! $this->is_allowed_table( $table_name ) || $limit < 1 ) {
			return null;
		}

		$offset = max( 0, $offset );
		$limit  = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- validated table name
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );

		if ( null === $rows ) {
			return null;
		}

		return $rows;
	}

	/**
	 * Whether the table name is in the current site's prefixed list.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	private function is_allowed_table( string $table_name ): bool {
		$table_name = sanitize_text_field( $table_name );
		$names      = $this->get_table_names();

		return in_array( $table_name, $names, true );
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
}


