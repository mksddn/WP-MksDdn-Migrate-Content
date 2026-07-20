<?php
/**
 * @file: ExportMemoryHelper.php
 * @description: Safely raises PHP memory_limit for full-site export within host-safe caps
 * @dependencies: PluginConfig
 * @created: 2026-03-20
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporarily increases memory for export within a hard ceiling.
 *
 * Intentionally does NOT request multi-GB limits: on small VPS that triggers
 * the Linux OOM killer and can take down the entire PHP-FPM pool.
 */
class ExportMemoryHelper {

	/**
	 * Apply a modest WordPress admin memory boost, capped by PluginConfig.
	 *
	 * @return string Original memory_limit value for restore().
	 */
	public static function raise_for_export(): string {
		$original = (string) ini_get( 'memory_limit' );
		if ( self::is_unlimited( $original ) ) {
			return $original;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$current_str   = (string) ini_get( 'memory_limit' );
		$current_bytes = wp_convert_hr_to_bytes( $current_str );
		if ( $current_bytes <= 0 || self::is_unlimited( $current_str ) ) {
			return $original;
		}

		$min_bytes = PluginConfig::min_export_memory_limit();
		$max_bytes = PluginConfig::max_export_memory_limit();

		// Never exceed the hard ceiling, even if min filter is raised aggressively.
		$target = min( max( $current_bytes, $min_bytes ), $max_bytes );

		if ( $target > $current_bytes ) {
			if ( function_exists( 'wp_is_ini_value_changeable' ) && ! wp_is_ini_value_changeable( 'memory_limit' ) ) {
				return $original;
			}
			$target_mb = (int) ceil( $target / ( 1024 * 1024 ) );
			@ini_set( 'memory_limit', $target_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		return $original;
	}

	/**
	 * Restore previous memory_limit after export.
	 *
	 * @param string $original Value returned by raise_for_export().
	 */
	public static function restore( string $original ): void {
		if ( '' === $original || self::is_unlimited( $original ) ) {
			return;
		}

		if ( function_exists( 'wp_is_ini_value_changeable' ) && ! wp_is_ini_value_changeable( 'memory_limit' ) ) {
			return;
		}

		@ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
	}

	/**
	 * Whether current memory usage is critically high relative to memory_limit.
	 */
	public static function is_memory_critical(): bool {
		$limit_str = (string) ini_get( 'memory_limit' );
		if ( self::is_unlimited( $limit_str ) ) {
			return false;
		}

		$limit = wp_convert_hr_to_bytes( $limit_str );
		if ( $limit <= 0 ) {
			return false;
		}

		$used  = memory_get_usage( true );
		$ratio = PluginConfig::export_memory_abort_ratio();

		return ( $used / $limit ) >= $ratio;
	}

	/**
	 * Whether PHP memory_limit is unlimited.
	 *
	 * @param string $limit Raw ini value.
	 */
	private static function is_unlimited( string $limit ): bool {
		$limit = trim( $limit );
		return '-1' === $limit || (int) $limit < 0;
	}
}
