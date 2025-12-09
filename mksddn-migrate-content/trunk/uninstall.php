<?php
/**
 * Uninstall cleanup.
 *
 * @package MksDdn_Migrate_Content
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Cleanup options or transients if we add them in future.
// Currently, no persistent options are stored by the plugin.
