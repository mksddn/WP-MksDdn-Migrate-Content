<?php
/**
 * Dumps WordPress database tables into an array structure.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Database;

use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export tables that belong to the current installation.
 */
class FullDatabaseExporter {

	/**
	 * Export all tables using the current blog prefix.
	 *
	 * @global wpdb $wpdb WordPress DB abstraction.
	 * @return array
	 */
	public function export(): array {
		global $wpdb;

		$tables = $this->detect_tables( $wpdb );
		$uploads = wp_upload_dir();
		$dump   = array(
			'site_url' => \get_option( 'siteurl' ),
			'home_url' => \home_url(),
			'paths'    => array(
				'root'    => ABSPATH,
				'content' => WP_CONTENT_DIR,
				'uploads' => isset( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads',
			),
			'tables'   => array(),
		);

		foreach ( $tables as $table_name ) {
			$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( null === $rows ) {
				continue;
			}

			$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @return string[]
	 */
	private function detect_tables( wpdb $wpdb ): array {
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$query  = $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->get_col( $query );

		return array_filter(
			array_map( 'sanitize_text_field', (array) $result )
		);
	}
}


