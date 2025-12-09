<?php
/**
 * Runs scheduled full-site backups.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Automation;

use Mksddn_MC\Filesystem\FullContentExporter;
use Mksddn_MC\Recovery\HistoryRepository;
use Mksddn_MC\Recovery\JobLock;
use Mksddn_MC\Support\FilenameBuilder;
use Mksddn_MC\Support\FilesystemHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates full-site archives for automation.
 */
class ScheduledBackupRunner {

	private FullContentExporter $exporter;
	private HistoryRepository $history;
	private JobLock $job_lock;
	private ScheduleSettings $settings;

	/**
	 * Setup runner.
	 *
	 * @param FullContentExporter|null $exporter Exporter instance.
	 * @param HistoryRepository|null   $history  History repository.
	 * @param JobLock|null             $job_lock Job lock helper.
	 * @param ScheduleSettings|null    $settings Settings helper.
	 */
	public function __construct( ?FullContentExporter $exporter = null, ?HistoryRepository $history = null, ?JobLock $job_lock = null, ?ScheduleSettings $settings = null ) {
		$this->exporter = $exporter ?? new FullContentExporter();
		$this->history  = $history ?? new HistoryRepository();
		$this->job_lock = $job_lock ?? new JobLock();
		$this->settings = $settings ?? new ScheduleSettings();
	}

	/**
	 * Execute scheduled export.
	 *
	 * @param array $settings Current schedule settings.
	 * @return array|WP_Error Run metadata or error.
	 */
	public function run( array $settings ) {
		$lock_id = $this->job_lock->acquire( 'scheduled-export' );
		if ( is_wp_error( $lock_id ) ) {
			return $lock_id;
		}

		try {
			return $this->perform_export( $settings, $lock_id );
		} finally {
			$this->job_lock->release( $lock_id );
		}
	}

	/**
	 * Perform archive export and handle retention.
	 *
	 * @param array  $settings Settings.
	 * @param string $lock_id  Lock identifier.
	 * @return array|WP_Error
	 */
	private function perform_export( array $settings, string $lock_id ) {
		$history_id = $this->history->start(
			'export',
			array(
				'mode' => 'scheduled',
			)
		);

		$temp = wp_tempnam( 'mksddn-scheduled-' );
		if ( ! $temp ) {
			return $this->fail_run( $history_id, __( 'Unable to allocate temporary file for scheduled export.', 'mksddn-migrate-content' ) );
		}

		$result = $this->exporter->export_to( $temp );
		if ( is_wp_error( $result ) ) {
			FilesystemHelper::delete( $temp );
			return $this->fail_run( $history_id, $result->get_error_message() );
		}

		$dir = $this->settings->get_storage_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			FilesystemHelper::delete( $temp );
			return $this->fail_run( $history_id, __( 'Unable to create storage directory for scheduled backups.', 'mksddn-migrate-content' ) );
		}

		$filename = FilenameBuilder::build( 'scheduled-backup', 'wpbkp' );
		$target   = trailingslashit( $dir ) . $filename;

		$move = FilesystemHelper::move( $temp, $target, true );
		if ( ! $move ) {
			$copied = FilesystemHelper::copy( $temp, $target, true );
			FilesystemHelper::delete( $temp );
			if ( ! $copied ) {
				return $this->fail_run( $history_id, __( 'Unable to store scheduled backup file. Check permissions.', 'mksddn-migrate-content' ) );
			}
		}

		$size = file_exists( $target ) ? (int) filesize( $target ) : 0;

		$this->history->finish(
			$history_id,
			'success',
			array(
				'mode' => 'scheduled',
				'file' => $filename,
			)
		);

		$this->enforce_retention( $dir, (int) ( $settings['retention'] ?? 5 ) );

		return array(
			'status'     => 'success',
			'message'    => __( 'Scheduled backup created.', 'mksddn-migrate-content' ),
			'created_at' => gmdate( 'c' ),
			'file'       => array(
				'name' => $filename,
				'size' => $size,
			),
		);
	}

	/**
	 * Handle failed run.
	 *
	 * @param string $history_id History entry.
	 * @param string $message    Error message.
	 * @return WP_Error
	 */
	private function fail_run( string $history_id, string $message ) {
		$this->history->finish(
			$history_id,
			'error',
			array(
				'message' => $message,
				'mode'    => 'scheduled',
			)
		);

		return new WP_Error( 'mksddn_mc_schedule_failed', $message );
	}

	/**
	 * Enforce storage retention by removing the oldest archives.
	 *
	 * @param string $dir      Storage directory.
	 * @param int    $limit    Number of files to keep.
	 * @return void
	 */
	private function enforce_retention( string $dir, int $limit ): void {
		$limit = max( 1, $limit );
		$files = glob( trailingslashit( $dir ) . '*.wpbkp' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_glob

		if ( empty( $files ) ) {
			return;
		}

		usort(
			$files,
			static function ( $a, $b ) {
				return filemtime( $b ) <=> filemtime( $a );
			}
		);

		$excess = array_slice( $files, $limit );
		foreach ( $excess as $file ) {
			FilesystemHelper::delete( $file );
		}
	}
}


