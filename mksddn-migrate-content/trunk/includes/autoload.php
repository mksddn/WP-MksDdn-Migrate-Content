<?php
/**
 * Simple PSR-4–like autoloader for plugin classes.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'MksDdn\\MigrateContent\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative     = substr( $class, strlen( $prefix ) );
		$relative_path = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file          = MKSDDN_MC_DIR . 'includes/' . $relative_path . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

