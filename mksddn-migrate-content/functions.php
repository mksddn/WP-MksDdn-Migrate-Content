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

/**
 * Replace URLs in content
 *
 * @param string $content Content to replace URLs in
 * @param string $old_url Old URL
 * @param string $new_url New URL
 * @return string
 */
function mksddn_mc_replace_urls( $content, $old_url, $new_url ) {
	if ( empty( $old_url ) || empty( $new_url ) || $old_url === $new_url ) {
		return $content;
	}

	// Replace various URL formats
	$replacements = array(
		$old_url => $new_url,
		str_replace( '/', '\/', $old_url ) => str_replace( '/', '\/', $new_url ),
		urlencode( $old_url ) => urlencode( $new_url ),
		rawurlencode( $old_url ) => rawurlencode( $new_url ),
	);

	foreach ( $replacements as $old => $new ) {
		$content = str_replace( $old, $new, $content );
	}

	return $content;
}

/**
 * Replace serialized URLs in content
 *
 * @param string $content Serialized content
 * @param string $old_url Old URL
 * @param string $new_url New URL
 * @return string
 */
function mksddn_mc_replace_serialized_urls( $content, $old_url, $new_url ) {
	if ( empty( $old_url ) || empty( $new_url ) || $old_url === $new_url ) {
		return $content;
	}

	// Check if content is serialized
	if ( ! is_serialized( $content ) ) {
		return mksddn_mc_replace_urls( $content, $old_url, $new_url );
	}

	// Unserialize, replace, and reserialize
	$data = unserialize( $content );
	$data = mksddn_mc_replace_urls_recursive( $data, $old_url, $new_url );
	return serialize( $data );
}

/**
 * Replace URLs recursively in array or object
 *
 * @param mixed $data Data to process
 * @param string $old_url Old URL
 * @param string $new_url New URL
 * @return mixed
 */
function mksddn_mc_replace_urls_recursive( $data, $old_url, $new_url ) {
	if ( is_array( $data ) ) {
		foreach ( $data as $key => $value ) {
			$data[ $key ] = mksddn_mc_replace_urls_recursive( $value, $old_url, $new_url );
		}
	} elseif ( is_object( $data ) ) {
		foreach ( $data as $key => $value ) {
			$data->$key = mksddn_mc_replace_urls_recursive( $value, $old_url, $new_url );
		}
	} elseif ( is_string( $data ) ) {
		$data = mksddn_mc_replace_urls( $data, $old_url, $new_url );
	}

	return $data;
}

/**
 * Get table prefix
 *
 * @return string
 */
function mksddn_mc_table_prefix() {
	global $wpdb;
	return $wpdb->prefix;
}

/**
 * Verify secret key
 *
 * @param string $secret_key Secret key
 * @return bool
 */
function mksddn_mc_verify_secret_key( $secret_key ) {
	$stored_key = get_option( MKSDDN_MC_SECRET_KEY );
	if ( empty( $stored_key ) ) {
		$stored_key = wp_generate_password( 32, false );
		update_option( MKSDDN_MC_SECRET_KEY, $stored_key );
	}

	return hash_equals( $stored_key, $secret_key );
}

/**
 * Get secret key
 *
 * @return string
 */
function mksddn_mc_get_secret_key() {
	$secret_key = get_option( MKSDDN_MC_SECRET_KEY );
	if ( empty( $secret_key ) ) {
		$secret_key = wp_generate_password( 32, false );
		update_option( MKSDDN_MC_SECRET_KEY, $secret_key );
	}

	return $secret_key;
}

/**
 * Setup environment
 *
 * @return void
 */
function mksddn_mc_setup_environment() {
	// Set time limit
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}

	// Set memory limit
	if ( function_exists( 'ini_set' ) ) {
		@ini_set( 'memory_limit', '256M' );
	}

	// Disable output buffering
	if ( ob_get_level() ) {
		@ob_end_clean();
	}
}

/**
 * Setup error handling
 *
 * @return void
 */
function mksddn_mc_setup_errors() {
	// Disable error display
	if ( function_exists( 'ini_set' ) ) {
		@ini_set( 'display_errors', 0 );
		@ini_set( 'log_errors', 1 );
	}
}

/**
 * Get filters for export
 *
 * @return array
 */
function mksddn_mc_get_filters() {
	$settings = MksDdn_MC_Settings::get_all();
	return array(
		'exclude_files'       => $settings['exclude_files'],
		'exclude_directories' => $settings['exclude_directories'],
		'exclude_extensions'  => $settings['exclude_extensions'],
		'exclude_tables'      => $settings['exclude_tables'],
	);
}

/**
 * Send JSON response
 *
 * @param array $data Response data
 * @return void
 */
function mksddn_mc_json_response( $data ) {
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $data );
	exit;
}

