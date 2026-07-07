<?php
/**
 * @file: PluginConfig.php
 * @description: Centralized configuration management for the plugin
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Config;

use MksDdn\MigrateContent\Services\PluginLogger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized plugin configuration.
 *
 * @since 1.0.0
 */
class PluginConfig {

	/**
	 * Get plugin version.
	 *
	 * @return string Plugin version.
	 * @since 1.0.0
	 */
	public static function version(): string {
		return apply_filters( 'mksddn_mc_version', MKSDDN_MC_VERSION );
	}

	/**
	 * Get plugin file path.
	 *
	 * @return string Plugin file path.
	 * @since 1.0.0
	 */
	public static function file(): string {
		return apply_filters( 'mksddn_mc_file', MKSDDN_MC_FILE );
	}

	/**
	 * Get plugin directory path.
	 *
	 * @return string Plugin directory path.
	 * @since 1.0.0
	 */
	public static function dir(): string {
		return apply_filters( 'mksddn_mc_dir', MKSDDN_MC_DIR );
	}

	/**
	 * Get plugin URL.
	 *
	 * @return string Plugin URL.
	 * @since 1.0.0
	 */
	public static function url(): string {
		return apply_filters( 'mksddn_mc_url', MKSDDN_MC_URL );
	}

	/**
	 * Get text domain.
	 *
	 * @return string Text domain.
	 * @since 1.0.0
	 */
	public static function text_domain(): string {
		$filtered = apply_filters( 'mksddn_mc_text_domain', MKSDDN_MC_TEXT_DOMAIN );
		if ( ! is_string( $filtered ) ) {
			return MKSDDN_MC_TEXT_DOMAIN;
		}

		$slug = sanitize_key( $filtered );
		if ( '' === $slug || ! str_starts_with( $slug, 'mksddn-' ) ) {
			return MKSDDN_MC_TEXT_DOMAIN;
		}

		return $slug;
	}

	/**
	 * Check if chunked transfers are disabled.
	 *
	 * @return bool True if disabled, false otherwise.
	 * @since 1.0.0
	 */
	public static function is_chunked_disabled(): bool {
		if ( ! defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) ) {
			return false;
		}

