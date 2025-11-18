<?php
/**
 * Error handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Handler class for error handling
 */
class MksDdn_MC_Handler {

	/**
	 * Register error handlers
	 *
	 * @return void
	 */
	public static function register() {
		set_error_handler( array( __CLASS__, 'error_handler' ) );
		set_exception_handler( array( __CLASS__, 'exception_handler' ) );
		register_shutdown_function( array( __CLASS__, 'shutdown_handler' ) );
	}

	/**
	 * Error handler
	 *
	 * @param int    $errno Error number
	 * @param string $errstr Error message
	 * @param string $errfile Error file
	 * @param int    $errline Error line
	 * @return bool
	 */
	public static function error_handler( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}

		$error_types = array(
			E_ERROR             => 'Error',
			E_WARNING           => 'Warning',
			E_PARSE             => 'Parse Error',
			E_NOTICE            => 'Notice',
			E_CORE_ERROR        => 'Core Error',
			E_CORE_WARNING      => 'Core Warning',
			E_COMPILE_ERROR     => 'Compile Error',
			E_COMPILE_WARNING   => 'Compile Warning',
			E_USER_ERROR        => 'User Error',
			E_USER_WARNING      => 'User Warning',
			E_USER_NOTICE       => 'User Notice',
			E_STRICT            => 'Strict Notice',
			E_RECOVERABLE_ERROR => 'Recoverable Error',
			E_DEPRECATED        => 'Deprecated',
			E_USER_DEPRECATED   => 'User Deprecated',
		);

		$error_type = isset( $error_types[ $errno ] ) ? $error_types[ $errno ] : 'Unknown Error';
		$error_message = sprintf( '%s: %s in %s on line %d', $error_type, $errstr, $errfile, $errline );

		MksDdn_MC_Log::error( $error_message );

		return false;
	}

	/**
	 * Exception handler
	 *
	 * @param Exception $exception Exception object
	 * @return void
	 */
	public static function exception_handler( $exception ) {
		$error_message = sprintf(
			'Uncaught Exception: %s in %s on line %d',
			$exception->getMessage(),
			$exception->getFile(),
			$exception->getLine()
		);

		MksDdn_MC_Log::error( $error_message );

		if ( MKSDDN_MC_DEBUG ) {
			echo '<pre>' . esc_html( $error_message ) . '</pre>';
		}
	}

	/**
	 * Shutdown handler
	 *
	 * @return void
	 */
	public static function shutdown_handler() {
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) {
			$error_message = sprintf(
				'Fatal Error: %s in %s on line %d',
				$error['message'],
				$error['file'],
				$error['line']
			);

			MksDdn_MC_Log::error( $error_message );
		}
	}
}

