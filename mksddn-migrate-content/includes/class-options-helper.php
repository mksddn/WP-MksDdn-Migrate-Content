<?php
/**
 * Options helper class.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options helper class.
 */
class MksDdn_MC_Options_Helper {

	/**
	 * Option name prefix.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'mksddn_mc_';

	/**
	 * Initialize default options.
	 */
	public function init_default_options() {
		$defaults = array(
			'version'           => MKSDDN_MC_VERSION,
			'max_upload_size'   => wp_max_upload_size(),
			'export_history'    => array(),
			'import_history'    => array(),
		);

		foreach ( $defaults as $key => $value ) {
			$option_name = self::OPTION_PREFIX . $key;
			if ( false === get_option( $option_name ) ) {
				add_option( $option_name, $value );
			}
		}
	}

	/**
	 * Get option value.
	 *
	 * @param string $key Option key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get_option( $key, $default = false ) {
		return get_option( self::OPTION_PREFIX . $key, $default );
	}

	/**
	 * Update option value.
	 *
	 * @param string $key Option key.
	 * @param mixed  $value Option value.
	 * @return bool
	 */
	public static function update_option( $key, $value ) {
		return update_option( self::OPTION_PREFIX . $key, $value );
	}

	/**
	 * Delete option.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	public static function delete_option( $key ) {
		return delete_option( self::OPTION_PREFIX . $key );
	}

	/**
	 * Add history entry.
	 *
	 * @param string $type Type (export or import).
	 * @param array  $data Entry data.
	 * @return bool
	 */
	public static function add_history_entry( $type, $data ) {
		$history_key = $type . '_history';
		$history     = self::get_option( $history_key, array() );

		$entry = array(
			'id'        => uniqid( 'migrate_', true ),
			'timestamp' => current_time( 'mysql' ),
			'data'      => $data,
		);

		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, 50 );

		return self::update_option( $history_key, $history );
	}

	/**
	 * Get history entries.
	 *
	 * @param string $type Type (export or import).
	 * @param int    $limit Limit.
	 * @return array
	 */
	public static function get_history( $type, $limit = 20 ) {
		$history_key = $type . '_history';
		$history     = self::get_option( $history_key, array() );
		return array_slice( $history, 0, $limit );
	}
}

