<?php
/**
 * index.php file handler
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * index.php file operations class
 */
class MksDdn_MC_File_Index {

	/**
	 * Create index.php file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function create( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'index.php';
		$content   = '<?php' . PHP_EOL . '// Silence is golden' . PHP_EOL;

		return MksDdn_MC_File::write( $file_path, $content );
	}

	/**
	 * Delete index.php file
	 *
	 * @param string $path Directory path
	 * @return bool
	 */
	public static function delete( $path ) {
		$file_path = $path . DIRECTORY_SEPARATOR . 'index.php';
		return MksDdn_MC_File::delete( $file_path );
	}
}

