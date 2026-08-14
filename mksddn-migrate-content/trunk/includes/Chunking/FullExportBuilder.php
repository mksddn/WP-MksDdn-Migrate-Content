<?php
/**
 * @file: FullExportBuilder.php
 * @description: Builds the full-site export archive in the background (WP-Cron) so the
 *               REST request that starts the job returns instantly and never hits
 *               reverse-proxy/PHP-FPM read timeouts on large sites.
 * @dependencies: ChunkJobRepositoryInterface, FullContentExporter, PluginLogger
 * @created: 2026-08-14
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Contracts\ChunkJobRepositoryInterface;
use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Services\PluginLogger;
use MksDdn\MigrateContent\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs the heavy full-site archive build outside the request/response cycle.
 *
 * @since 2.6.0
 */
class FullExportBuilder {

	/**
	 * Cron hook used to trigger the background build.
	 *
	 * @var string
	 */
	public const CRON_HOOK = 'mksddn_mc_build_full_export';

	private ChunkJobRepositoryInterface $repository;

	/**
	 * Constructor.
	 *
	 * @param ChunkJobRepositoryInterface $repository Chunk job repository.
	 * @since 2.6.0
	 */
	public function __construct( ChunkJobRepositoryInterface $repository ) {
		$this->repository = $repository;
		add_action( self::CRON_HOOK, array( $this, 'build' ), 10, 1 );
	}

	/**
	 * Build the export archive for a given job and update its status.
	 *
	 * @param string $job_id Chunk job identifier.
	 * @return void
	 * @since 2.6.0
	 */
	public function build( string $job_id ): void {
		$job  = $this->repository->get( $job_id );
		$data = $job->get_data();

		// Job was cancelled/expired before the cron event fired, or belongs to a different flow.
		if ( 'download' !== ( $data['mode'] ?? '' ) || 'pending' !== ( $data['status'] ?? '' ) ) {
			if ( 'cancelled' === ( $data['status'] ?? '' ) ) {
				$job->delete();
			}
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $job->update_if_status( 'pending', array( 'status' => 'building' ) ) ) {
			return;
		}

		$file       = $job->get_file_path();
		$chunk_size = (int) ( $data['chunk_size'] ?? 5242880 );

		try {
			$export = new FullContentExporter();
			$result = $export->export_to( $file );

			if ( is_wp_error( $result ) ) {
				$message = $result->get_error_message();
				$error_data = $result->get_error_data();
				if ( is_array( $error_data ) && ! empty( $error_data['hint'] ) && is_string( $error_data['hint'] ) ) {
					$message .= ' ' . $error_data['hint'];
				}

				$this->mark_error( $job, $job_id, $file, $message );
				return;
			}

			$size = filesize( $file );
			if ( false === $size || 0 === $size ) {
				$this->mark_error(
					$job,
					$job_id,
					$file,
					__( 'Export file is empty or cannot be read.', 'mksddn-migrate-content' )
						. ' '
						. __( 'Check free disk space, hosting quota, and PHP temp directory (sys_temp_dir / upload_tmp_dir).', 'mksddn-migrate-content' )
				);
				return;
			}

			$total_chunks = (int) max( 1, ceil( $size / $chunk_size ) );

			if ( ! $job->update_if_status(
				'building',
				array(
					'status'       => 'ready',
					'total_chunks' => $total_chunks,
					'size'         => $size,
				)
			) ) {
				$job->delete();
				return;
			}

			PluginLogger::log( sprintf( 'Full export build completed for job %s (%d bytes, %d chunks).', $job_id, $size, $total_chunks ), 'FullExportBuilder' );
		} catch ( \Throwable $error ) {
			$this->mark_error(
				$job,
				$job_id,
				$file,
				__( 'Unexpected error while building the export archive.', 'mksddn-migrate-content' )
			);
			PluginLogger::log( sprintf( 'Full export build crashed for job %s: %s', $job_id, $error->getMessage() ), 'FullExportBuilder' );
		}
	}

	/**
	 * Persist an export error unless the job was cancelled, then remove partial data.
	 *
	 * @param ChunkJob $job     Export job.
	 * @param string   $job_id  Export job identifier.
	 * @param string   $file    Partial archive path.
	 * @param string   $message User-visible error message.
	 * @return void
	 */
	private function mark_error( ChunkJob $job, string $job_id, string $file, string $message ): void {
		FilesystemHelper::delete( $file );

		if ( ! $job->update_if_status( 'building', array( 'status' => 'error', 'error' => $message ) ) ) {
			$job->delete();
			return;
		}

		PluginLogger::log( sprintf( 'Full export build failed for job %s: %s', $job_id, $message ), 'FullExportBuilder' );
	}
}