		return apply_filters( 'mksddn_mc_disable_chunked', MKSDDN_MC_DISABLE_CHUNKED );
	}

	/**
	 * Check if JSON export debug is enabled.
	 *
	 * @return bool True if enabled, false otherwise.
	 * @since 1.0.0
	 */
	public static function is_json_export_debug_enabled(): bool {
		if ( ! defined( 'MKSDDN_MC_DEBUG_JSON_EXPORT' ) ) {
			return false;
		}

		return apply_filters( 'mksddn_mc_debug_json_export', MKSDDN_MC_DEBUG_JSON_EXPORT );
	}

	/**
	 * Get chunk size for transfers.
	 *
	 * @return int Chunk size in bytes.
	 * @since 1.0.0
	 */
	public static function chunk_size(): int {
		return apply_filters( 'mksddn_mc_chunk_size', 1024 * 1024 ); // 1MB default.
	}

	/**
	 * Get maximum upload size.
	 *
	 * @return int Maximum upload size in bytes.
	 * @since 1.0.0
	 */
	public static function max_upload_size(): int {
		$wp_max = wp_max_upload_size();
		return apply_filters( 'mksddn_mc_max_upload_size', $wp_max );
	}

	/**
	 * Get assets directory path.
	 *
	 * @return string Assets directory path.
	 * @since 1.0.0
	 */
	public static function assets_dir(): string {
		return self::dir() . 'assets/';
	}

	/**
	 * Get assets URL.
	 *
	 * @return string Assets URL.
	 * @since 1.0.0
	 */
	public static function assets_url(): string {
		return self::url() . 'assets/';
	}

	/**
	 * Get includes directory path.
	 *
	 * @return string Includes directory path.
	 * @since 1.0.0
	 */
	public static function includes_dir(): string {
		return self::dir() . 'includes/';
	}

	/**
	 * Get languages directory path.
	 *
	 * @return string Languages directory path.
	 * @since 1.0.0
	 */
	public static function languages_dir(): string {
		return self::dir() . 'languages/';
	}

	/**
	 * Get imports directory path for server-uploaded backup files.
	 *
	 * @return string Imports directory path.
	 * @since 1.0.1
	 */
	public static function imports_dir(): string {
		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'];
		$default = trailingslashit( $base ) . 'mksddn-mc/imports/';
		return apply_filters( 'mksddn_mc_imports_dir', $default );
	}

	/**
	 * Get base uploads directory for plugin.
	 *
	 * @return string Base directory path.
	 * @since 1.0.1
	 */
	public static function uploads_base_dir(): string {
		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'];
		return trailingslashit( $base ) . 'mksddn-mc/';
	}

	/**
	 * Get logs directory path for plugin diagnostics.
	 *
	 * @return string Logs directory path.
	 * @since 2.3.2
	 */
	public static function logs_dir(): string {
		$uploads_default = trailingslashit( self::uploads_base_dir() ) . 'logs/';
		$private_default = trailingslashit( sys_get_temp_dir() ) . 'mksddn-mc-logs/';
		$preferred_dir   = (string) apply_filters( 'mksddn_mc_logs_dir', $uploads_default );

		if ( '' === $preferred_dir ) {
			$preferred_dir = $uploads_default;
		}

		$candidates = array(
			trailingslashit( $preferred_dir ),
			trailingslashit( $uploads_default ),
			trailingslashit( $private_default ),
		);

		foreach ( $candidates as $candidate ) {
			if ( ! is_dir( $candidate ) && ! wp_mkdir_p( $candidate ) ) {
				continue;
			}

			if ( self::is_writable_directory( $candidate ) ) {
				return $candidate;
			}
		}

		return trailingslashit( $uploads_default );
	}

	/**
	 * Check whether a directory is writable by creating a probe file.
	 *
	 * @param string $dir Directory path.
	 * @return bool True when writable, false otherwise.
	 * @since 2.3.3
	 */
	private static function is_writable_directory( string $dir ): bool {
		$probe = trailingslashit( $dir ) . '.mksddn-mc-write-test';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = @file_put_contents( $probe, '1' );
		if ( false === $written ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $probe );

		return true;
	}

	// ==========================================================================
	// Import Memory/Size Limits
	// ==========================================================================

	/**
	 * Maximum JSON payload size for import (bytes).
	 *
	 * @return int Size in bytes (default 5GB to support very large databases).
	 * @since 1.0.0
	 */
	public static function max_import_json_size(): int {
		return apply_filters( 'mksddn_mc_max_import_json_size', 5 * 1024 * 1024 * 1024 );
	}

	/**
	 * Minimum memory limit for large imports (bytes).
	 *
	 * @return int Size in bytes (default 1GB).
	 * @since 1.0.0
	 */
	public static function min_import_memory_limit(): int {
		return apply_filters( 'mksddn_mc_min_import_memory_limit', 1024 * 1024 * 1024 );
	}

	/**
	 * Maximum memory limit for imports (bytes).
	 *
	 * @return int Size in bytes (default 8GB to support very large databases).
	 * @since 1.0.0
	 */
	public static function max_import_memory_limit(): int {
		return apply_filters( 'mksddn_mc_max_import_memory_limit', 8 * 1024 * 1024 * 1024 );
	}

	// ==========================================================================
	// Export Memory Limits
	// ==========================================================================

	/**
	 * Minimum memory limit for full-site export (bytes).
	 *
	 * Full export builds a DB dump in RAM and JSON-encodes it; use
	 * filter `mksddn_mc_min_export_memory_limit` for heavier sites (e.g. 2560M).
	 *
	 * @return int Size in bytes (default 2560MB — full export holds DB + JSON in RAM).
	 * @since 2.1.2
	 */
	public static function min_export_memory_limit(): int {
		return apply_filters( 'mksddn_mc_min_export_memory_limit', 2560 * 1024 * 1024 );
	}

	/**
	 * Maximum memory limit for full-site export (bytes).
	 *
	 * @return int Size in bytes (default 8GB).
	 * @since 2.1.2
	 */
	public static function max_export_memory_limit(): int {
		return apply_filters( 'mksddn_mc_max_export_memory_limit', 8 * 1024 * 1024 * 1024 );
	}

	/**
	 * Database row chunk size for large tables.
	 *
	 * @return int Number of rows per chunk (default 2000).
	 * @since 1.0.0
	 */
	public static function db_row_chunk_size(): int {
		return apply_filters( 'mksddn_mc_db_row_chunk_size', 2000 );
	}

	/**
	 * Threshold for large table processing (rows).
	 *
	 * @return int Number of rows (default 5000).
	 * @since 1.0.0
	 */
	public static function large_table_threshold(): int {
		return apply_filters( 'mksddn_mc_large_table_threshold', 5000 );
	}

	/**
	 * Get all required plugin directories.
	 *
	 * @return array Array of directory paths.
	 * @since 1.0.1
	 */
	public static function get_required_directories(): array {
		$base = self::uploads_base_dir();

		return array(
			'base'    => $base,
			'jobs'    => $base . 'jobs/',
			'imports' => $base . 'imports/',
			'logs'    => self::logs_dir(),
		);
	}

	/**
	 * Create all required plugin directories.
	 *
	 * @return bool|WP_Error True if all directories were created successfully, WP_Error with failed directories otherwise.
	 * @since 1.0.1
	 */
	public static function create_required_directories(): bool|WP_Error {
		$directories = self::get_required_directories();
		$failed = array();

		foreach ( $directories as $key => $dir ) {
			if ( ! is_dir( $dir ) ) {
				if ( ! wp_mkdir_p( $dir ) ) {
					$failed[ $key ] = $dir;
					PluginLogger::log(
						sprintf( 'Failed to create directory: %s', $dir ),
						'PluginConfig'
					);
				}
			}
		}

		if ( ! empty( $failed ) ) {
			return new WP_Error(
				'mksddn_mc_directories_creation_failed',
				sprintf(
					/* translators: %s: list of failed directories */
					__( 'Failed to create required directories: %s', 'mksddn-migrate-content' ),
					implode( ', ', $failed )
				),
				$failed
			);
		}

		return true;
	}
}

