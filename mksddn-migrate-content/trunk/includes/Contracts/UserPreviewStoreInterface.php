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
}

