<?php
/**
 * @file: ChunkJobRepositoryInterface.php
 * @description: Contract for chunk job repository operations
 * @dependencies: Chunking\ChunkJob
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

use MksDdn\MigrateContent\Chunking\ChunkJob;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for chunk job repository operations.
 *
 * @since 1.0.0
 */
interface ChunkJobRepositoryInterface {

	/**
	 * Get chunk job by ID.
	 *
	 * Creates metadata if missing. Prefer {@see find()} when the job must already exist.
	 *
	 * @param string $job_id Job ID.
	 * @return ChunkJob Job instance.
	 * @since 1.0.0
	 */
	public function get( string $job_id ): ChunkJob;

	/**
	 * Load an existing chunk job without creating metadata.
	 *
	 * @param string $job_id Job ID.
	 * @return ChunkJob|null Job instance or null when the job file is missing.
	 * @since 2.6.0
	 */
	public function find( string $job_id ): ?ChunkJob;

	/**
	 * Create new chunk job.
	 *
	 * @return ChunkJob New job instance.
	 * @since 1.0.0
	 */
	public function create(): ChunkJob;
}

