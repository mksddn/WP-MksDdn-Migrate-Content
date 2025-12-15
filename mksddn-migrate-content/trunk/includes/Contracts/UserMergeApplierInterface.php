<?php
/**
 * @file: UserMergeApplierInterface.php
 * @description: Contract for applying user merge operations
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for applying user merge operations.
 *
 * @since 1.0.0
 */
interface UserMergeApplierInterface {

	/**
	 * Extract remote users keyed by email for merging.
	 *
	 * @param array $database    Database dump.
	 * @param array $table_hints Optional hints for table names.
	 * @return array Remote users data.
	 * @since 1.0.0
	 */
	public function extract_remote_users( array $database, array $table_hints = array() ): array;

	/**
	 * Remove user tables from database dump prior to import.
	 *
	 * @param array $database Database dump reference.
	 * @param array $tables   Table hints.
	 * @return void
	 * @since 1.0.0
	 */
	public function strip_user_tables( array &$database, array $tables ): void;

	/**
	 * Apply merge plan to current site.
	 *
	 * @param array  $remote_users Remote users keyed by lowercase email.
	 * @param array  $plan         Normalized plan keyed by email.
	 * @param string $remote_prefix Remote table prefix for meta normalization.
	 * @return array|WP_Error Summary or error.
	 * @since 1.0.0
	 */
	public function merge( array $remote_users, array $plan, string $remote_prefix ): array|WP_Error;

	/**
	 * Get last summary stats.
	 *
	 * @return array Summary statistics.
	 * @since 1.0.0
	 */
	public function get_summary(): array;
}

