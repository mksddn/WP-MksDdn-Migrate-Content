<?php
/**
 * @file: ImportArtifactCleanup.php
 * @description: Stages browser uploads into preflight/; promotes ephemeral archives into imports/ after success
 * @dependencies: Chunking\ChunkJobRepository, Config\PluginConfig, Support\FilesystemHelper, Support\PreflightStagingPath
 * @created: 2026-08-25
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Services\PluginLogger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * After a successful import, keep a single reusable copy under imports/.
 *
 * @since 2.6.0
 */
final class ImportArtifactCleanup {

	/**
	 * Max size allowed for a copy fallback when rename cannot cross volumes.
	 */
	private const COPY_FALLBACK_MAX_BYTES = 32 * MB_IN_BYTES;

	/**
	 * Rename an ephemeral backup into imports/ and drop chunk-job metadata.
	 *
	 * Prefers rename (no second copy). Falls back to copy+delete for small files
	 * when jobs/preflight and imports live on different volumes. Files already in
	 * imports/ are left in place. On total failure the source is kept.
	 *
	 * @param string      $source_path   Absolute path used for the import.
	 * @param string      $original_name Preferred filename for imports/.
	 * @param object|null $job           Chunk job (deleted after a successful move).
	 * @return void
	 */
	public static function persist_for_reuse( string $source_path, string $original_name, $job = null ): void {
		if ( '' === $source_path || ! is_file( $source_path ) ) {
			self::delete_job_metadata( $job );
			return;
		}

		$real = realpath( $source_path );
		if ( false === $real ) {
			self::delete_job_metadata( $job );
			return;
		}

		if ( self::is_under_imports( $real ) ) {
			self::delete_job_metadata( $job );
			return;
		}

		$extension = self::resolve_extension( $original_name, $real );
		$imports_dir = PluginConfig::imports_dir();
		if ( ! is_dir( $imports_dir ) && ! wp_mkdir_p( $imports_dir ) ) {
			PluginLogger::log( 'Could not create imports directory for backup reuse.', 'ImportArtifactCleanup' );
			return;
		}

		FilesystemHelper::protect_directory_from_web( $imports_dir );

		$basename = self::resolve_archive_basename( $original_name, $extension );
		$dest     = trailingslashit( $imports_dir ) . wp_unique_filename( $imports_dir, $basename );

		if ( ! self::relocate( $real, $dest ) ) {
			PluginLogger::log(
				sprintf( 'Could not relocate import archive into imports/: %s -> %s', $real, $dest ),
				'ImportArtifactCleanup'
			);
			return;
		}

		delete_transient( 'mksddn_mc_server_backups' );
		self::delete_job_metadata( $job );
	}

	/**
	 * Move a browser upload into preflight/ for the next import step.
	 *
	 * @param string $source_path   Absolute source path.
	 * @param string $original_name Preferred basename.
	 * @param string $extension     Known extension (wpbkp|json); inferred when empty.
	 * @return array{path:string,name:string,extension:string}|WP_Error
	 */
	public static function stage_into_preflight( string $source_path, string $original_name, string $extension = '' ): array|WP_Error {
		if ( '' === $source_path || ! is_readable( $source_path ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_stage_source',
				__( 'Uploaded backup file is not readable.', 'mksddn-migrate-content' )
			);
		}

		$extension = self::resolve_extension( $original_name !== '' ? $original_name : $source_path, $source_path, $extension );
		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return new WP_Error(
				'mksddn_mc_import_file_invalid_type',
				__( 'Invalid import file type. Only .wpbkp and .json files are supported.', 'mksddn-migrate-content' )
			);
		}

		$preflight_dir = PluginConfig::preflight_dir();
		if ( ! is_dir( $preflight_dir ) && ! wp_mkdir_p( $preflight_dir ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_dir',
				__( 'Could not create the preflight staging directory.', 'mksddn-migrate-content' )
			);
		}

		FilesystemHelper::protect_directory_from_web( $preflight_dir );

		$basename = self::resolve_archive_basename( $original_name, $extension );
		$dest     = trailingslashit( $preflight_dir ) . wp_unique_filename( $preflight_dir, $basename );

		if ( ! self::relocate( $source_path, $dest ) ) {
			$size = is_file( $source_path ) ? (int) filesize( $source_path ) : 0;
			if ( $size > self::COPY_FALLBACK_MAX_BYTES ) {
				return new WP_Error(
					'mksddn_mc_preflight_stage_failed',
					__( 'Could not move the uploaded backup into staging without copying it. Use chunked upload or place the file in the server imports directory.', 'mksddn-migrate-content' )
				);
			}

			return new WP_Error(
				'mksddn_mc_preflight_stage_failed',
				__( 'Could not stage the uploaded backup for import.', 'mksddn-migrate-content' )
			);
		}

