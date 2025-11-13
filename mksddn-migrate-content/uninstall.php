<?php
/**
 * Uninstall script.
 *
 * @package MksDdn_Migrate_Content
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Delete plugin options.
delete_option( 'mksddn_mc_version' );
delete_option( 'mksddn_mc_max_upload_size' );
delete_option( 'mksddn_mc_export_history' );
delete_option( 'mksddn_mc_import_history' );

// Delete export files directory.
$upload_dir = wp_upload_dir();
$export_dir = $upload_dir['basedir'] . '/mksddn-mc-exports';

if ( file_exists( $export_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->rmdir( $export_dir, true );
	}
}

