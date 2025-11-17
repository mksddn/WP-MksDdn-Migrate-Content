<?php
/**
 * Export database
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export database class
 */
class MksDdn_MC_Export_Database {

	/**
	 * Execute database export
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		global $wpdb;

		MksDdn_MC_Status::info( __( 'Exporting database...', 'mksddn-migrate-content' ) );

		$tables = isset( $params['tables'] ) ? $params['tables'] : array();

		if ( empty( $tables ) ) {
			$params = MksDdn_MC_Export_Enumerate_Tables::execute( $params );
			$tables = $params['tables'];
		}

		$sql_content = '';

		// Export each table
		foreach ( $tables as $table_name ) {
			MksDdn_MC_Status::info( sprintf( __( 'Exporting table: %s', 'mksddn-migrate-content' ), $table_name ) );

			// Get table structure
			$create_table = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_A );
			if ( $create_table ) {
				$sql_content .= "\n\n-- Table structure for `{$table_name}`\n";
				$sql_content .= "DROP TABLE IF EXISTS `{$table_name}`;\n";
				$sql_content .= $create_table['Create Table'] . ";\n\n";

				// Get table data
				$rows = $wpdb->get_results( "SELECT * FROM `{$table_name}`", ARRAY_A );
				if ( ! empty( $rows ) ) {
					$sql_content .= "-- Data for table `{$table_name}`\n";
					$sql_content .= self::generate_insert_statements( $table_name, $rows );
				}
			}
		}

		$params['database_content'] = $sql_content;

		return $params;
	}

	/**
	 * Generate INSERT statements
	 *
	 * @param string $table_name Table name
	 * @param array $rows Table rows
	 * @return string
	 */
	private static function generate_insert_statements( $table_name, $rows ) {
		global $wpdb;

		$sql = '';
		$chunk_size = MKSDDN_MC_MAX_SELECT_RECORDS;

		$chunks = array_chunk( $rows, $chunk_size );

		foreach ( $chunks as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$row_values = array();
				foreach ( $row as $value ) {
					if ( is_null( $value ) ) {
						$row_values[] = 'NULL';
					} else {
						$row_values[] = "'" . $wpdb->_escape( $value ) . "'";
					}
				}
				$values[] = '(' . implode( ',', $row_values ) . ')';
			}

			if ( ! empty( $values ) ) {
				$columns = array_keys( $chunk[0] );
				$sql .= "INSERT INTO `{$table_name}` (`" . implode( '`, `', $columns ) . "`) VALUES\n";
				$sql .= implode( ",\n", $values ) . ";\n\n";
			}
		}

		return $sql;
	}
}

