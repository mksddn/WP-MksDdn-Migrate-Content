<?php
/**
 * Database handler base class
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Base database class
 */
abstract class MksDdn_MC_Database {

	/**
	 * Database connection
	 *
	 * @var mixed
	 */
	protected $connection;

	/**
	 * Database name
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Constructor
	 *
	 * @param mixed $connection Database connection
	 * @param string $name Database name
	 */
	public function __construct( $connection, $name ) {
		$this->connection = $connection;
		$this->name       = $name;
	}

	/**
	 * Get database name
	 *
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Get tables
	 *
	 * @return array
	 */
	abstract public function get_tables();

	/**
	 * Get table structure
	 *
	 * @param string $table_name Table name
	 * @return string
	 */
	abstract public function get_table_structure( $table_name );

	/**
	 * Get table data
	 *
	 * @param string $table_name Table name
	 * @param int $offset Offset
	 * @param int $limit Limit
	 * @return array
	 */
	abstract public function get_table_data( $table_name, $offset = 0, $limit = 1000 );

	/**
	 * Execute query
	 *
	 * @param string $query SQL query
	 * @return bool
	 */
	abstract public function query( $query );

	/**
	 * Escape string
	 *
	 * @param string $string String to escape
	 * @return string
	 */
	abstract public function escape( $string );
}

