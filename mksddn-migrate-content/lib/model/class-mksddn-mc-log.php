<?php
/**
 * Log handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Log class for error and operation logging
 */
class MksDdn_MC_Log {

	/**
	 * Log file path
	 *
	 * @var string
	 */
	private static $log_file = null;

	/**
	 * Get log file path
	 *
	 * @return string
	 */
	private static function get_log_file() {
		if ( self::$log_file === null ) {
			self::$log_file = MKSDDN_MC_ERROR_FILE;
		}
		return self::$log_file;
	}

	/**
	 * Write log entry
	 *
	 * @param string $message Log message
	 * @param string $level Log level (error, warning, info)
	 * @return void
	 */
	public static function write( $message, $level = 'info' ) {
		if ( ! MKSDDN_MC_DEBUG && $level !== 'error' ) {
			return;
		}

		$log_file = self::get_log_file();
		$timestamp = date( 'Y-m-d H:i:s' );
		$log_entry = sprintf( '[%s] [%s] %s%s', $timestamp, strtoupper( $level ), $message, PHP_EOL );

		@file_put_contents( $log_file, $log_entry, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Log error
	 *
	 * @param string $message Error message
	 * @return void
	 */
	public static function error( $message ) {
		self::write( $message, 'error' );
	}

	/**
	 * Log warning
	 *
	 * @param string $message Warning message
	 * @return void
	 */
	public static function warning( $message ) {
		self::write( $message, 'warning' );
	}

	/**
	 * Log info
	 *
	 * @param string $message Info message
	 * @return void
	 */
	public static function info( $message ) {
		self::write( $message, 'info' );
	}

	/**
	 * Clear log file
	 *
	 * @return bool
	 */
	public static function clear() {
		$log_file = self::get_log_file();
		if ( file_exists( $log_file ) ) {
			return @unlink( $log_file );
		}
		return true;
	}

	/**
	 * Get log content
	 *
	 * @param int $lines Number of lines to retrieve
	 * @return string
	 */
	public static function get_content( $lines = 100 ) {
		$log_file = self::get_log_file();
		if ( ! file_exists( $log_file ) ) {
			return '';
		}

		$content = @file_get_contents( $log_file );
		if ( $content === false ) {
			return '';
		}

		$content_lines = explode( PHP_EOL, $content );
		$content_lines = array_filter( $content_lines );
		$content_lines = array_slice( $content_lines, -$lines );

		return implode( PHP_EOL, $content_lines );
	}
}

