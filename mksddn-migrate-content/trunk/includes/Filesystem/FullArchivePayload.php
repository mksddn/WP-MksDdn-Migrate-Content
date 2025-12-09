<?php
/**
 * Helper for reading payload from full-site archives.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Filesystem;

use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches decoded payload data from `.wpbkp` archives.
 */
class FullArchivePayload {

	/**
	 * Read payload/content.json from archive and decode it.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @return array|WP_Error
	 */
	public static function read( string $archive_path ) {
		if ( '' === $archive_path || ! file_exists( $archive_path ) ) {
			return new WP_Error( 'mksddn_mc_payload_missing', __( 'Archive payload is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$json = self::read_payload_json( $archive_path );
		if ( is_wp_error( $json ) ) {
			return $json;
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_payload_corrupted', __( 'Archive payload is corrupted or unreadable.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}

	/**
	 * Read payload JSON either via ZipArchive or PclZip.
	 *
	 * @param string $archive_path Archive path.
	 * @return string|WP_Error
	 */
	private static function read_payload_json( string $archive_path ) {
		if ( class_exists( ZipArchive::class ) ) {
			$zip  = new ZipArchive();
			$open = $zip->open( $archive_path );

			if ( true === $open ) {
				$payload = $zip->getFromName( 'payload/content.json' );
				$zip->close();

				if ( false !== $payload ) {
					return $payload;
				}
			}
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new \PclZip( $archive_path );
		$result  = $archive->extract(
			PCLZIP_OPT_BY_NAME,
			'payload/content.json',
			PCLZIP_OPT_EXTRACT_AS_STRING
		);

		if ( false === $result || empty( $result ) ) {
			return new WP_Error( 'mksddn_mc_payload_not_found', __( 'Full archive payload not found.', 'mksddn-migrate-content' ) );
		}

		$content = $result[0]['content'] ?? '';
		if ( '' === $content ) {
			return new WP_Error( 'mksddn_mc_payload_empty', __( 'Full archive payload is empty.', 'mksddn-migrate-content' ) );
		}

		return $content;
	}
}


