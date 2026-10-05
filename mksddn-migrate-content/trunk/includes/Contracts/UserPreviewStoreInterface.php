<?php
/**
 * @file: UserPreviewStoreInterface.php
 * @description: Contract for user preview storage operations
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for user preview storage operations.
 *
 * @since 1.0.0
 */
interface UserPreviewStoreInterface {

	/**
	 * Save preview payload and return generated ID.
	 *
	 * @param array $payload Preview data.
	 * @return string Preview ID.
	 * @since 1.0.0
	 */
	public function create( array $payload ): string;

	/**
	 * Fetch preview by ID.
	 *
	 * @param string $id Preview ID.
	 * @return array|null Preview data or null if not found.
	 * @since 1.0.0
	 */
	public function get( string $id ): ?array;

	/**
	 * Remove preview entry.
	 *
	 * @param string $id Preview ID.
	 * @return void
	 * @since 1.0.0
	 */
	public function delete( string $id ): void;

	/**
	 * Delete previews owned by the user that match a preflight report or archive path.
	 *
	 * @param string   $report_id  Preflight report id (may be empty).
	 * @param string[] $file_paths Absolute archive paths to match.
	 * @param int      $user_id    Owner user id.
	 * @return int Number of deleted preview sessions.
	 */
	public function delete_related_to_preflight( string $report_id, array $file_paths, int $user_id ): int;
}

