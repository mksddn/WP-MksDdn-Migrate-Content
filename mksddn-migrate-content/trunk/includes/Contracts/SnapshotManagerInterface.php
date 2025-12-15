<?php
/**
 * @file: SnapshotManagerInterface.php
 * @description: Contract for snapshot management operations
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for snapshot management operations.
 *
 * @since 1.0.0
 */
interface SnapshotManagerInterface {

	/**
	 * Create snapshot archive.
	 *
	 * @param array $args Snapshot options.
	 * @return array|WP_Error Snapshot metadata or error.
	 * @since 1.0.0
	 */
	public function create( array $args = array() ): array|WP_Error;

	/**
	 * List available snapshots.
	 *
	 * @return array List of snapshot metadata.
	 * @since 1.0.0
	 */
	public function all(): array;

	/**
	 * Get snapshot metadata by ID.
	 *
	 * @param string $id Snapshot ID.
	 * @return array|null Snapshot metadata or null if not found.
	 * @since 1.0.0
	 */
	public function get( string $id ): ?array;

	/**
	 * Delete snapshot by ID.
	 *
	 * @param string $id Snapshot ID.
	 * @return bool|WP_Error True on success, false or error on failure.
	 * @since 1.0.0
	 */
	public function delete( string $id ): bool|WP_Error;
}

