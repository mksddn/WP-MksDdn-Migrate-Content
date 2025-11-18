<?php
/**
 * Import database
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Import database class
 */
class MksDdn_MC_Import_Database {

	/**
	 * Execute database import
	 *
	 * @param array $params Import parameters
	 * @return array
	 * @throws Exception
	 */
	public static function execute( $params ) {
		global $wpdb;

		MksDdn_MC_Status::info( __( 'Importing database...', 'mksddn-migrate-content' ) );

		if ( empty( $params['extract_path'] ) ) {
			throw new Exception( __( 'Extract path not specified.', 'mksddn-migrate-content' ) );
		}

		$database_file = $params['extract_path'] . DIRECTORY_SEPARATOR . MKSDDN_MC_DATABASE_NAME;

		if ( ! file_exists( $database_file ) ) {
			throw new Exception( __( 'Database file not found in archive.', 'mksddn-migrate-content' ) );
		}

		// Read SQL file
		$sql_content = MksDdn_MC_File::read( $database_file );
		if ( false === $sql_content ) {
			throw new Exception( __( 'Failed to read database file.', 'mksddn-migrate-content' ) );
		}

		// Replace URLs if needed
		$settings = MksDdn_MC_Settings::get_all();
		if ( isset( $params['package']['wordpress']['url'] ) && ! empty( $settings['import_replace_urls'] ) ) {
			$old_url = $params['package']['wordpress']['url'];
			$new_url = site_url();
			if ( ! empty( $old_url ) && $old_url !== $new_url ) {
				$sql_content = mksddn_mc_replace_urls( $sql_content, $old_url, $new_url );
			}
		}

		// Process SQL queries
		self::process_sql( $sql_content );

		MksDdn_MC_Status::info( __( 'Database imported successfully.', 'mksddn-migrate-content' ) );

		return $params;
	}

	/**
	 * Process SQL queries
	 *
	 * @param string $sql_content SQL content
	 * @return void
	 * @throws Exception
	 */
	private static function process_sql( $sql_content ) {
		global $wpdb;

		// Remove comments and empty lines
		$sql_content = preg_replace( '/^--.*$/m', '', $sql_content );
		$sql_content = preg_replace( '/^\/\*.*?\*\/;?$/ms', '', $sql_content );

		// Split into individual queries
		$queries = self::split_sql( $sql_content );

		$processed = 0;
		$total = count( $queries );

		foreach ( $queries as $query ) {
			$query = trim( $query );
			if ( empty( $query ) ) {
				continue;
			}

			// Skip potentially dangerous queries (DROP, TRUNCATE, DELETE without WHERE)
			if ( preg_match( '/^\s*(DROP|TRUNCATE|DELETE\s+FROM\s+\w+\s*;?\s*$)/i', $query ) ) {
				MksDdn_MC_Log::warning( sprintf( 'Skipped potentially dangerous query: %s', substr( $query, 0, 100 ) ) );
				continue;
			}

			// Execute query
			$result = $wpdb->query( $query );
			if ( false === $result ) {
				// Log error but continue
				MksDdn_MC_Log::error( sprintf( 'Database import error: %s', $wpdb->last_error ) );
			}

			$processed++;
			if ( $processed % 100 === 0 ) {
				$progress = (int) ( ( $processed / $total ) * 100 );
				MksDdn_MC_Status::progress( $progress );
			}
		}
	}

	/**
	 * Split SQL into individual queries
	 *
	 * @param string $sql SQL content
	 * @return array
	 */
	private static function split_sql( $sql ) {
		$queries = array();
		$current_query = '';
		$in_string = false;
		$string_char = '';

		$length = strlen( $sql );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $sql[ $i ];
			$next_char = ( $i + 1 < $length ) ? $sql[ $i + 1 ] : '';

			if ( ! $in_string ) {
				if ( $char === "'" || $char === '"' ) {
					$in_string = true;
					$string_char = $char;
					$current_query .= $char;
				} elseif ( $char === ';' ) {
					$current_query .= $char;
					$queries[] = $current_query;
					$current_query = '';
				} else {
					$current_query .= $char;
				}
			} else {
				$current_query .= $char;
				if ( $char === $string_char && $sql[ $i - 1 ] !== '\\' ) {
					$in_string = false;
					$string_char = '';
				}
			}
		}

		if ( ! empty( trim( $current_query ) ) ) {
			$queries[] = $current_query;
		}

		return $queries;
	}
}

