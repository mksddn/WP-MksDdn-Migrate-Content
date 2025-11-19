<?php
/**
 * Core bootstrap wiring.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Prevent direct access.
}

require_once __DIR__ . '/includes/autoload.php';
add_action(
	'plugins_loaded',
	static function (): void {
		require_once __DIR__ . '/includes/Admin/ExportImportAdmin.php';
		require_once __DIR__ . '/includes/Export/ExportHandler.php';
		require_once __DIR__ . '/includes/Import/ImportHandler.php';
		require_once __DIR__ . '/includes/Options/OptionsExporter.php';
		require_once __DIR__ . '/includes/Options/OptionsImporter.php';
		require_once __DIR__ . '/includes/Selection/ContentSelection.php';
		require_once __DIR__ . '/includes/Selection/SelectionBuilder.php';
	}
);

( new Mksddn_MC\Plugin() )->register();
