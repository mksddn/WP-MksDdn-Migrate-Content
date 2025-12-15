<?php
/**
 * Generates consistent filenames for exports.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds filenames with host, type and timestamp.
 */
class FilenameBuilder {

	/**
	 * Build filename string.
	 *
	 * @param string $type      Export type slug (e.g. full-site, selected-content).
	 * @param string $extension File extension without dot.
	 */
	public static function build( string $type, string $extension = '' ): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host ) {
			$host = (string) wp_parse_url( site_url(), PHP_URL_HOST );
		}

		if ( '' === $host ) {
			$host = 'site';
		}

		$host_slug = strtolower( preg_replace( '/[^a-z0-9.-]+/i', '-', $host ) ?? 'site' );
		$type_slug = sanitize_title( $type );
		$timestamp = gmdate( 'Ymd-His' );

		$parts    = array_filter( array( $host_slug, $type_slug, $timestamp ) );
		$filename = implode( '-', $parts );

		if ( '' !== $extension ) {
			$filename .= '.' . ltrim( $extension, '.' );
		}

		return $filename;
	}
}


