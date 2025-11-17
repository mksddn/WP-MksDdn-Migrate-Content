<?php
/**
 * Export enumerate tables
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export enumerate tables class
 */
class MksDdn_MC_Export_Enumerate_Tables {

	/**
	 * Execute table enumeration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		global $wpdb;

		MksDdn_MC_Status::info( __( 'Enumerating database tables...', 'mksddn-migrate-content' ) );

		$tables = array();

		// Get all tables
		$table_list = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		foreach ( $table_list as $table ) {
			$table_name = $table[0];
			$tables[] = $table_name;
		}

		$params['tables'] = $tables;

		return $params;
	}
}

