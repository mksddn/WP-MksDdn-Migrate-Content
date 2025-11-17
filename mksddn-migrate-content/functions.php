<?php
/**
 * Helper functions
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

/**
 * Get storage absolute path
 *
 * @param array $params Request parameters
 * @return string
 * @throws Exception
 */
function mksddn_mc_storage_path( $params ) {
	if ( empty( $params['storage'] ) ) {
		throw new Exception( __( 'Could not locate the storage path. The process cannot continue.', 'mksddn-migrate-content' ) );
	}

	// Validate storage path
	if ( mksddn_mc_validate_file( $params['storage'] ) !== 0 ) {
		throw new Exception( __( 'Your storage directory name contains invalid characters. The process cannot continue.', 'mksddn-migrate-content' ) );
	}

	// Get storage path
	$storage = MKSDDN_MC_STORAGE_PATH . DIRECTORY_SEPARATOR . basename( $params['storage'] );
	if ( ! is_dir( $storage ) ) {
		wp_mkdir_p( $storage );
	}

	return $storage;
}

/**
 * Get backup absolute path
 *
 * @param array $params Request parameters
 * @return string
 * @throws Exception
 */
function mksddn_mc_backup_path( $params ) {
	if ( empty( $params['archive'] ) ) {
		throw new Exception( __( 'Could not locate the archive path. The process cannot continue.', 'mksddn-migrate-content' ) );
	}

	// Validate archive path
	if ( mksddn_mc_validate_file( $params['archive'] ) !== 0 ) {
		throw new Exception( __( 'Your archive file name contains invalid characters. The process cannot continue.', 'mksddn-migrate-content' ) );
	}

	// Validate file extension
	if ( ! mksddn_mc_is_filename_supported( $params['archive'] ) ) {
		throw new Exception( __( 'Invalid archive file type. Only .mksddn files are allowed. The process cannot continue.', 'mksddn-migrate-content' ) );
	}

	// Get backup path
	$backup = MKSDDN_MC_BACKUPS_PATH . DIRECTORY_SEPARATOR . basename( $params['archive'] );

	return $backup;
}

/**
 * Validate file name
 *
 * @param string $filename File name
 * @return int
 */
function mksddn_mc_validate_file( $filename ) {
	return preg_match( '/[<>:"|?*\x00]/', $filename );
}

/**
 * Check if filename is supported
 *
 * @param string $filename File name
 * @return bool
 */
function mksddn_mc_is_filename_supported( $filename ) {
	$extensions = array( 'mksddn', 'migrate' );
	$extension  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	return in_array( $extension, $extensions, true );
}

/**
 * Format bytes to human readable size
 *
 * @param int $bytes Bytes
 * @param int $precision Precision
 * @return string
 */
function mksddn_mc_size_format( $bytes, $precision = 2 ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

	for ( $i = 0; $bytes > 1024 && $i < count( $units ) - 1; $i++ ) {
		$bytes /= 1024;
	}

	return round( $bytes, $precision ) . ' ' . $units[ $i ];
}

/**
 * Get allowed HTML tags for wp_kses
 *
 * @return array
 */
function mksddn_mc_allowed_html_tags() {
	return array(
		'a'      => array(
			'href'   => array(),
			'target' => array(),
		),
		'br'     => array(),
		'code'   => array(),
		'em'     => array(),
		'strong' => array(),
		'b'      => array(),
		'i'      => array(),
		'p'      => array(),
	);
}

