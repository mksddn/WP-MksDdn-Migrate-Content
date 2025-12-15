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

( new MksDdn\MigrateContent\Plugin() )->register();
