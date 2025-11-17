<?php
/**
 * web.config file handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * web.config file operations class
 */
class MksDdn_MC_File_Webconfig {

	/**
	 * Create web.config file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function create( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'web.config';
		$content   = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
			. '<configuration>' . PHP_EOL
			. '  <system.webServer>' . PHP_EOL
			. '    <authorization>' . PHP_EOL
			. '      <deny users="*" />' . PHP_EOL
			. '    </authorization>' . PHP_EOL
			. '  </system.webServer>' . PHP_EOL
			. '</configuration>' . PHP_EOL;

		return MksDdn_MC_File::write( $file_path, $content );
	}

	/**
	 * Delete web.config file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function delete( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'web.config';
		return MksDdn_MC_File::delete( $file_path );
	}
}

