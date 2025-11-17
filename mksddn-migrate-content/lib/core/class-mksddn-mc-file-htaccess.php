<?php
/**
 * .htaccess file handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * .htaccess file operations class
 */
class MksDdn_MC_File_Htaccess {

	/**
	 * Create .htaccess file
	 *
	 * @param string $path Directory path
	 * @param string $content File content
	 * @return bool
	 */
	public static function create( $path, $content = '' ) {
		$file_path = $path . DIRECTORY_SEPARATOR . '.htaccess';

		if ( empty( $content ) ) {
			$content = self::get_default_content();
		}

		return MksDdn_MC_File::write( $file_path, $content );
	}

	/**
	 * Delete .htaccess file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function delete( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . '.htaccess';
		return MksDdn_MC_File::delete( $file_path );
	}

	/**
	 * Get default .htaccess content
	 *
	 * @return string
	 */
	private static function get_default_content() {
		return "# Deny access to all files\nOrder Deny,Allow\nDeny from all\n";
	}
}

