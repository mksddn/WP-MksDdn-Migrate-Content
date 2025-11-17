<?php
/**
 * Uninstall script
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die( 'Direct access not allowed' );
}

// Delete options
delete_option( 'mksddn_mc_secret_key' );
delete_option( 'mksddn_mc_status' );
delete_option( 'mksddn_mc_messages' );
delete_option( 'mksddn_mc_backups_path' );

// Delete transients
delete_transient( 'mksddn_mc_status' );
delete_transient( 'mksddn_mc_messages' );

