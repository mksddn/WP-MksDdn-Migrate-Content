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

// Load core filesystem classes
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-directory.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-file.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-file-htaccess.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-file-index.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-file-robots.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-file-webconfig.php';

// Load core archiver classes
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-archiver.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-compressor.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-extractor.php';

// Load core database classes
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-database.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-database-mysqli.php';

// Load core iterator classes
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-recursive-directory-iterator.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-recursive-iterator-iterator.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-recursive-exclude-filter.php';
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-recursive-extension-filter.php';

// Load core cron class
require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-cron.php';

// Load WP-CLI command class
if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
	require_once MKSDDN_MC_CORE_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-wp-cli-command.php';
}

// Load model classes
require_once MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-template.php';
require_once MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-status.php';
require_once MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-backups.php';
require_once MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-settings.php';
require_once MKSDDN_MC_MODEL_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-notification.php';

// Load controllers
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-main-controller.php';
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-export-controller.php';
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-import-controller.php';
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-backups-controller.php';
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-settings-controller.php';
require_once MKSDDN_MC_CONTROLLER_PATH . DIRECTORY_SEPARATOR . 'class-mksddn-mc-status-controller.php';

