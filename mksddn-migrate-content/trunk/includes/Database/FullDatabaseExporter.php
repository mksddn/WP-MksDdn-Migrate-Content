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
	 * Export all tables using the current blog prefix.
	 *
	 * @global wpdb $wpdb WordPress DB abstraction.
	 * @return array<string, mixed> Database dump with tables, site URLs, and paths.
	 * @since 1.0.0
	 */
	public function export(): array {
		global $wpdb;

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


