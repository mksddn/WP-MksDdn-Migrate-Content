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
	 * @return string|WP_Error Import type ('full', 'selected', or 'themes') or error.
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

		try {
			// Check if manifest.json exists.
			$manifest_raw = $zip->getFromName( 'manifest.json' );
			if ( false === $manifest_raw ) {
				return new WP_Error( 'mksddn_mc_missing_manifest', __( 'Archive is missing manifest.json.', 'mksddn-migrate-content' ) );
			}

			$manifest = json_decode( $manifest_raw, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) ) {
				return new WP_Error( 'mksddn_mc_invalid_manifest', __( 'Invalid manifest in archive.', 'mksddn-migrate-content' ) );
			}

			// Check manifest type first.
			$manifest_type = sanitize_key( $manifest['type'] ?? '' );
			if ( in_array( $manifest_type, array( 'full', 'full-site', 'full_site', 'fullsite' ), true ) ) {
				// Validate full site manifest structure.
				if ( ! isset( $manifest['format_version'] ) || ! isset( $manifest['plugin_version'] ) ) {
					return new WP_Error( 'mksddn_mc_invalid_manifest', __( 'Invalid full site manifest structure.', 'mksddn-migrate-content' ) );
				}
				return 'full';
			}
			if ( in_array( $manifest_type, array( 'themes', 'theme' ), true ) ) {
				// Validate themes manifest structure.
				if ( ! isset( $manifest['format_version'] ) || ! isset( $manifest['plugin_version'] ) || ! isset( $manifest['themes'] ) || ! is_array( $manifest['themes'] ) ) {
					return new WP_Error( 'mksddn_mc_invalid_manifest', __( 'Invalid themes manifest structure.', 'mksddn-migrate-content' ) );
				}
				return 'themes';
			}

			// Common selected-content manifest types should route to selected import.
			if ( in_array( $manifest_type, array( 'selected', 'page', 'post', 'bundle' ), true ) ) {
				return 'selected';
			}

			// Check if payload/content.json exists and contains database data.
			$payload_stat = $zip->statName( 'payload/content.json' );
			if ( false === $payload_stat ) {
				// No payload/content.json means it's selected content import.
				return 'selected';
			}

			// Full-site archives usually include wp-content trees.
			if ( $this->archive_has_full_site_roots( $zip ) ) {
				return 'full';
			}

			$payload_sample = $this->read_payload_sample( $zip );
			if ( null !== $payload_sample ) {
				// Full-site payloads always include database table dumps.
				if ( false !== strpos( $payload_sample, '"database"' ) && false !== strpos( $payload_sample, '"tables"' ) ) {
					return 'full';
				}

				// Theme payloads include themes metadata array.
				if ( false !== strpos( $payload_sample, '"themes"' ) ) {
					return 'themes';
				}
			}

			// Check for database-related directories/files in archive.
			$db_indicators = array( 'database/', 'options/', 'filesystem/', 'files/wp-content/' );
			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$filename = $zip->getNameIndex( $i );
				if ( false === $filename ) {
					continue;
				}

				foreach ( $db_indicators as $indicator ) {
					if ( 0 === strpos( $filename, $indicator ) ) {
						return 'full';
					}
				}
			}

			// Default to selected content import.
			return 'selected';
		} finally {
			$zip->close();
		}
	}

	/**
	 * Read a small payload sample for heuristic type detection.
	 *
	 * @param ZipArchive $zip Archive object.
	 * @return string|null
	 */
	private function read_payload_sample( ZipArchive $zip ): ?string {
		$sample_size = 65536;
		$stream      = $zip->getStream( 'payload/content.json' );

		if ( false !== $stream ) {
			$sample = fread( $stream, $sample_size ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- ZipArchive stream requires native fread.
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with ZipArchive stream
			return false === $sample ? null : (string) $sample;
		}

		$sample = $zip->getFromName( 'payload/content.json', $sample_size );
		return false === $sample ? null : (string) $sample;
	}

	/**
	 * Determine whether archive contains wp-content directories typical for full-site backups.
	 *
	 * @param ZipArchive $zip Archive object.
	 * @return bool
	 */
	private function archive_has_full_site_roots( ZipArchive $zip ): bool {
		$prefixes = array(
			'files/wp-content/uploads/',
			'files/wp-content/plugins/',
			'files/wp-content/themes/',
			'files/wp-content/mu-plugins/',
			'wp-content/uploads/',
			'wp-content/plugins/',
			'wp-content/themes/',
			'wp-content/mu-plugins/',
		);

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$filename = $zip->getNameIndex( $i );
			if ( false === $filename ) {
				continue;
			}

			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $filename, $prefix ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
