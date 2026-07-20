<?php
/**
 * @file: FullDatabaseExporter.php
 * @description: Streams WordPress database tables into JSON without loading the full dump into RAM
 * @dependencies: PluginConfig, ExportMemoryHelper, wpdb
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Database;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\ExportMemoryHelper;
use WP_Error;
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
	 * Stream a database dump object as JSON into an open writable stream.
	 *
	 * Writes: {"site_url":...,"home_url":...,"table_prefix":...,"paths":{...},"tables":{...}}
	 * Rows are read in chunks (PluginConfig::db_row_chunk_size()) so peak memory stays bounded.
	 *
	 * @param resource $stream Open writable stream.
	 * @return true|WP_Error
	 * @since 2.4.1
	 */
	public function stream_to( $stream ) {
		if ( ! is_resource( $stream ) ) {
			return new WP_Error(
				'mksddn_mc_export_stream',
				__( 'Invalid stream for database export.', 'mksddn-migrate-content' )
			);
		}

		global $wpdb;

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		$tables  = $this->detect_tables( $wpdb );
		$uploads = wp_upload_dir();
		$paths   = wp_json_encode(
			array(
				'root'    => function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH,
				'content' => WP_CONTENT_DIR,
				'uploads' => $uploads['basedir'],
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( false === $paths ) {
			return new WP_Error(
				'mksddn_mc_export_paths_json',
				__( 'Failed to encode export path metadata as JSON.', 'mksddn-migrate-content' )
			);
		}

		$wrote = $this->write(
			$stream,
			'{'
			. '"site_url":' . $this->encode_scalar( \get_option( 'siteurl' ) ) . ','
			. '"home_url":' . $this->encode_scalar( \home_url() ) . ','
			. '"table_prefix":' . $this->encode_scalar( $wpdb->prefix ) . ','
			. '"paths":' . $paths . ','
			. '"tables":{'
		);

		if ( is_wp_error( $wrote ) ) {
			return $wrote;
		}

		$first_table = true;
		foreach ( $tables as $table_name ) {
			if ( ! $first_table ) {
				$wrote = $this->write( $stream, ',' );
				if ( is_wp_error( $wrote ) ) {
					return $wrote;
				}
			}
			$first_table = false;

			$result = $this->stream_table( $wpdb, $stream, $table_name );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->write( $stream, '}}' );
	}

	/**
	 * Export all tables using the current blog prefix (in-memory; prefer stream_to()).
	 *
	 * Kept for backward compatibility with callers that expect an array.
	 * Large sites should use stream_to() instead.
	 *
	 * @global wpdb $wpdb WordPress DB abstraction.
	 * @return array<string, mixed>|WP_Error Database dump or error.
	 * @since 1.0.0
	 */
	public function export() {
		$temp = \wp_tempnam( 'mksddn-db-export-' );
		if ( ! $temp ) {
			return new WP_Error(
				'mksddn_mc_export_temp',
				__( 'Unable to create temporary file for database export.', 'mksddn-migrate-content' )
			);
		}

		$handle = fopen( $temp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming JSON requires native handle
		if ( ! $handle ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new WP_Error(
				'mksddn_mc_export_temp',
				__( 'Unable to open temporary file for database export.', 'mksddn-migrate-content' )
			);
		}

		$result = $this->stream_to( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( is_wp_error( $result ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return $result;
		}

		$json = file_get_contents( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- small-site BC path
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		if ( false === $json ) {
			return new WP_Error(
				'mksddn_mc_export_read',
				__( 'Unable to read temporary database export file.', 'mksddn-migrate-content' )
			);
		}

		$decoded = json_decode( $json, true );
		unset( $json );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'mksddn_mc_export_decode',
				__( 'Failed to decode streamed database export.', 'mksddn-migrate-content' )
			);
		}

		return $decoded;
	}

	/**
	 * Stream one table (schema + rows) into the JSON object.
	 *
	 * @param wpdb     $wpdb       Database object.
	 * @param resource $stream     Writable stream.
	 * @param string   $table_name Sanitized table name.
	 * @return true|WP_Error
	 */
	private function stream_table( wpdb $wpdb, $stream, string $table_name ) {
		$schema_row = $wpdb->get_row( "SHOW CREATE TABLE `{$table_name}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- schema export; table name from sanitized SHOW TABLES
		$schema     = is_array( $schema_row ) && isset( $schema_row[1] ) ? (string) $schema_row[1] : '';

		$wrote = $this->write(
			$stream,
			$this->encode_scalar( $table_name ) . ':{"schema":' . $this->encode_scalar( $schema ) . ',"rows":['
		);
		if ( is_wp_error( $wrote ) ) {
			return $wrote;
		}

		$chunk_size = max( 50, (int) PluginConfig::db_row_chunk_size() );
		$offset     = 0;
		$first_row  = true;

		while ( true ) {
			if ( ExportMemoryHelper::is_memory_critical() ) {
				$chunk_size = max( 50, (int) floor( $chunk_size / 2 ) );
				if ( ExportMemoryHelper::is_memory_critical() && $chunk_size <= 50 ) {
					return new WP_Error(
						'mksddn_mc_export_memory',
						sprintf(
							/* translators: %s: database table name */
							__( 'Export aborted while reading table %s: PHP memory usage is critically high. Free server memory, raise memory_limit modestly (not multi-GB on small VPS), or export from staging.', 'mksddn-migrate-content' ),
							$table_name
						),
						array(
							'status' => 500,
							'hint'   => __( 'A full-site export that exhausts RAM can trigger the Linux OOM killer and stop PHP-FPM for the whole site.', 'mksddn-migrate-content' ),
						)
					);
				}
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name sanitized via detect_tables(); LIMIT/OFFSET are integers
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table_name}` LIMIT %d OFFSET %d",
					$chunk_size,
					$offset
				),
				ARRAY_A
			);

			if ( ! is_array( $rows ) || array() === $rows ) {
				break;
			}

			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$encoded = wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
				if ( false === $encoded ) {
					return new WP_Error(
						'mksddn_mc_export_row_json',
						sprintf(
							/* translators: %s: database table name */
							__( 'Failed to encode a row from table %s as JSON.', 'mksddn-migrate-content' ),
							$table_name
						)
					);
				}

				$prefix = $first_row ? '' : ',';
				$first_row = false;
				$wrote     = $this->write( $stream, $prefix . $encoded );
				if ( is_wp_error( $wrote ) ) {
					return $wrote;
				}
			}

			$count = count( $rows );
			unset( $rows );
			$offset += $count;

			if ( $count < $chunk_size ) {
				break;
			}

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return $this->write( $stream, ']}' );
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

		return array_values(
			array_filter(
				array_map( 'sanitize_text_field', (array) $result )
			)
		);
	}

	/**
	 * JSON-encode a scalar/string value or fail hard.
	 *
	 * @param mixed $value Value to encode.
	 * @return string
	 */
	private function encode_scalar( $value ): string {
		$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return false === $encoded ? 'null' : $encoded;
	}

	/**
	 * Write bytes to stream.
	 *
	 * @param resource $stream Stream.
	 * @param string   $data   Data.
	 * @return true|WP_Error
	 */
	private function write( $stream, string $data ) {
		$bytes = strlen( $data );
		if ( 0 === $bytes ) {
			return true;
		}

		$written = fwrite( $stream, $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- streaming export
		if ( false === $written || $written < $bytes ) {
			return new WP_Error(
				'mksddn_mc_export_write',
				__( 'Failed to write database export data to temporary storage. Check free disk space.', 'mksddn-migrate-content' ),
				array(
					'status' => 507,
					'hint'   => __( 'Check free disk space, hosting quota, and PHP temp directory (sys_temp_dir / upload_tmp_dir).', 'mksddn-migrate-content' ),
				)
			);
		}

		return true;
	}
}
