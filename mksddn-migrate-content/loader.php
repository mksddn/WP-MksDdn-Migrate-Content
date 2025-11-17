<?php
/**
 * Class loader
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Autoloader for plugin classes
 *
 * @param string $class_name Class name
 * @return void
 */
function mksddn_mc_autoloader( $class_name ) {
	if ( strpos( $class_name, 'MksDdn_MC_' ) !== 0 ) {
		return;
	}

	$class_name = str_replace( 'MksDdn_MC_', '', $class_name );
	$class_name = str_replace( '_', DIRECTORY_SEPARATOR, $class_name );
	$class_name = strtolower( $class_name );

	$file_paths = array(
		MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-' . $class_name . '.php',
		MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-' . $class_name . '.php',
		MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-' . $class_name . '.php',
	);

	foreach ( $file_paths as $file_path ) {
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}

	// Try subdirectories
	$subdirs = array(
		MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'export',
		MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'import',
	);

	foreach ( $subdirs as $subdir ) {
		$file_path = $subdir . DIRECTORY_SEPARATOR . 'class-' . $class_name . '.php';
		if ( file_exists( $file_path ) ) {
			require_once $file_path;
			return;
		}
	}
}

spl_autoload_register( 'mksddn_mc_autoloader' );

// Load main controller
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-main-controller.php';

