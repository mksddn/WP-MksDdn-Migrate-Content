<?php
/**
 * @file: HistoryRepositoryInterface.php
 * @description: Contract for history repository operations
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for history repository operations.
 *
 * @since 1.0.0
 */
interface HistoryRepositoryInterface {

	/**
	 * Start new history entry.
	 *
	 * @param string $type    Entry type.
	 * @param array  $context Additional metadata.
	 * @return string Entry ID.
	 * @since 1.0.0
	 */
	public function start( string $type, array $context = array() ): string;

	/**
	 * Complete history entry.
	 *
	 * @param string $id      Entry ID.
	 * @param string $status  Final status (success|error|cancelled).
	 * @param array  $context Additional context.
	 * @return void
	 * @since 1.0.0
	 */
	public function finish( string $id, string $status, array $context = array() ): void;

	/**
	 * Get all history entries.
	 *
	 * @return array List of history entries.
	 * @since 1.0.0
	 */
	public function all(): array;

	/**
	 * Get history entry by ID.
	 *
	 * @param string $id Entry ID.
	 * @return array|null Entry data or null if not found.
	 * @since 1.0.0
	 */
	public function find( string $id ): ?array;

	/**
	 * Update progress for running entry.
	 *
	 * @param string $id      Entry ID.
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_progress( string $id, int $percent, string $message = '' ): void;

	/**
	 * Update context for existing entry.
	 *
	 * @param string $id      Entry ID.
	 * @param array  $context Context data to merge.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_context( string $id, array $context ): void;

	/**
	 * Cleanup stale running entries.
	 *
	 * @param int $threshold_seconds Seconds before marking as stale.
	 * @return int Number of cleaned entries.
	 * @since 1.0.0
	 */
	public function cleanup_stale( int $threshold_seconds = 3600 ): int;
}

