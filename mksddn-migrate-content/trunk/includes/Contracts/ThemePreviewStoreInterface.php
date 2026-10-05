<?php
/**
 * @file: ThemePreviewStoreInterface.php
 * @description: Contract for theme preview storage operations
 * @dependencies: None
 * @created: 2026-02-21
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for theme preview storage operations.
 *
 * @since 2.1.0
 */
interface ThemePreviewStoreInterface {

	/**
	 * Save preview payload and return generated ID.
	 *
	 * @param array $payload Preview data.
	 * @return string|\WP_Error Preview id or error when the session could not be stored.
	 */
	public function create( array $payload );

	/**
	 * Fetch preview by ID.
	 *
	 * @param string $id Preview ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array;

	/**
	 * Remove preview entry.
	 *
	 * @param string $id Preview ID.
	 * @return void
	 */
	public function delete( string $id ): void;

	/**
	 * Delete previews owned by the user that match a preflight report or archive path.
	 *
	 * Used when dismissing a preflight session so theme apply cannot keep a stale file_path.
	 *
	 * @param string   $report_id  Preflight report id (may be empty).
	 * @param string[] $file_paths Absolute archive paths to match.
	 * @param int      $user_id    Owner user id.
	 * @return int Number of deleted preview sessions.
	 */
	public function delete_related_to_preflight( string $report_id, array $file_paths, int $user_id ): int;
}

