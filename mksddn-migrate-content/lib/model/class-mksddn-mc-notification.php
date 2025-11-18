<?php
/**
 * Notification model
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Notification class
 */
class MksDdn_MC_Notification {

	/**
	 * Add admin notice
	 *
	 * @param string $message Message text
	 * @param string $type Notice type (success, error, warning, info)
	 * @param bool   $dismissible Is dismissible
	 * @return void
	 */
	public static function add( $message, $type = 'info', $dismissible = true ) {
		$notices = get_transient( 'mksddn_mc_notices' );
		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		$notices[] = array(
			'message'     => $message,
			'type'        => $type,
			'dismissible' => $dismissible,
		);

		set_transient( 'mksddn_mc_notices', $notices, 60 );
	}

	/**
	 * Get all notices
	 *
	 * @return array
	 */
	public static function get_all() {
		$notices = get_transient( 'mksddn_mc_notices' );
		if ( ! is_array( $notices ) ) {
			return array();
		}

		// Delete notices after retrieval
		delete_transient( 'mksddn_mc_notices' );

		return $notices;
	}

	/**
	 * Display admin notices
	 *
	 * @return void
	 */
	public static function display() {
		$notices = self::get_all();

		foreach ( $notices as $notice ) {
			$class = 'notice notice-' . esc_attr( $notice['type'] );
			if ( $notice['dismissible'] ) {
				$class .= ' is-dismissible';
			}

			printf(
				'<div class="%1$s"><p>%2$s</p></div>',
				esc_attr( $class ),
				wp_kses_post( $notice['message'] )
			);
		}
	}

	/**
	 * Add success notice
	 *
	 * @param string $message Message text
	 * @return void
	 */
	public static function success( $message ) {
		self::add( $message, 'success' );
	}

	/**
	 * Add error notice
	 *
	 * @param string $message Message text
	 * @return void
	 */
	public static function error( $message ) {
		self::add( $message, 'error' );
	}

	/**
	 * Add warning notice
	 *
	 * @param string $message Message text
	 * @return void
	 */
	public static function warning( $message ) {
		self::add( $message, 'warning' );
	}

	/**
	 * Add info notice
	 *
	 * @param string $message Message text
	 * @return void
	 */
	public static function info( $message ) {
		self::add( $message, 'info' );
	}
}

