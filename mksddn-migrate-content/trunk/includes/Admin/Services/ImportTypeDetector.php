<?php
/**
 * @file: ImportTypeDetector.php
 * @description: Service for detecting import type (full site or selected content) from archive file
 * @dependencies: Archive\Extractor, Filesystem\FullArchivePayload
 * @created: 2026-01-25
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Archive\Extractor;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for detecting import type from archive file.
 *
 * @since 1.4.0
 */
class ImportTypeDetector {

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	/**
	 * Constructor.
	 *
	 * @param Extractor|null $extractor Archive extractor.
	 * @since 1.4.0
	 */
	public function __construct( ?Extractor $extractor = null ) {
		$this->extractor = $extractor ?? new Extractor();
	}

	/**
	 * Detect import type from file.
	 *
	 * @param string $file_path File path.
	 * @param string $extension File extension (lowercase).
	 * @return string|WP_Error Import type ('full' or 'selected') or error.
	 * @since 1.4.0
	 */
	public function detect( string $file_path, string $extension ): string|WP_Error {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'File not found.', 'mksddn-migrate-content' ) );
		}

		// JSON files are always selected content imports.
		if ( 'json' === $extension ) {
			return 'selected';
		}

		// For .wpbkp archives, check manifest and payload structure.
		if ( 'wpbkp' === $extension ) {
			return $this->detect_from_archive( $file_path );
		}

		return new WP_Error( 'mksddn_mc_invalid_type', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Detect import type from .wpbkp archive.
	 *
	 * @param string $file_path Archive path.
	 * @return string|WP_Error Import type or error.
	 * @since 1.4.0
	 */
	private function detect_from_archive( string $file_path ): string|WP_Error {
		$zip = new ZipArchive();
		$open = $zip->open( $file_path );

		if ( true !== $open ) {
			return new WP_Error( 'mksddn_mc_zip_open', __( 'Unable to open archive.', 'mksddn-migrate-content' ) );
		}

		// Check if manifest.json exists.
		$manifest_raw = $zip->getFromName( 'manifest.json' );
		if ( false === $manifest_raw ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_missing_manifest', __( 'Archive is missing manifest.json.', 'mksddn-migrate-content' ) );
		}

		$manifest = json_decode( $manifest_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_invalid_manifest', __( 'Invalid manifest in archive.', 'mksddn-migrate-content' ) );
		}

		// Check manifest type first.
		$manifest_type = sanitize_key( $manifest['type'] ?? '' );
		if ( 'full' === $manifest_type ) {
			$zip->close();
			return 'full';
		}

		// Check if payload/content.json exists and contains database data.
		$payload_stat = $zip->statName( 'payload/content.json' );
		if ( false === $payload_stat ) {
			$zip->close();
			// No payload/content.json means it's selected content import.
			return 'selected';
		}

		// Read a small portion of payload to check for database structure.
		// Read first 2048 bytes to get enough data for detection.
		$payload_sample = $zip->getFromName( 'payload/content.json', 0, 2048 );

		if ( false === $payload_sample ) {
			$zip->close();
			return 'selected';
		}

		// Check if payload contains database structure (full site import).
		$payload_data = json_decode( $payload_sample, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $payload_data ) ) {
			// Full site archives have 'database' key with 'tables' array.
			if ( isset( $payload_data['database'] ) && is_array( $payload_data['database'] ) ) {
				$zip->close();
				return 'full';
			}
		}

		// Check for database-related directories/files in archive.
		$db_indicators = array( 'database/', 'options/', 'filesystem/' );
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$filename = $zip->getNameIndex( $i );
			if ( false === $filename ) {
				continue;
			}

			foreach ( $db_indicators as $indicator ) {
				if ( 0 === strpos( $filename, $indicator ) ) {
					$zip->close();
					return 'full';
				}
			}
		}

		$zip->close();

		// Default to selected content import.
		return 'selected';
	}
}
