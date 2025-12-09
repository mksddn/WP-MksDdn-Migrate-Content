<?php
/**
 * Handles persistence of automation schedule settings.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule settings storage helper.
 */
class ScheduleSettings {

	private const OPTION       = 'mksddn_mc_schedule';
	private const RUNS_OPTION  = 'mksddn_mc_schedule_runs';
	private const HISTORY_SIZE = 10;

	/**
	 * Return sanitized schedule settings.
	 *
	 * @return array
	 */
	public function get(): array {
		$defaults = array(
			'enabled'      => false,
			'recurrence'   => 'daily',
			'retention'    => 5,
			'last_run'     => '',
			'last_status'  => '',
			'last_message' => '',
		);

		$value = get_option( self::OPTION, array() );
		$data  = is_array( $value ) ? $value : array();
		$data  = wp_parse_args( $data, $defaults );

		$data['enabled']   = (bool) $data['enabled'];
		$data['recurrence'] = $this->sanitize_recurrence( $data['recurrence'] );
		$data['retention']  = max( 1, absint( $data['retention'] ) );

		return $data;
	}

	/**
	 * Persist new settings.
	 *
	 * @param array $payload Settings payload.
	 * @return array Saved settings.
	 */
	public function save( array $payload ): array {
		$current = $this->get();

		$current['enabled']   = ! empty( $payload['enabled'] );
		$current['recurrence'] = $this->sanitize_recurrence( $payload['recurrence'] ?? $current['recurrence'] );
		$current['retention']  = max( 1, absint( $payload['retention'] ?? $current['retention'] ) );

		update_option( self::OPTION, $current, false );

		return $current;
	}

	/**
	 * Update last run metadata.
	 *
	 * @param array $settings Current settings.
	 * @param array $run      Run metadata.
	 * @return void
	 */
	public function record_last_run( array $settings, array $run ): void {
		$settings['last_run']     = $run['created_at'] ?? gmdate( 'c' );
		$settings['last_status']  = sanitize_text_field( $run['status'] ?? '' );
		$settings['last_message'] = isset( $run['message'] ) ? wp_kses_post( $run['message'] ) : '';

		update_option( self::OPTION, $settings, false );

		$this->record_run_history( $run );
	}

	/**
	 * Get recorded run history entries.
	 *
	 * @return array
	 */
	public function get_recent_runs(): array {
		$value = get_option( self::RUNS_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Record run history entry.
	 *
	 * @param array $run Run meta.
	 * @return void
	 */
	private function record_run_history( array $run ): void {
		$runs = $this->get_recent_runs();
		array_unshift( $runs, $this->sanitize_run( $run ) );
		$runs = array_slice( $runs, 0, self::HISTORY_SIZE );

		update_option( self::RUNS_OPTION, $runs, false );
	}

	/**
	 * Return directory for scheduled backups.
	 *
	 * @return string
	 */
	public function get_storage_dir(): string {
		$uploads = wp_upload_dir();
		$base    = $uploads['basedir'] ?? WP_CONTENT_DIR . '/uploads';

		return trailingslashit( $base ) . 'mksddn-mc/scheduled';
	}

	/**
	 * Resolve file path for stored backup filename.
	 *
	 * @param string $filename File name.
	 * @return string|null
	 */
	public function resolve_backup_path( string $filename ): ?string {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			return null;
		}

		$path = trailingslashit( $this->get_storage_dir() ) . $filename;

		return file_exists( $path ) ? $path : null;
	}

	/**
	 * Remove run entry by filename.
	 *
	 * @param string $filename Archive filename.
	 * @return void
	 */
	public function remove_run_by_file( string $filename ): void {
		$filename = sanitize_file_name( $filename );
		if ( '' === $filename ) {
			return;
		}

		$runs = $this->get_recent_runs();
		$runs = array_values(
			array_filter(
				$runs,
				static function ( $run ) use ( $filename ) {
					return ( $run['file']['name'] ?? '' ) !== $filename;
				}
			)
		);

		update_option( self::RUNS_OPTION, $runs, false );
	}

	/**
	 * Sanitize recurrence slug.
	 *
	 * @param string $value Candidate value.
	 * @return string
	 */
	public function sanitize_recurrence( string $value ): string {
		$allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
		$value   = sanitize_key( $value );

		return in_array( $value, $allowed, true ) ? $value : 'daily';
	}

	/**
	 * Sanitize single run entry.
	 *
	 * @param array $run Run meta.
	 * @return array
	 */
	private function sanitize_run( array $run ): array {
		$file = array();
		if ( isset( $run['file'] ) && is_array( $run['file'] ) ) {
			$file = array(
				'name' => sanitize_file_name( $run['file']['name'] ?? '' ),
				'size' => isset( $run['file']['size'] ) ? absint( $run['file']['size'] ) : 0,
			);
		}

		return array(
			'status'     => sanitize_text_field( $run['status'] ?? '' ),
			'message'    => isset( $run['message'] ) ? wp_kses_post( $run['message'] ) : '',
			'created_at' => $run['created_at'] ?? gmdate( 'c' ),
			'file'       => $file,
		);
	}
}


