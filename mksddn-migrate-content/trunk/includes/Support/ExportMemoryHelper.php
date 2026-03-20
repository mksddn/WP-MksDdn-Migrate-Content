<?php
/**
 * @file: ExportMemoryHelper.php
 * @description: Raises PHP memory_limit for full-site export when ini_set is allowed
 * @dependencies: PluginConfig
 * @created: 2026-03-20
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Temporarily increases memory for building large JSON payloads during export.
 */
class ExportMemoryHelper {

	/**
	 * Apply WordPress admin memory boost and optional ini_set up to configured bounds.
	 *
	 * @return string Original memory_limit value for restore().
	 */
	public static function raise_for_export(): string {
		$original = ini_get( 'memory_limit' );
		if ( self::is_unlimited( $original ) ) {
			return $original;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$current_str   = ini_get( 'memory_limit' );
		$current_bytes = wp_convert_hr_to_bytes( $current_str );
		if ( $current_bytes <= 0 || self::is_unlimited( $current_str ) ) {
			return $original;
		}

		$min_bytes = PluginConfig::min_export_memory_limit();
		$max_bytes = PluginConfig::max_export_memory_limit();
		$target    = max( $current_bytes, $min_bytes );
		$target    = min( $target, $max_bytes );

		if ( $target > $current_bytes ) {
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

		@ini_set( 'memory_limit', $original ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
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
