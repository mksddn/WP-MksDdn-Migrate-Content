<?php
/**
 * Archive packer responsible for building .wpbkp files.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Archive;

use WP_Error;
use Mksddn_MC\Support\FilesystemHelper;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates archives containing manifest + payload.
 */
class Packer {

	private const FORMAT_VERSION = 1;

	/**
	 * Build archive and return a temporary filepath.
	 *
	 * @param array $payload Payload data.
	 * @param array $meta    Manifest metadata (type, label, etc).
	 * @param array $assets  Additional files to embed.
	 * @return string|WP_Error
	 */
	public function create_archive( array $payload, array $meta, array $assets = array() ): string|WP_Error {
		$payload_json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

		if ( false === $payload_json ) {
			return new WP_Error( 'mksddn_mc_invalid_payload', __( 'Failed to encode payload for export.', 'mksddn-migrate-content' ) );
		}

		$checksum = hash( 'sha256', $payload_json );
		$manifest = array(
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => MKSDDN_MC_VERSION,
			'type'           => sanitize_key( $meta['type'] ?? 'page' ),
			'label'          => sanitize_text_field( $meta['label'] ?? '' ),
			'created_at_gmt' => gmdate( 'c' ),
			'checksum'       => $checksum,
			'media'          => $meta['media'] ?? array(),
		);

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $manifest_json ) {
			return new WP_Error( 'mksddn_mc_invalid_manifest', __( 'Failed to encode manifest for export.', 'mksddn-migrate-content' ) );
		}

		if ( class_exists( ZipArchive::class ) ) {
			return $this->build_with_ziparchive( $manifest_json, $payload_json, $assets );
		}

		return $this->build_with_pclzip( $manifest_json, $payload_json, $assets );
	}

	/**
	 * Build archive using ZipArchive.
	 *
	 * @param string $manifest_json Manifest string.
	 * @param string $payload_json  Payload string.
	 * @return string|WP_Error
	 */
	private function build_with_ziparchive( string $manifest_json, string $payload_json, array $assets ): string|WP_Error {
		$archive_path = wp_tempnam( 'mksddn-mc-' );

		if ( ! $archive_path ) {
			return new WP_Error( 'mksddn_mc_tempfile_error', __( 'Unable to create temporary archive file.', 'mksddn-migrate-content' ) );
		}

		$zip = new ZipArchive();
		$result = $zip->open( $archive_path, ZipArchive::OVERWRITE );

		if ( true !== $result ) {
			return new WP_Error( 'mksddn_mc_zip_error', __( 'Unable to initialize archive writer.', 'mksddn-migrate-content' ) );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'payload/content.json', $payload_json );

		foreach ( $assets as $asset ) {
			$source = $asset['source'] ?? '';
			$target = $asset['target'] ?? '';
			if ( '' === $source || '' === $target || ! file_exists( $source ) ) {
				continue;
			}

			$zip->addFile( $source, $target );
		}

		$zip->close();

		return $archive_path;
	}

	/**
	 * Build archive using PclZip as a fallback.
	 *
	 * @param string $manifest_json Manifest string.
	 * @param string $payload_json  Payload string.
	 * @return string|WP_Error
	 */
	private function build_with_pclzip( string $manifest_json, string $payload_json, array $assets ): string|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$archive_path = wp_tempnam( 'mksddn-mc-' );
		if ( ! $archive_path ) {
			return new WP_Error( 'mksddn_mc_tempfile_error', __( 'Unable to create temporary archive file.', 'mksddn-migrate-content' ) );
		}

		$tmp_dir = $this->create_temp_dir();
		if ( is_wp_error( $tmp_dir ) ) {
			return $tmp_dir;
		}

		$manifest_path = trailingslashit( $tmp_dir ) . 'manifest.json';
		file_put_contents( $manifest_path, $manifest_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$payload_dir  = trailingslashit( $tmp_dir ) . 'payload';
		wp_mkdir_p( $payload_dir );
		$content_path = trailingslashit( $payload_dir ) . 'content.json';
		file_put_contents( $content_path, $payload_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$archive = new \PclZip( $archive_path );
		$files   = array(
			array(
				PCLZIP_ATT_FILE_NAME        => $manifest_path,
				PCLZIP_ATT_FILE_NEW_FULL_NAME => 'manifest.json',
			),
			array(
				PCLZIP_ATT_FILE_NAME        => $content_path,
				PCLZIP_ATT_FILE_NEW_FULL_NAME => 'payload/content.json',
			),
		);

		foreach ( $assets as $asset ) {
			$source = $asset['source'] ?? '';
			$target = $asset['target'] ?? '';
			if ( '' === $source || '' === $target || ! file_exists( $source ) ) {
				continue;
			}

			$files[] = array(
				PCLZIP_ATT_FILE_NAME        => $source,
				PCLZIP_ATT_FILE_NEW_FULL_NAME => $target,
			);
		}

		$result = $archive->create( $files );

		$this->cleanup_temp_dir( $tmp_dir );

		if ( false === $result || 0 === $result ) {
			return new WP_Error( 'mksddn_mc_pclzip_error', __( 'Failed to write archive via PclZip.', 'mksddn-migrate-content' ) );
		}

		return $archive_path;
	}

	/**
	 * Create a temporary directory.
	 *
	 * @return string|WP_Error
	 */
	private function create_temp_dir(): string|WP_Error {
		$temp_file = wp_tempnam( 'mksddn-mc-' );

		if ( ! $temp_file ) {
			return new WP_Error( 'mksddn_mc_tempdir_error', __( 'Unable to allocate temporary directory.', 'mksddn-migrate-content' ) );
		}

		FilesystemHelper::delete( $temp_file );

		$temp_dir = $temp_file . '-dir';

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error( 'mksddn_mc_tempdir_error', __( 'Unable to create temporary directory.', 'mksddn-migrate-content' ) );
		}

		return $temp_dir;
	}

	/**
	 * Remove temporary directory recursively.
	 *
	 * @param string $dir Path to remove.
	 */
	private function cleanup_temp_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		FilesystemHelper::delete( $dir, true );
	}
}

