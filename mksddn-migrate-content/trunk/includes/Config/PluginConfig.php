<?php
/**
 * @file: PluginConfig.php
 * @description: Centralized configuration management for the plugin
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Config;

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
		return apply_filters( 'mksddn_mc_text_domain', MKSDDN_MC_TEXT_DOMAIN );
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
}

