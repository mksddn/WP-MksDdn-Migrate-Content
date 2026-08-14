<?php
/**
 * @file: PluginLogger.php
 * @description: Centralized plugin logging independent of WP_DEBUG
 * @dependencies: Config\PluginConfig
 * @created: 2026-07-06
 */

namespace MksDdn\MigrateContent\Services;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes plugin logs to a private directory and PHP error_log.
 *
 * Falls back to uploads-based logs when private storage is unavailable.
 *
 * @since 2.3.2
 */
class PluginLogger {

	/**
	 * Log file name inside the logs directory.
	 */
	private const LOG_FILENAME = 'migrate.log';

	/**
	 * Rotate log when it exceeds this size (5 MB).
	 */
	private const MAX_LOG_BYTES = 5242880;

	/**
	 * Check whether plugin logging is enabled.
	 *
	 * Disable with `define( 'MKSDDN_MC_DISABLE_LOGGING', true );` in wp-config.php.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		if ( defined( 'MKSDDN_MC_DISABLE_LOGGING' ) && MKSDDN_MC_DISABLE_LOGGING ) {
			return false;
		}

		/**
		 * Filter plugin logging.
		 *
		 * @param bool $enabled Whether logging is enabled.
		 * @since 2.3.2
		 */
		return (bool) apply_filters( 'mksddn_mc_logging_enabled', true );
	}

	/**
	 * Absolute path to the active log file.
	 *
	 * @return string
	 */
	public static function log_path(): string {
		return trailingslashit( PluginConfig::logs_dir() ) . self::LOG_FILENAME;
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $message Log message.
	 * @param string $context Optional context label (component name).
	 * @return void
	 */
	public static function log( string $message, string $context = '' ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$line = self::format_line( $message, $context );

		self::write_file( $line );

		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional plugin logging.
			error_log( 'MksDdn Migrate Content: ' . $line );
		}
	}

	/**
	 * Build a single log line with optional context prefix.
	 *
	 * @param string $message Log message.
	 * @param string $context Optional context label.
	 * @return string
	 */
	private static function format_line( string $message, string $context ): string {
		if ( '' !== $context ) {
			return sprintf( '[%s] %s', $context, $message );
		}

		return $message;
	}

	/**
	 * Append a timestamped entry to the plugin log file.
	 *
	 * @param string $line Formatted log line.
	 * @return void
	 */
	private static function write_file( string $line ): void {
		$entry = sprintf( '[%s] %s%s', gmdate( 'Y-m-d H:i:s' ), $line, PHP_EOL );

		foreach ( self::candidate_directories() as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				continue;
			}

			FilesystemHelper::protect_directory_from_web( $dir );

			$path = trailingslashit( $dir ) . self::LOG_FILENAME;
			self::maybe_rotate( $path );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Dedicated plugin log file.
			$written = @file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX );
			if ( false !== $written ) {
				return;
			}
		}
	}

	/**
	 * Build prioritized list of log directories.
	 *
	 * @return array<int, string>
	 */
	private static function candidate_directories(): array {
		$primary = trailingslashit( PluginConfig::logs_dir() );
		$uploads = trailingslashit( PluginConfig::uploads_base_dir() ) . 'logs/';
		$temp    = trailingslashit( sys_get_temp_dir() ) . 'mksddn-mc-logs/';

		return array_values(
			array_unique(
				array(
					$primary,
					trailingslashit( $uploads ),
					trailingslashit( $temp ),
				)
			)
		);
	}

	/**
	 * Rotate the log file when it grows too large.
	 *
	 * @param string $path Active log file path.
	 * @return void
	 */
	private static function maybe_rotate( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}

		$size = filesize( $path );

		if ( false === $size || $size < self::MAX_LOG_BYTES ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		@rename( $path, $path . '.1' );
	}
}
