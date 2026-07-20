<?php
/**
 * @file: ExportPreflight.php
 * @description: Pre-flight checks before full-site export (DB size, free disk, memory floor)
 * @dependencies: PluginConfig, wpdb
 * @created: 2026-07-17
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;
use WP_Error;
use wpdb;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates that the host can safely attempt a full-site export.
 */
class ExportPreflight {

	/**
	 * Run checks; return true or WP_Error with a user-facing message.
	 *
	 * @return true|WP_Error
	 */
	public function validate_full_export() {
		global $wpdb;

		$db_bytes = $this->estimate_database_bytes( $wpdb );
		$temp_dir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$free     = function_exists( 'disk_free_space' ) ? @disk_free_space( $temp_dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort preflight

		$multiplier = PluginConfig::export_disk_safety_multiplier();
		$headroom   = PluginConfig::export_disk_min_headroom();
		$needed     = (int) ceil( ( $db_bytes * $multiplier ) + $headroom );

		if ( false !== $free && $free < $needed ) {
			return new WP_Error(
				'mksddn_mc_export_insufficient_disk',
				sprintf(
					/* translators: 1: required free space, 2: available free space, 3: temp directory path */
					__( 'Not enough free disk space for full-site export. About %1$s is required in the temp directory, but only %2$s is available (%3$s). Free disk space or export on a larger server / staging copy.', 'mksddn-migrate-content' ),
					size_format( $needed, 2 ),
					size_format( (int) $free, 2 ),
					$temp_dir
				),
				array(
					'status'   => 507,
					'needed'   => $needed,
					'free'     => (int) $free,
					'db_bytes' => $db_bytes,
					'hint'     => __( 'Full-site export writes a large temporary JSON dump and ZIP. Running out of disk or RAM can take down PHP-FPM for the whole site.', 'mksddn-migrate-content' ),
				)
			);
		}

		$memory_check = $this->validate_memory_floor();
		if ( is_wp_error( $memory_check ) ) {
			return $memory_check;
		}

		/**
		 * Filter to abort or customize full-site export preflight.
		 *
		 * @param true|WP_Error $result   Validation result.
		 * @param array         $context  Estimate context.
		 */
		return apply_filters(
			'mksddn_mc_export_preflight',
			true,
			array(
				'db_bytes' => $db_bytes,
				'needed'   => $needed,
				'free'     => false === $free ? null : (int) $free,
				'temp_dir' => $temp_dir,
			)
		);
	}

	/**
	 * Estimate total size of tables for the current blog prefix.
	 *
	 * @param wpdb $wpdb Database abstraction.
	 * @return int Bytes (Data_length + Index_length).
	 */
	public function estimate_database_bytes( wpdb $wpdb ): int {
		$like    = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$results = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- export preflight size estimate

		if ( ! is_array( $results ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $results as $row ) {
			$total += (int) ( $row['Data_length'] ?? 0 );
			$total += (int) ( $row['Index_length'] ?? 0 );
		}

		return max( 0, $total );
	}

	/**
	 * Ensure PHP has a usable memory_limit floor (or unlimited).
	 *
	 * @return true|WP_Error
	 */
	private function validate_memory_floor() {
		$limit_str = (string) ini_get( 'memory_limit' );
		$trimmed   = trim( $limit_str );
		if ( '-1' === $trimmed || (int) $trimmed < 0 ) {
			return true;
		}

		$limit = wp_convert_hr_to_bytes( $limit_str );
		$min   = 64 * 1024 * 1024;

		if ( $limit > 0 && $limit < $min ) {
			return new WP_Error(
				'mksddn_mc_export_low_memory',
				sprintf(
					/* translators: 1: current memory_limit, 2: recommended minimum */
					__( 'PHP memory_limit is too low for full-site export (%1$s). Raise it to at least %2$s (wp-config.php / php.ini) or run the export on a staging server with more resources.', 'mksddn-migrate-content' ),
					size_format( $limit, 0 ),
					size_format( $min, 0 )
				),
				array(
					'status' => 500,
					'hint'   => __( 'Do not set multi-GB memory_limit on small VPS hosts — that can trigger the OOM killer and stop PHP-FPM for all sites.', 'mksddn-migrate-content' ),
				)
			);
		}

		return true;
	}
}
