<?php
/**
 * Helper for reading payload from full-site archives.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Config\PluginConfig;
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
	 * Original memory limit before increase.
	 *
	 * @var string|null
	 */
	private static ?string $original_memory_limit = null;

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

		// Store original memory limit.
		self::$original_memory_limit = ini_get( 'memory_limit' );

		$json = self::read_payload_json( $archive_path );
		if ( is_wp_error( $json ) ) {
			// Restore memory limit on error.
			if ( null !== self::$original_memory_limit ) {
				@ini_set( 'memory_limit', self::$original_memory_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
			return $json;
		}

		// Ensure memory limit is sufficient for JSON decode (may already be increased by read_payload_json).
		$json_size = strlen( $json );
		if ( $json_size > 50 * 1024 * 1024 ) { // > 50MB.
			$current_limit = ini_get( 'memory_limit' );
			$current_bytes = self::parse_memory_limit( $current_limit );
			$required      = $json_size * 5; // Conservative estimate for JSON decode.
			$min_limit    = PluginConfig::min_import_memory_limit();
			$max_limit    = PluginConfig::max_import_memory_limit();
			$target       = max( $min_limit, min( $required, $max_limit ) );

			// Increase if current limit is insufficient.
			if ( $current_bytes < $target ) {
				$target_mb = ceil( $target / ( 1024 * 1024 ) );
				@ini_set( 'memory_limit', $target_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}
		}

		$data = json_decode( $json, true );

		// Restore original memory limit after decode.
		if ( null !== self::$original_memory_limit ) {
			@ini_set( 'memory_limit', self::$original_memory_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_payload_corrupted', __( 'Archive payload is corrupted or unreadable.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}

	/**
	 * Parse memory limit string to bytes.
	 *
	 * @param string $limit Memory limit string (e.g., "256M", "1G").
	 * @return int Size in bytes.
	 */
	private static function parse_memory_limit( string $limit ): int {
		$limit = trim( $limit );
		$last  = strtolower( $limit[ strlen( $limit ) - 1 ] );
		$value = (int) $limit;

		switch ( $last ) {
			case 'g':
				$value *= 1024;
				// Fall through.
			case 'm':
				$value *= 1024;
				// Fall through.
			case 'k':
				$value *= 1024;
		}

		return $value;
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
				// Get file size before reading to manage memory.
				$stat = $zip->statName( 'payload/content.json' );
				if ( false !== $stat && isset( $stat['size'] ) ) {
					$file_size = $stat['size'];
					// Increase memory limit before reading large files.
					if ( $file_size > 50 * 1024 * 1024 ) { // > 50MB.
						$min_limit = PluginConfig::min_import_memory_limit();
						$max_limit = PluginConfig::max_import_memory_limit();
						// Estimate: file size * 2 for reading + * 5 for decode = * 7 total.
						$required  = $file_size * 7;
						$target    = max( $min_limit, min( $required, $max_limit ) );
						$target_mb = ceil( $target / ( 1024 * 1024 ) );
						@ini_set( 'memory_limit', $target_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
					}
				}

				// Use stream for better memory efficiency.
				$stream = $zip->getStream( 'payload/content.json' );
				if ( false !== $stream ) {
					$payload = stream_get_contents( $stream );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZipArchive stream resource, not a filesystem file.
					fclose( $stream );
					$zip->close();

					if ( false !== $payload ) {
						return $payload;
					}
				} else {
					// Fallback to getFromName if stream fails.
					$payload = $zip->getFromName( 'payload/content.json' );
					$zip->close();

					if ( false !== $payload ) {
						return $payload;
					}
				}
			}
		}

		// Load PclZip class required for archive payload reading when ZipArchive is unavailable.
		// Class is used immediately after loading to extract payload from archive.
		if ( ! class_exists( 'PclZip' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		}
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


