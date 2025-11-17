<?php
/**
 * Status handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Status class for managing operation status
 */
class MksDdn_MC_Status {

	/**
	 * Log error status
	 *
	 * @param string $title Error title
	 * @param string $message Error message
	 * @return void
	 */
	public static function error( $title, $message ) {
		self::log( array(
			'type'    => 'error',
			'title'   => $title,
			'message' => $message,
		) );
	}

	/**
	 * Log info status
	 *
	 * @param string $message Info message
	 * @return void
	 */
	public static function info( $message ) {
		self::log( array(
			'type'    => 'info',
			'message' => $message,
		) );
	}

	/**
	 * Log download status
	 *
	 * @param string $message Download message
	 * @return void
	 */
	public static function download( $message ) {
		self::log( array(
			'type'    => 'download',
			'message' => $message,
		) );
	}

	/**
	 * Log done status
	 *
	 * @param string $title Done title
	 * @param string|null $message Done message
	 * @return void
	 */
	public static function done( $title, $message = null ) {
		self::log( array(
			'type'    => 'done',
			'title'   => $title,
			'message' => $message,
		) );
	}

	/**
	 * Log progress
	 *
	 * @param int $percent Progress percent
	 * @return void
	 */
	public static function progress( $percent ) {
		self::log( array(
			'type'    => 'progress',
			'percent' => $percent,
		) );
	}

	/**
	 * Log status data
	 *
	 * @param array $data Status data
	 * @return void
	 */
	public static function log( $data ) {
		update_option( MKSDDN_MC_STATUS, $data );
	}

	/**
	 * Get current status
	 *
	 * @return array
	 */
	public static function get() {
		return get_option( MKSDDN_MC_STATUS, array() );
	}

	/**
	 * Clear status
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( MKSDDN_MC_STATUS );
	}
}