		return array(
			'path'      => $dest,
			'name'      => $basename,
			'extension' => $extension,
		);
	}

	/**
	 * Delete a temp file that is not under plugin-managed storage.
	 *
	 * Keeps imports/, preflight/, and jobs/ so failed prepares remain retryable.
	 *
	 * @param string $path Absolute file path.
	 * @return void
	 */
	public static function discard_unmanaged_temp( string $path ): void {
		if ( '' === $path || ! is_file( $path ) ) {
			return;
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return;
		}

		if ( self::is_under_plugin_storage( $real ) ) {
			return;
		}

		FilesystemHelper::delete( $real );
	}

	/**
	 * Remove abandoned preflight staging files older than the TTL.
	 *
	 * @param int $ttl Time-to-live in seconds.
	 * @return void
	 */
	public static function purge_expired_preflight_files( int $ttl = DAY_IN_SECONDS ): void {
		$dir = PluginConfig::preflight_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$cutoff = time() - max( 60, $ttl );
		$files  = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$mtime = filemtime( $path );
			if ( false === $mtime || $mtime >= $cutoff ) {
				continue;
			}

			if ( PreflightStagingPath::is_ephemeral_path( $path ) ) {
				FilesystemHelper::delete( $path );
			}
		}
	}

	/**
	 * Preferred filename when promoting a chunked upload into imports/.
	 *
	 * @param string $chunk_job_id Chunk job identifier.
	 * @param string $posted_name  Optional sanitized original name from the request.
	 * @return string
	 */
	public static function preferred_chunk_filename( string $chunk_job_id, string $posted_name = '' ): string {
		$posted_name = basename( sanitize_file_name( $posted_name ) );
		$extension   = strtolower( pathinfo( $posted_name, PATHINFO_EXTENSION ) );
		if ( '' !== $posted_name && in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return $posted_name;
		}

		$job_id = sanitize_key( $chunk_job_id );
		if ( '' === $job_id ) {
			return 'chunked-upload.wpbkp';
		}

		return sprintf( 'chunk-%s.wpbkp', $job_id );
	}

	/**
	 * Build a safe archive basename with the expected extension.
	 *
	 * @param string $original_name Preferred name.
	 * @param string $extension     wpbkp|json.
	 * @return string
	 */
	public static function resolve_archive_basename( string $original_name, string $extension ): string {
		$extension = strtolower( $extension );
		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			$extension = 'wpbkp';
		}

		$basename = basename( sanitize_file_name( $original_name ) );
		if ( '' === $basename || ! str_ends_with( strtolower( $basename ), '.' . $extension ) ) {
			return 'import-' . gmdate( 'Y-m-d-His' ) . '.' . $extension;
		}

		return $basename;
	}

	/**
	 * Relocate a file via rename; copy+delete for small cross-volume moves.
	 *
	 * @param string $from Source path.
	 * @param string $to   Destination path.
	 * @return bool
	 */
	private static function relocate( string $from, string $to ): bool {
		if ( FilesystemHelper::rename_without_copy( $from, $to ) ) {
			return true;
		}

		// Cross-volume: copy only small files so multi-GB archives never double on disk.
		$size = is_file( $from ) ? (int) filesize( $from ) : 0;
		if ( $size <= 0 || $size > self::COPY_FALLBACK_MAX_BYTES ) {
			return false;
		}

		if ( ! FilesystemHelper::copy( $from, $to, true ) ) {
			return false;
		}

		FilesystemHelper::delete( $from );
		return is_file( $to );
	}

	/**
	 * Resolve a supported archive extension.
	 *
	 * @param string $name_hint   Filename hint.
	 * @param string $path_hint   Filesystem path hint.
	 * @param string $known_extension Optional already-validated extension.
	 * @return string
	 */
	private static function resolve_extension( string $name_hint, string $path_hint, string $known_extension = '' ): string {
		$known_extension = strtolower( sanitize_file_name( $known_extension ) );
		if ( in_array( $known_extension, array( 'wpbkp', 'json' ), true ) ) {
			return $known_extension;
		}

		$extension = strtolower( pathinfo( $name_hint, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return $extension;
		}

		$extension = strtolower( pathinfo( $path_hint, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return $extension;
		}

		return 'wpbkp';
	}

	/**
	 * Delete chunk job json/tmp without touching imports/.
	 *
	 * @param object|null $job Chunk job.
	 * @return void
	 */
	private static function delete_job_metadata( $job ): void {
		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}

	/**
	 * Whether the file already lives in the server imports directory.
	 *
	 * @param string $absolute_path Real filesystem path.
	 * @return bool
	 */
	private static function is_under_imports( string $absolute_path ): bool {
		return self::path_is_under( $absolute_path, PluginConfig::imports_dir() );
	}

	/**
	 * Whether the path is under imports/, preflight/, or jobs/.
	 *
	 * @param string $absolute_path Real filesystem path.
	 * @return bool
	 */
	private static function is_under_plugin_storage( string $absolute_path ): bool {
		$dirs = PluginConfig::get_required_directories();
		foreach ( array( 'imports', 'preflight', 'jobs' ) as $key ) {
			if ( ! empty( $dirs[ $key ] ) && self::path_is_under( $absolute_path, (string) $dirs[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Prefix check against a configured directory root.
	 *
	 * @param string $absolute_path Real filesystem path.
	 * @param string $root          Directory root.
	 * @return bool
	 */
	private static function path_is_under( string $absolute_path, string $root ): bool {
		$real_root = realpath( untrailingslashit( wp_normalize_path( $root ) ) );
		if ( false === $real_root ) {
			return false;
		}

		$prefix = trailingslashit( wp_normalize_path( $real_root ) );
		$file   = wp_normalize_path( $absolute_path );

		return str_starts_with( $file, $prefix );
	}
}
