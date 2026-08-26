<?php
/**
 * @file: ImportArtifactCleanup.php
 * @description: Promotes ephemeral import files into imports/ after success; purges stale preflight files
 * @dependencies: Chunking\ChunkJobRepository, Config\PluginConfig, Support\FilesystemHelper, Support\PreflightStagingPath
 * @created: 2026-08-25
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Services\PluginLogger;

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
	 * Rename an ephemeral backup into imports/ and drop chunk-job metadata.
	 *
	 * Does not copy. Files already in imports/ are left in place. On rename
	 * failure the source (jobs/preflight) is kept so the archive is not lost.
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

		$extension = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			$extension = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		}
		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			$extension = 'wpbkp';
		}

		$imports_dir = PluginConfig::imports_dir();
		if ( ! is_dir( $imports_dir ) && ! wp_mkdir_p( $imports_dir ) ) {
			PluginLogger::log( 'Could not create imports directory for backup reuse.', 'ImportArtifactCleanup' );
			return;
		}

		FilesystemHelper::protect_directory_from_web( $imports_dir );

		$basename = basename( sanitize_file_name( $original_name ) );
		if ( '' === $basename || ! str_ends_with( strtolower( $basename ), '.' . $extension ) ) {
			$basename = 'import-' . gmdate( 'Y-m-d-His' ) . '.' . $extension;
		}

		$dest = trailingslashit( $imports_dir ) . wp_unique_filename( $imports_dir, $basename );
		if ( ! FilesystemHelper::rename_without_copy( $real, $dest ) ) {
			PluginLogger::log(
				sprintf( 'Could not rename import archive into imports/ without copying: %s -> %s', $real, $dest ),
				'ImportArtifactCleanup'
			);
			return;
		}

		delete_transient( 'mksddn_mc_server_backups' );
		self::delete_job_metadata( $job );
	}

	/**
	 * Delete a chunk job payload and metadata under uploads/mksddn-mc/jobs/.
	 *
	 * @param string $job_id Chunk job identifier.
	 * @return void
	 */
	public static function delete_chunk_job( string $job_id ): void {
		$job_id = sanitize_key( $job_id );
		if ( '' === $job_id ) {
			return;
		}

		( new ChunkJobRepository() )->get( $job_id )->delete();
	}

	/**
	 * Delete a preflight-staged file (browser upload between preflight and import).
	 *
	 * Never deletes files in the user-managed imports directory.
	 *
	 * @param string $path Absolute file path.
	 * @return void
	 */
	public static function delete_preflight_file( string $path ): void {
		if ( '' === $path || ! PreflightStagingPath::is_ephemeral_path( $path ) ) {
			return;
		}

		FilesystemHelper::delete( $path );
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
		$root = realpath( untrailingslashit( wp_normalize_path( PluginConfig::imports_dir() ) ) );
		if ( false === $root ) {
			return false;
		}

		$prefix = trailingslashit( wp_normalize_path( $root ) );
		$file   = wp_normalize_path( $absolute_path );

		return str_starts_with( $file, $prefix );
	}
}
