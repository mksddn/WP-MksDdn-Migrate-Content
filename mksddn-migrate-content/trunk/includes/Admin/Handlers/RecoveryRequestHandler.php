<?php
/**
 * @file: RecoveryRequestHandler.php
 * @description: Handler for recovery request operations (snapshots, rollback)
 * @dependencies: Recovery\SnapshotManager, Recovery\HistoryRepository, Recovery\JobLock, Filesystem\FullContentImporter, Support\SiteUrlGuard, Admin\Services\NotificationService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Contracts\RecoveryRequestHandlerInterface;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Recovery\JobLock;
use MksDdn\MigrateContent\Recovery\SnapshotManager;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for recovery request operations.
 *
 * @since 1.0.0
 */
class RecoveryRequestHandler implements RecoveryRequestHandlerInterface {

	/**
	 * Snapshot manager.
	 *
	 * @var SnapshotManager
	 */
	private SnapshotManager $snapshot_manager;

	/**
	 * History repository.
	 *
	 * @var HistoryRepository
	 */
	private HistoryRepository $history;

	/**
	 * Job lock.
	 *
	 * @var JobLock
	 */
	private JobLock $job_lock;

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param SnapshotManager|null    $snapshot_manager Snapshot manager.
	 * @param HistoryRepository|null  $history          History repository.
	 * @param JobLock|null            $job_lock         Job lock.
	 * @param NotificationService|null $notifications    Notification service.
	 * @since 1.0.0
	 */
	/**
	 * Schedule manager.
	 *
	 * @var ScheduleManager
	 */
	private ScheduleManager $schedule_manager;

	/**
	 * Constructor.
	 *
	 * @param SnapshotManager|null     $snapshot_manager Snapshot manager.
	 * @param HistoryRepository|null   $history          History repository.
	 * @param JobLock|null             $job_lock         Job lock.
	 * @param NotificationService|null $notifications    Notification service.
	 * @param ScheduleManager|null     $schedule_manager Schedule manager.
	 * @since 1.0.0
	 */
	public function __construct(
		?SnapshotManager $snapshot_manager = null,
		?HistoryRepository $history = null,
		?JobLock $job_lock = null,
		?NotificationService $notifications = null,
		?ScheduleManager $schedule_manager = null
	) {
		$this->snapshot_manager = $snapshot_manager ?? new SnapshotManager();
		$this->history          = $history ?? new HistoryRepository();
		$this->job_lock         = $job_lock ?? new JobLock();
		$this->notifications    = $notifications ?? new NotificationService();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
	}

	/**
	 * Handle snapshot rollback.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_rollback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$history_id  = isset( $_POST['history_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_id'] ) ) : '';
		$snapshot_id = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : '';

		check_admin_referer( 'mksddn_mc_rollback_' . $history_id );

		if ( '' === $snapshot_id ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Snapshot is missing.', 'mksddn-migrate-content' ) );
		}

		$snapshot = $this->snapshot_manager->get( $snapshot_id );
		if ( ! $snapshot ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Snapshot not found on disk.', 'mksddn-migrate-content' ) );
		}

		$lock_id = $this->job_lock->acquire( 'rollback' );
		if ( is_wp_error( $lock_id ) ) {
			$this->notifications->redirect_with_notice( 'error', $lock_id->get_error_message() );
		}

		$result = $this->restore_snapshot( $snapshot, 'manual' );

		if ( is_wp_error( $result ) ) {
			$this->job_lock->release( $lock_id );
			$this->notifications->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$this->job_lock->release( $lock_id );
		$this->notifications->redirect_with_notice( 'success', __( 'Snapshot restored successfully.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Handle snapshot delete.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$history_id  = isset( $_POST['history_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_id'] ) ) : '';
		$snapshot_id = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : '';

		check_admin_referer( 'mksddn_mc_delete_snapshot_' . $history_id );

		if ( '' === $snapshot_id ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Snapshot identifier is missing.', 'mksddn-migrate-content' ) );
		}

		$snapshot = $this->snapshot_manager->get( $snapshot_id );
		if ( $snapshot ) {
			$this->snapshot_manager->delete( $snapshot_id );
		}

		// Delete scheduled backup file if this is a scheduled entry.
		if ( $history_id ) {
			$entry   = $this->history->find( $history_id );
			$context = $entry['context'] ?? array();

			if ( 'scheduled' === ( $context['mode'] ?? '' ) && ! empty( $context['file'] ) ) {
				$this->schedule_manager->delete_backup( $context['file'] );
			}

			$this->history->update_context(
				$history_id,
				array(
					'snapshot_id'    => '',
					'snapshot_label' => '',
					'file'           => '',
					'action'         => 'snapshot_deleted',
				)
			);
		}

		$this->notifications->redirect_with_notice( 'success', __( 'Backup deleted successfully.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Restore snapshot.
	 *
	 * @param array  $snapshot Snapshot metadata.
	 * @param string $action   manual|auto.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function restore_snapshot( array $snapshot, string $action = 'manual' ) {
		if ( empty( $snapshot['path'] ) || ! file_exists( $snapshot['path'] ) ) {
			return new WP_Error( 'mksddn_snapshot_missing', __( 'Snapshot archive is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$history_entry = $this->history->start(
			'rollback',
			array(
				'snapshot_id'    => $snapshot['id'] ?? '',
				'snapshot_label' => $snapshot['label'] ?? '',
				'action'         => $action,
			)
		);

		$guard    = new SiteUrlGuard();
		$importer = new FullContentImporter();
		$result   = $importer->import_from( $snapshot['path'], $guard );

		if ( is_wp_error( $result ) ) {
			$this->history->finish(
				$history_entry,
				'error',
				array( 'message' => $result->get_error_message() )
			);

			return $result;
		}

		$guard->restore();
		$this->history->finish( $history_entry, 'success' );

		$guard->restore();
		$this->normalize_plugin_storage();
		return true;
	}

	/**
	 * Normalize storage paths for known plugins.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function normalize_plugin_storage(): void {
		$target = trailingslashit( WP_CONTENT_DIR ) . 'ai1wm-backups';
		wp_mkdir_p( $target );
		update_option( 'ai1wm_storage_path', $target );
	}
}

