<?php
/**
 * Export configuration
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Export configuration class
 */
class MksDdn_MC_Export_Config {

	/**
	 * Execute configuration
	 *
	 * @param array $params Export parameters
	 * @return array
	 */
	public static function execute( $params ) {
		MksDdn_MC_Status::info( __( 'Creating configuration file...', 'mksddn-migrate-content' ) );

		$config = array(
			'version'        => MKSDDN_MC_VERSION,
			'wordpress'      => array(
				'version' => get_bloginfo( 'version' ),
				'url'     => site_url(),
				'home'    => home_url(),
			),
			'php'            => array(
				'version' => PHP_VERSION,
			),
			'database'       => array(
				'name'     => DB_NAME,
				'charset'  => DB_CHARSET,
				'collate'  => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
			),
			'export_date'    => current_time( 'mysql' ),
			'export_version' => MKSDDN_MC_VERSION,
		);

		$params['config'] = $config;

		return $params;
	}
}

