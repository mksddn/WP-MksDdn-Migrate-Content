<?php
/**
 * Settings model
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Settings class
 */
class MksDdn_MC_Settings {

	/**
	 * Option name prefix
	 */
	const OPTION_PREFIX = 'mksddn_mc_';

	/**
	 * Get setting value
	 *
	 * @param string $key Setting key
	 * @param mixed  $default Default value
	 * @return mixed
	 */
	public static function get( $key, $default = false ) {
		return get_option( self::OPTION_PREFIX . $key, $default );
	}

	/**
	 * Set setting value
	 *
	 * @param string $key Setting key
	 * @param mixed  $value Setting value
	 * @return bool
	 */
	public static function set( $key, $value ) {
		return update_option( self::OPTION_PREFIX . $key, $value );
	}

	/**
	 * Delete setting
	 *
	 * @param string $key Setting key
	 * @return bool
	 */
	public static function delete( $key ) {
		return delete_option( self::OPTION_PREFIX . $key );
	}

	/**
	 * Get all settings
	 *
	 * @return array
	 */
	public static function get_all() {
		$settings = array(
			'exclude_files'        => self::get( 'exclude_files', array() ),
			'exclude_directories'  => self::get( 'exclude_directories', array() ),
			'exclude_extensions'   => self::get( 'exclude_extensions', array() ),
			'exclude_tables'       => self::get( 'exclude_tables', array() ),
			'import_replace_urls'  => self::get( 'import_replace_urls', true ),
			'import_replace_paths' => self::get( 'import_replace_paths', true ),
			'backups_retention'    => self::get( 'backups_retention', 0 ),
			'backups_path'         => self::get( 'backups_path', MKSDDN_MC_DEFAULT_BACKUPS_PATH ),
		);

		return $settings;
	}

	/**
	 * Save all settings
	 *
	 * @param array $settings Settings array
	 * @return bool
	 */
	public static function save_all( $settings ) {
		$result = true;

		foreach ( $settings as $key => $value ) {
			if ( ! self::set( $key, $value ) ) {
				$result = false;
			}
		}

		return $result;
	}

	/**
	 * Get default exclude patterns
	 *
	 * @return array
	 */
	public static function get_default_excludes() {
		return array(
			'files'      => array( '.htaccess', 'web.config', 'wp-config.php' ),
			'directories' => array( 'node_modules', '.git', '.svn', 'cache' ),
			'extensions' => array( 'log', 'tmp', 'bak' ),
		);
	}
}

