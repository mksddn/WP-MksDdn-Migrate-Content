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
	 * @return string
	 */
	public function create( array $payload ): string;

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
}

