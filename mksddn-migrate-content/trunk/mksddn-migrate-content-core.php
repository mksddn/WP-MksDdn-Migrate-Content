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

// Initialize chunking services.
add_action(
	'plugins_loaded',
	static function (): void {
		MksDdn\MigrateContent\Chunking\ChunkServiceProvider::init();
	}
);

( new MksDdn\MigrateContent\Plugin() )->register();
