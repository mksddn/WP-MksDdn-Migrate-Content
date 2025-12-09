<?php
/**
 * Archive extractor for .wpbkp files.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Archive;

use WP_Error;
use ZipArchive;
use Mksddn_MC\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts manifest + payload with integrity validation.
 */
class Extractor {

	/**
	 * Extract archive contents and return normalized data.
	 *
	 * @param string $file_path Uploaded archive path.
	 * @return array|WP_Error
	 */
	public function extract( string $file_path ): array|WP_Error {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'Uploaded archive not found.', 'mksddn-migrate-content' ) );
		}

		$raw = $this->read_from_archive( $file_path );

		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$manifest = json_decode( $raw['manifest'], true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) ) {
			return new WP_Error( 'mksddn_mc_manifest_error', __( 'Invalid manifest in archive.', 'mksddn-migrate-content' ) );
		}

		$payload = json_decode( $raw['payload'], true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return new WP_Error( 'mksddn_mc_payload_error', __( 'Invalid payload in archive.', 'mksddn-migrate-content' ) );
		}

		$expected_checksum = $manifest['checksum'] ?? '';
		$actual_checksum   = hash( 'sha256', $raw['payload'] );

		if ( ! hash_equals( (string) $expected_checksum, $actual_checksum ) ) {
			return new WP_Error( 'mksddn_mc_checksum_mismatch', __( 'Archive integrity check failed.', 'mksddn-migrate-content' ) );
		}

		return array(
			'type'    => sanitize_key( $manifest['type'] ?? 'page' ),
			'payload' => $payload,
			'media'   => $manifest['media'] ?? array(),
		);
	}

	/**
	 * Read archive contents.
	 *
	 * @param string $file_path Archive.
	 * @return array|WP_Error
	 */
	private function read_from_archive( string $file_path ): array|WP_Error {
		if ( class_exists( ZipArchive::class ) ) {
			$result = $this->read_with_ziparchive( $file_path );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
		}

		return $this->read_with_pclzip( $file_path );
	}

	/**
	 * Read using ZipArchive.
	 *
	 * @param string $file_path Archive path.
	 * @return array|WP_Error
	 */
	private function read_with_ziparchive( string $file_path ): array|WP_Error {
		$zip   = new ZipArchive();
		$open  = $zip->open( $file_path );

		if ( true !== $open ) {
			return new WP_Error( 'mksddn_mc_zip_read_error', __( 'Unable to open archive.', 'mksddn-migrate-content' ) );
		}

		$manifest = $zip->getFromName( 'manifest.json' );
		$payload  = $zip->getFromName( 'payload/content.json' );
		$zip->close();

		if ( false === $manifest || false === $payload ) {
			return new WP_Error( 'mksddn_mc_missing_files', __( 'Archive is missing required files.', 'mksddn-migrate-content' ) );
		}

		return array(
			'manifest' => $manifest,
			'payload'  => $payload,
		);
	}

	/**
	 * Read archive using PclZip fallback.
	 *
	 * @param string $file_path Archive path.
	 * @return array|WP_Error
	 */
	private function read_with_pclzip( string $file_path ): array|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive = new \PclZip( $file_path );

		$manifest = $archive->extract( PCLZIP_OPT_BY_NAME, 'manifest.json', PCLZIP_OPT_EXTRACT_AS_STRING );
		$payload  = $archive->extract( PCLZIP_OPT_BY_NAME, 'payload/content.json', PCLZIP_OPT_EXTRACT_AS_STRING );

		if ( false === $manifest || false === $payload || empty( $manifest ) || empty( $payload ) ) {
			return new WP_Error( 'mksddn_mc_missing_files', __( 'Archive is missing required files.', 'mksddn-migrate-content' ) );
		}

		return array(
			'manifest' => $manifest[0]['content'] ?? '',
			'payload'  => $payload[0]['content'] ?? '',
		);
	}

	/**
	 * Extract media file into a temporary path.
	 *
	 * @param string $archive_path Relative path inside archive.
	 * @param string $file_path    Original uploaded archive.
	 * @return string|WP_Error
	 */
	public function extract_media_file( string $archive_path, string $file_path ): string|WP_Error {
		if ( '' === $archive_path ) {
			return new WP_Error( 'mksddn_mc_media_path_missing', __( 'Media file path missing in archive.', 'mksddn-migrate-content' ) );
		}

		if ( class_exists( ZipArchive::class ) ) {
			$zip = new ZipArchive();
			if ( true === $zip->open( $file_path ) ) {
				$stream = $zip->getStream( $archive_path );
				if ( ! $stream ) {
					$zip->close();
					return new WP_Error( 'mksddn_mc_media_not_found', __( 'Media file not found inside archive.', 'mksddn-migrate-content' ) );
				}

				$tmp_path = wp_tempnam( 'mksddn-media-' );
				if ( ! $tmp_path || ! FilesystemHelper::put_stream( $tmp_path, $stream ) ) {
					fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with ZipArchive stream
					$zip->close();
					return new WP_Error( 'mksddn_mc_media_write_error', __( 'Unable to extract media file to temporary storage.', 'mksddn-migrate-content' ) );
				}
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with ZipArchive stream
				$zip->close();

				return $tmp_path;
			}
		}

		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new \PclZip( $file_path );
		$files   = $archive->extract(
			PCLZIP_OPT_BY_NAME,
			$archive_path,
			PCLZIP_OPT_EXTRACT_AS_STRING
		);

		if ( false === $files || empty( $files ) ) {
			return new WP_Error( 'mksddn_mc_media_not_found', __( 'Media file not found inside archive.', 'mksddn-migrate-content' ) );
		}

		$content = $files[0]['content'] ?? '';
		$tmp     = wp_tempnam( 'mksddn-media-' );
		if ( ! $tmp || ! FilesystemHelper::put_contents( $tmp, $content ) ) {
			return new WP_Error( 'mksddn_mc_media_write_error', __( 'Unable to extract media file to temporary storage.', 'mksddn-migrate-content' ) );
		}

		return $tmp;
	}
}
