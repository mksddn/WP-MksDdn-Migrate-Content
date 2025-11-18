<?php
/**
 * MySQLi database handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * MySQLi database class
 */
class MksDdn_MC_Database_MySQLi extends MksDdn_MC_Database {

	/**
	 * Get tables
	 *
	 * @return array
	 */
	public function get_tables() {
		$tables = array();
		$result = $this->connection->query( 'SHOW TABLES' );

		if ( $result ) {
			while ( $row = $result->fetch_array() ) {
				$tables[] = $row[0];
			}
			$result->free();
		}

		return $tables;
	}

	/**
	 * Get table structure
	 *
	 * @param string $table_name Table name
	 * @return string
	 */
	public function get_table_structure( $table_name ) {
		// Escape table name to prevent SQL injection
		$table_name = $this->escape( $table_name );
		$result = $this->connection->query( "SHOW CREATE TABLE `{$table_name}`" );
		if ( $result && $row = $result->fetch_assoc() ) {
			return $row['Create Table'];
		}
		return '';
	}

	/**
	 * Get table data
	 *
	 * @param string $table_name Table name
	 * @param int $offset Offset
	 * @param int $limit Limit
	 * @return array
	 */
	public function get_table_data( $table_name, $offset = 0, $limit = 1000 ) {
		$rows = array();
		// Escape table name and sanitize offset/limit to prevent SQL injection
		$table_name = $this->escape( $table_name );
		$offset = absint( $offset );
		$limit = absint( $limit );
		$query = "SELECT * FROM `{$table_name}` LIMIT {$limit} OFFSET {$offset}";
		$result = $this->connection->query( $query );

		if ( $result ) {
			while ( $row = $result->fetch_assoc() ) {
				$rows[] = $row;
			}
			$result->free();
		}

		return $rows;
	}

	/**
	 * Execute query
	 *
	 * @param string $query SQL query
	 * @return bool
	 */
	public function query( $query ) {
		return $this->connection->query( $query );
	}

	/**
	 * Escape string
	 *
	 * @param string $string String to escape
	 * @return string
	 */
	public function escape( $string ) {
		return $this->connection->real_escape_string( $string );
	}
}

