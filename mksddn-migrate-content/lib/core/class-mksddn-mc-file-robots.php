<?php
/**
 * robots.txt file handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * robots.txt file operations class
 */
class MksDdn_MC_File_Robots {

	/**
	 * Create robots.txt file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function create( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'robots.txt';
		$content   = "User-agent: *\nDisallow: /\n";

		return MksDdn_MC_File::write( $file_path, $content );
	}

	/**
	 * Delete robots.txt file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function delete( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'robots.txt';
		return MksDdn_MC_File::delete( $file_path );
	}
}

