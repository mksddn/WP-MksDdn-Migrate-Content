<?php
/**
 * Coordinates cron scheduling and automation UI helpers.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Automation;

use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles cron setup and manual runs.
 */
class ScheduleManager {

	public const CRON_HOOK = 'mksddn_mc_cron_run';

	private ScheduleSettings $settings;
	private ScheduledBackupRunner $runner;

	/**
	 * Setup manager.
	 *
	 * @param ScheduleSettings|null     $settings Settings helper.
	 * @param ScheduledBackupRunner|null $runner  Runner instance.
	 */
	public function __construct( ?ScheduleSettings $settings = null, ?ScheduledBackupRunner $runner = null ) {
		$this->settings = $settings ?? new ScheduleSettings();
		$this->runner   = $runner ?? new ScheduledBackupRunner( null, null, null, $this->settings );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'register_custom_schedules' ) );
		add_action( 'init', array( $this, 'maybe_schedule_event' ) );
		add_action( self::CRON_HOOK, array( $this, 'handle_cron_run' ) );
	}

	/**
	 * Register custom cron schedules.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_custom_schedules( array $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'mksddn-migrate-content' ),
			);
		}

		return $schedules;
	}

	/**
	 * Ensure cron event state matches settings.
	 */
	public function maybe_schedule_event(): void {
		$settings = $this->settings->get();

		if ( ! $settings['enabled'] ) {
			$this->clear_schedule();
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $settings['recurrence'], self::CRON_HOOK );
		}
	}

	/**
	 * Handle automated cron run.
	 *
	 * @return void
	 */
	public function handle_cron_run(): void {
		$settings = $this->settings->get();
		if ( ! $settings['enabled'] ) {
			$this->clear_schedule();
			return;
		}

		$this->execute_run( $settings );
	}

	/**
	 * Execute manual run.
	 *
	 * @return array|WP_Error
	 */
	public function run_manually() {
		$settings = $this->settings->get();
		return $this->execute_run( $settings );
	}

	/**
	 * Execute runner and persist result.
	 *
	 * @param array $settings Schedule settings.
	 * @return array|WP_Error
	 */
	private function execute_run( array $settings ) {
		$result = $this->runner->run( $settings );

		if ( is_wp_error( $result ) ) {
			$this->settings->record_last_run(
				$settings,
				array(
					'status'     => 'error',
					'message'    => $result->get_error_message(),
					'created_at' => gmdate( 'c' ),
					'file'       => array(),
				)
			);

			return $result;
		}

		$this->settings->record_last_run( $settings, $result );

		return $result;
	}

	/**
	 * Update schedule settings.
	 *
	 * @param array $payload New settings.
	 * @return array Saved settings.
	 */
	public function update_settings( array $payload ): array {
		$settings = $this->settings->save( $payload );
		$this->clear_schedule();

		if ( $settings['enabled'] ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $settings['recurrence'], self::CRON_HOOK );
		}

		return $settings;
	}

	/**
	 * Return current schedule settings.
	 *
	 * @return array
	 */
	public function get_settings(): array {
		return $this->settings->get();
	}

	/**
	 * Get recorded runs.
	 *
	 * @return array
	 */
	public function get_recent_runs(): array {
		return $this->settings->get_recent_runs();
	}

	/**
	 * Get timestamp for next scheduled run.
	 *
	 * @return int|null
	 */
	public function get_next_run_time(): ?int {
		$next = wp_next_scheduled( self::CRON_HOOK );

		return $next ? (int) $next : null;
	}

	/**
	 * Provide recurrence labels.
	 *
	 * @return array<string,string>
	 */
	public function get_available_recurrences(): array {
		return array(
			'hourly'     => __( 'Every hour', 'mksddn-migrate-content' ),
			'twicedaily' => __( 'Twice daily', 'mksddn-migrate-content' ),
			'daily'      => __( 'Once daily', 'mksddn-migrate-content' ),
			'weekly'     => __( 'Once weekly', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * Resolve backup path for download.
	 *
	 * @param string $filename Backup filename.
	 * @return string|null
	 */
	public function resolve_backup_path( string $filename ): ?string {
		return $this->settings->resolve_backup_path( $filename );
	}

	/**
	 * Delete stored backup and remove from history.
	 *
	 * @param string $filename Archive filename.
	 * @return bool
	 */
	public function delete_backup( string $filename ): bool {
		$path = $this->resolve_backup_path( $filename );
		if ( $path && file_exists( $path ) ) {
			FilesystemHelper::delete( $path );
		}

		$this->settings->remove_run_by_file( $filename );

		return true;
	}

	/**
	 * Remove cron hook.
	 *
	 * @return void
	 */
	public function clear_schedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cleanup on plugin deactivation.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}
}


