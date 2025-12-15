<?php
/**
 * @file: ScheduleManagerInterface.php
 * @description: Contract for schedule management operations
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for schedule management operations.
 *
 * @since 1.0.0
 */
interface ScheduleManagerInterface {

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void;

	/**
	 * Execute manual run.
	 *
	 * @return array|WP_Error Run result or error.
	 * @since 1.0.0
	 */
	public function run_manually();

	/**
	 * Update schedule settings.
	 *
	 * @param array $payload New settings.
	 * @return array Saved settings.
	 * @since 1.0.0
	 */
	public function update_settings( array $payload ): array;

	/**
	 * Return current schedule settings.
	 *
	 * @return array Current settings.
	 * @since 1.0.0
	 */
	public function get_settings(): array;

	/**
	 * Get recorded runs.
	 *
	 * @return array Recent runs.
	 * @since 1.0.0
	 */
	public function get_recent_runs(): array;

	/**
	 * Get timestamp for next scheduled run.
	 *
	 * @return int|null Next run timestamp or null.
	 * @since 1.0.0
	 */
	public function get_next_run_time(): ?int;

	/**
	 * Provide recurrence labels.
	 *
	 * @return array<string,string> Recurrence options.
	 * @since 1.0.0
	 */
	public function get_available_recurrences(): array;

	/**
	 * Resolve backup path for download.
	 *
	 * @param string $filename Backup filename.
	 * @return string|null Backup path or null.
	 * @since 1.0.0
	 */
	public function resolve_backup_path( string $filename ): ?string;

	/**
	 * Delete stored backup and remove from history.
	 *
	 * @param string $filename Archive filename.
	 * @return bool True on success.
	 * @since 1.0.0
	 */
	public function delete_backup( string $filename ): bool;

	/**
	 * Remove cron hook.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function clear_schedule(): void;
}

