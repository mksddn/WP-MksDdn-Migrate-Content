<?php
/**
 * @file: ImportArtifactCleanup.php
 * @description: Stages browser uploads into preflight/; promotes ephemeral archives into imports/ after success or abort
 * @dependencies: Chunking\ChunkJobRepository, Config\PluginConfig, Support\FilesystemHelper, Support\PreflightStagingPath
 * @created: 2026-08-25
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Services\PluginLogger;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps a reusable copy under imports/ after success or explicit abort (cancel/dismiss).
 *
 * @since 2.6.1
 */
final class ImportArtifactCleanup {

	/**
	 * Max size allowed for a copy fallback when rename cannot cross volumes.
	 */
	private const COPY_FALLBACK_MAX_BYTES = 32 * MB_IN_BYTES;

	/**
	 * Retention for preflight report JSON bodies (must match PreflightReportStore::TTL_SECONDS).
	 *
	 * Staged archives keep the longer default TTL; report files are session-scoped.
	 */
	private const REPORT_JSON_TTL_SECONDS = 900;

	/**
	 * Promote a managed archive (or resolve a chunk job) into imports/ for reuse.
	 *
	 * Used on cancel/dismiss paths. Files already under imports/ are left in place.
	 * Unmanaged PHP temps outside plugin storage are deleted.
	 *
	 * @param string $source_path   Absolute path (preflight/jobs/imports or empty when chunk-only).
	 * @param string $original_name Preferred filename for imports/.
	 * @param string $chunk_job_id  Optional chunk job id (resolves path and clears metadata).
	 * @return bool True when the archive is available under imports/ (or already was).
	 */
	public static function persist_handle_for_reuse( string $source_path, string $original_name, string $chunk_job_id = '' ): bool {
		$job = null;
		$chunk_job_id = sanitize_key( $chunk_job_id );

		if ( '' !== $chunk_job_id ) {
			$job = ( new ChunkJobRepository() )->find( $chunk_job_id );
			if ( ( '' === $source_path || ! is_file( $source_path ) ) && $job ) {
				$source_path = $job->get_file_path();
			}
			if ( '' === $original_name ) {
				$original_name = self::preferred_chunk_filename( $chunk_job_id, '' );
			}
		}

		if ( '' === $source_path || ! is_file( $source_path ) ) {
			self::delete_job_metadata( $job );
			return false;
		}

		$real = realpath( $source_path );
		if ( false === $real ) {
			self::delete_job_metadata( $job );
			return false;
		}

		if ( self::is_under_plugin_storage( $real ) ) {
			return self::persist_for_reuse( $real, $original_name, $job );
		}

		self::discard_unmanaged_temp( $real );
		self::delete_job_metadata( $job );
		return false;
	}

	/**
	 * Promote an archive described by a preflight import_handle into imports/.
	 *
	 * Server-sourced handles are a no-op (already under imports/).
	 *
	 * @param array $handle Keys: source_type, staged_path, chunk_job_id, original_name, server_file.
	 * @return bool True when the archive is available under imports/ (or already was).
	 */
	public static function persist_import_handle_for_reuse( array $handle ): bool {
		$source_type   = isset( $handle['source_type'] ) ? sanitize_key( (string) $handle['source_type'] ) : '';
		$original_name = isset( $handle['original_name'] ) ? sanitize_file_name( (string) $handle['original_name'] ) : '';

		if ( 'server' === $source_type ) {
			return true;
		}

		if ( 'chunked' === $source_type ) {
			$chunk_job_id = isset( $handle['chunk_job_id'] ) ? sanitize_text_field( (string) $handle['chunk_job_id'] ) : '';
			return self::persist_handle_for_reuse( '', $original_name, $chunk_job_id );
		}

		if ( 'staged' === $source_type ) {
			$path = isset( $handle['staged_path'] ) ? (string) $handle['staged_path'] : '';
			return self::persist_handle_for_reuse( $path, $original_name, '' );
		}

		return false;
	}

