<?php
/**
 * @file: ScheduleRequestHandler.php
 * @description: Handler for schedule request operations
 * @dependencies: Automation\ScheduleManager, Admin\Services\NotificationService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Contracts\ScheduleRequestHandlerInterface;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for schedule request operations.
 *
 * @since 1.0.0
 */
class ScheduleRequestHandler implements ScheduleRequestHandlerInterface {

	/**
	 * Schedule manager.
	 *
	 * @var ScheduleManager
	 */
	private ScheduleManager $schedule_manager;

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param ScheduleManager|null    $schedule_manager Schedule manager.
	 * @param NotificationService|null $notifications    Notification service.
	 * @since 1.0.0
	 */
	public function __construct( ?ScheduleManager $schedule_manager = null, ?NotificationService $notifications = null ) {
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->notifications    = $notifications ?? new NotificationService();
	}

	/**
	 * Handle schedule save.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_schedule_save' );

		$payload = array(
			'enabled'   => isset( $_POST['schedule_enabled'] ),
			'recurrence' => sanitize_key( $_POST['schedule_recurrence'] ?? 'daily' ),
			'retention' => absint( $_POST['schedule_retention'] ?? 5 ),
		);

		$this->schedule_manager->update_settings( $payload );
		$this->notifications->redirect_with_notice( 'success', __( 'Schedule settings updated.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Handle schedule run now.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_schedule_run' );

		$result = $this->schedule_manager->run_manually();
		if ( is_wp_error( $result ) ) {
			$this->notifications->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$filename = $result['file']['name'] ?? '';
		$message  = $filename
			? sprintf(
				/* translators: %s archive filename */
				__( 'Scheduled backup %s created.', 'mksddn-migrate-content' ),
				$filename
			)
			: __( 'Scheduled backup completed.', 'mksddn-migrate-content' );

		$this->notifications->redirect_with_notice( 'success', $message );
	}

	/**
	 * Handle download scheduled backup.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_download(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- basename() is sufficient for filename.
		$filename = isset( $_GET['file'] ) ? basename( wp_unslash( $_GET['file'] ) ) : '';
		if ( '' === $filename ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Backup file is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_download_scheduled_' . $filename );

		$path = $this->schedule_manager->resolve_backup_path( $filename );
		if ( ! $path ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Backup file was not found on disk.', 'mksddn-migrate-content' ) );
		}

		$this->stream_file_download( $path, $filename, false );
	}

	/**
	 * Handle delete scheduled backup.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- basename() is sufficient for filename.
		$filename = isset( $_GET['file'] ) ? basename( wp_unslash( $_GET['file'] ) ) : '';
		if ( '' === $filename ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Backup file is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_delete_scheduled_' . $filename );

		$deleted = $this->schedule_manager->delete_backup( $filename );

		if ( ! $deleted ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Failed to delete scheduled backup. Check file permissions.', 'mksddn-migrate-content' ) );
			return;
		}

		$this->notifications->redirect_with_notice( 'success', __( 'Scheduled backup deleted.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Stream file download.
	 *
	 * @param string $path         File path.
	 * @param string $filename     Download filename.
	 * @param bool   $delete_after Whether to delete file after download.
	 * @return void
	 * @since 1.0.0
	 */
	private function stream_file_download( string $path, string $filename, bool $delete_after = true ): void {
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Export file not found.', 'mksddn-migrate-content' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filesize = filesize( $path );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . $filesize );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large archives requires native handle
		if ( $handle ) {
			fpassthru( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen for streaming
		}

		if ( $delete_after && file_exists( $path ) ) {
			FilesystemHelper::delete( $path );
		}
		exit;
	}
}

