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
	 * Read payload/content.json from archive and decode it.
	 *
	 * Checks payload size and available PHP memory before loading to avoid fatal OOM.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @return array|WP_Error
	 */
	public static function read( string $archive_path ) {
		if ( '' === $archive_path || ! file_exists( $archive_path ) ) {
			return new WP_Error( 'mksddn_mc_payload_missing', __( 'Archive payload is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$loaded = self::load_payload_json( $archive_path );
		if ( is_wp_error( $loaded ) ) {
			return $loaded;
		}

		$json      = $loaded['json'];
		$json_size = strlen( $json );

		// Re-validate when PclZip path had unknown size, or zip size disagreed with bytes read.
		if ( $json_size > 0 && $json_size !== (int) $loaded['declared_size'] ) {
			$memory_ready = self::ensure_memory_for_payload( $json_size );
			if ( is_wp_error( $memory_ready ) ) {
				unset( $json );
				return $memory_ready;
			}
		}

		$data = json_decode( $json, true );
		unset( $json );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_payload_corrupted', __( 'Archive payload is corrupted or unreadable.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}

	/**
	 * Open archive once: size check, memory raise, then read payload JSON.
	 *
	 * @param string $archive_path Archive path.
	 * @return array{json:string,declared_size:int}|WP_Error
	 */
	private static function load_payload_json( string $archive_path ) {
		if ( class_exists( ZipArchive::class ) ) {
			$zip  = new ZipArchive();
			$open = $zip->open( $archive_path );

			if ( true === $open ) {
				$stat = $zip->statName( 'payload/content.json' );
				if ( false === $stat || ! isset( $stat['size'] ) ) {
					$zip->close();
					return new WP_Error( 'mksddn_mc_payload_not_found', __( 'Full archive payload not found.', 'mksddn-migrate-content' ) );
				}

				$declared_size = (int) $stat['size'];
				$memory_ready  = self::ensure_memory_for_payload( $declared_size );
				if ( is_wp_error( $memory_ready ) ) {
					$zip->close();
					return $memory_ready;
				}

				$payload = false;
				$stream  = $zip->getStream( 'payload/content.json' );
				if ( false !== $stream ) {
					$payload = stream_get_contents( $stream );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZipArchive stream resource, not a filesystem file.
					fclose( $stream );
				}

				if ( false === $payload ) {
					$payload = $zip->getFromName( 'payload/content.json' );
				}

				$zip->close();

				if ( false === $payload ) {
					return new WP_Error( 'mksddn_mc_payload_not_found', __( 'Full archive payload not found.', 'mksddn-migrate-content' ) );
				}

				return array(
					'json'           => $payload,
					'declared_size'  => $declared_size,
				);
			}
		}

		return self::load_payload_json_via_pclzip( $archive_path );
	}

	/**
	 * PclZip fallback when ZipArchive is unavailable or cannot open the archive.
	 *
	 * Size is unknown before extract; memory is validated after read.
	 *
	 * @param string $archive_path Archive path.
	 * @return array{json:string,declared_size:int}|WP_Error
	 */
	private static function load_payload_json_via_pclzip( string $archive_path ) {
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

		return array(
			'json'          => $content,
			'declared_size' => 0,
		);
	}

	/**
	 * Ensure PHP memory_limit can hold payload read + json_decode.
	 *
	 * @param int $payload_size Uncompressed payload size in bytes.
	 * @return true|WP_Error
	 */
	private static function ensure_memory_for_payload( int $payload_size ) {
		if ( $payload_size <= 0 ) {
			return true;
		}

		$absolute_max = PluginConfig::effective_max_import_json_size();
		if ( $payload_size > $absolute_max ) {
			return new WP_Error(
				'mksddn_mc_file_too_large',
				sprintf(
					/* translators: %1$s: file size in MB, %2$s: maximum size in MB. */
					__( 'Import file is too large (%1$s MB). Maximum supported size is %2$s MB.', 'mksddn-migrate-content' ),
					self::bytes_to_mb_label( $payload_size ),
					self::bytes_to_mb_label( $absolute_max )
				)
			);
		}

		$required_bytes = $payload_size * PluginConfig::import_json_memory_multiplier();
		$min_limit      = PluginConfig::min_import_memory_limit();
		$max_limit      = PluginConfig::max_import_memory_limit();
		$target_bytes   = max( $min_limit, min( $required_bytes + memory_get_usage( true ), $max_limit ) );

		self::raise_memory_limit( $target_bytes );

		$limit_str   = (string) ini_get( 'memory_limit' );
		$limit_bytes = wp_convert_hr_to_bytes( $limit_str );

		// Unlimited.
		if ( $limit_bytes <= 0 || '-1' === trim( $limit_str ) ) {
			return true;
		}

		$used      = memory_get_usage( true );
		$available = $limit_bytes - $used;

		if ( $available >= $required_bytes ) {
			return true;
		}

		$required_mb = (int) ceil( $required_bytes / ( 1024 * 1024 ) );
		$current_mb  = (int) ceil( $limit_bytes / ( 1024 * 1024 ) );
		$payload_mb  = self::bytes_to_mb_label( $payload_size );

		return new WP_Error(
			'mksddn_mc_insufficient_memory',
			sprintf(
				/* translators: %1$s: payload size MB, %2$d: required MB, %3$d: current limit MB */
				__( 'Insufficient PHP memory to read the archive payload (%1$s MB). About %2$d MB is required, but the current limit is %3$d MB. Increase memory_limit in php.ini or wp-config.php (define WP_MEMORY_LIMIT / WP_MAX_MEMORY_LIMIT), then try again.', 'mksddn-migrate-content' ),
				$payload_mb,
				$required_mb,
				$current_mb
			)
		);
	}

	/**
	 * Raise memory_limit toward a target when PHP allows it.
	 *
	 * @param int $target_bytes Desired limit in bytes.
	 * @return void
	 */
	private static function raise_memory_limit( int $target_bytes ): void {
		if ( $target_bytes <= 0 ) {
			return;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$current_str   = (string) ini_get( 'memory_limit' );
		$current_bytes = wp_convert_hr_to_bytes( $current_str );

		if ( $current_bytes <= 0 || '-1' === trim( $current_str ) ) {
			return;
		}

		if ( $current_bytes >= $target_bytes ) {
			return;
		}

		if ( function_exists( 'wp_is_ini_value_changeable' ) && ! wp_is_ini_value_changeable( 'memory_limit' ) ) {
			return;
		}

		$target_mb = (int) ceil( $target_bytes / ( 1024 * 1024 ) );
		@ini_set( 'memory_limit', $target_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
	}

	/**
	 * Format bytes as MB label for messages.
	 *
	 * @param int $bytes Size in bytes.
	 * @return string
	 */
	private static function bytes_to_mb_label( int $bytes ): string {
		return (string) round( $bytes / ( 1024 * 1024 ), 2 );
	}
}