	/**
	 * Resolve absolute archive paths referenced by a preflight import handle.
	 *
	 * Used to invalidate preview sessions that still point at the staged/chunk path.
	 *
	 * @param array $handle Import handle from PreflightReportStore.
	 * @return string[] Absolute paths (may be empty).
	 */
	public static function paths_from_import_handle( array $handle ): array {
		$paths       = array();
		$source_type = isset( $handle['source_type'] ) ? sanitize_key( (string) $handle['source_type'] ) : '';

		if ( 'staged' === $source_type ) {
			$path = isset( $handle['staged_path'] ) ? (string) $handle['staged_path'] : '';
			if ( '' !== $path ) {
				$paths[] = $path;
			}
		}

		if ( 'chunked' === $source_type ) {
			$chunk_job_id = isset( $handle['chunk_job_id'] ) ? sanitize_text_field( (string) $handle['chunk_job_id'] ) : '';
			if ( '' !== $chunk_job_id ) {
				$job = ( new ChunkJobRepository() )->find( $chunk_job_id );
				if ( $job ) {
					$path = $job->get_file_path();
					if ( is_string( $path ) && '' !== $path ) {
						$paths[] = $path;
					}
				}
			}
		}

		if ( 'server' === $source_type ) {
			$server_file = isset( $handle['server_file'] ) ? sanitize_file_name( (string) $handle['server_file'] ) : '';
			if ( '' !== $server_file ) {
				$paths[] = trailingslashit( PluginConfig::imports_dir() ) . $server_file;
			}
		}

		$normalized = array();
		foreach ( $paths as $path ) {
			$real = realpath( $path );
			$normalized[] = false !== $real ? $real : $path;
		}

		return array_values( array_unique( $normalized ) );
	}

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
	 * @return bool True when the archive is available under imports/ (or already was).
	 */
	public static function persist_for_reuse( string $source_path, string $original_name, $job = null ): bool {
		if ( '' === $source_path || ! is_file( $source_path ) ) {
			self::delete_job_metadata( $job );
			return false;
		}

		$real = realpath( $source_path );
		if ( false === $real ) {
			self::delete_job_metadata( $job );
			return false;
		}

		if ( self::is_under_imports( $real ) ) {
			self::delete_job_metadata( $job );
			return true;
		}

		$extension = self::resolve_extension( $original_name, $real );
		$imports_dir = PluginConfig::imports_dir();
		if ( ! is_dir( $imports_dir ) && ! wp_mkdir_p( $imports_dir ) ) {
			PluginLogger::log( 'Could not create imports directory for backup reuse.', 'ImportArtifactCleanup' );
			return false;
		}

		FilesystemHelper::protect_directory_from_web( $imports_dir );

		$basename = self::resolve_archive_basename( $original_name, $extension );
		$dest     = trailingslashit( $imports_dir ) . wp_unique_filename( $imports_dir, $basename );

		if ( ! self::relocate( $real, $dest ) ) {
			PluginLogger::log(
				sprintf( 'Could not relocate import archive into imports/: %s -> %s', $real, $dest ),
				'ImportArtifactCleanup'
			);
			return false;
		}

		delete_transient( 'mksddn_mc_server_backups' );
		self::delete_job_metadata( $job );
		return true;
	}

	/**
	 * Move a browser upload into preflight/ for the next import step.
	 *
	 * @param string $source_path   Absolute source path.
	 * @param string $original_name Preferred basename.
	 * @param string $extension     Known extension (wpbkp|json); inferred when empty.
	 * @return array{path:string,name:string,extension:string}|WP_Error
	 */
	public static function stage_into_preflight( string $source_path, string $original_name, string $extension = '' ) {
		if ( '' === $source_path || ! is_readable( $source_path ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_stage_source',
				__( 'Uploaded backup file is not readable.', 'mksddn-migrate-content' )
			);
		}

		// Strict mode: unknown types are rejected instead of falling back to "wpbkp".
		$extension = self::resolve_extension( $original_name !== '' ? $original_name : $source_path, $source_path, $extension, true );
		if ( '' === $extension ) {
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

		$now   = time();
		$files = glob( trailingslashit( $dir ) . '*' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			$mtime = filemtime( $path );
			if ( false === $mtime ) {
				continue;
			}

			$file_ttl = self::is_preflight_report_json( $path )
				? self::REPORT_JSON_TTL_SECONDS
				: max( 60, $ttl );

			if ( $mtime >= ( $now - $file_ttl ) ) {
				continue;
			}

			if ( PreflightStagingPath::is_ephemeral_path( $path ) ) {
				FilesystemHelper::delete( $path );
			}
		}
	}

	/**
	 * Whether path is a file-backed preflight report JSON (short TTL).
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private static function is_preflight_report_json( string $path ): bool {
		return (bool) preg_match( '/^report-[a-zA-Z0-9_-]+\.json$/', basename( $path ) );
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
		$suffix = '.' . $extension;
		if ( '' === $basename || substr( strtolower( $basename ), -strlen( $suffix ) ) !== $suffix ) {
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
	 * @param bool   $strict          Return an empty string for unknown types instead of "wpbkp".
	 * @return string
	 */
	private static function resolve_extension( string $name_hint, string $path_hint, string $known_extension = '', bool $strict = false ): string {
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

		return $strict ? '' : 'wpbkp';
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

		return 0 === strpos( $file, $prefix );
	}
}
