<?php
/**
 * @file: UserMergeRequestHandler.php
 * @description: Handler for user merge request operations
 * @dependencies: Users\UserPreviewStore, Admin\Services\NotificationService, Support\ImportArtifactCleanup
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Contracts\UserMergeRequestHandlerInterface;
use MksDdn\MigrateContent\Support\ImportArtifactCleanup;
use MksDdn\MigrateContent\Users\UserPreviewStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for user merge request operations.
 *
 * @since 1.0.0
 */
class UserMergeRequestHandler implements UserMergeRequestHandlerInterface {

	/**
	 * User preview store.
	 *
	 * @var UserPreviewStore
	 */
	private UserPreviewStore $preview_store;

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param UserPreviewStore|null   $preview_store  User preview store.
	 * @param NotificationService|null $notifications Notification service.
	 * @since 1.0.0
	 */
	public function __construct( ?UserPreviewStore $preview_store = null, ?NotificationService $notifications = null ) {
		$this->preview_store = $preview_store ?? new UserPreviewStore();
		$this->notifications = $notifications ?? new NotificationService();
	}

	/**
	 * Handle cancel user preview.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_cancel_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		check_admin_referer( 'mksddn_mc_cancel_preview_' . $preview_id );

		if ( '' === $preview_id ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Preview identifier is missing.', 'mksddn-migrate-content' ) );
		}

		$preview = $this->preview_store->get( $preview_id );
		if ( ! $preview ) {
			$this->notifications->redirect_with_notice(
				'error',
				__( 'Preview not found or has expired.', 'mksddn-migrate-content' )
			);
		}

		$kept = $this->cleanup_preview_resources( $preview );
		$this->preview_store->delete( $preview_id );

		if ( $kept ) {
			$this->notifications->redirect_with_notice(
				'success',
				__( 'User selection cancelled. The backup was kept under Server files for reuse.', 'mksddn-migrate-content' )
			);
		}

		$this->notifications->redirect_with_notice(
			'error',
			__( 'User selection cancelled, but the backup could not be moved to Server files. Re-upload the archive if you need it again.', 'mksddn-migrate-content' )
		);
	}

	/**
	 * Promote managed archives into imports/ and drop unmanaged PHP temps.
	 *
	 * @param array $preview Preview payload.
	 * @return bool True when the archive is available under imports/.
	 * @since 1.0.0
	 */
	private function cleanup_preview_resources( array $preview ): bool {
		$path          = isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '';
		$original_name = isset( $preview['original_name'] ) ? sanitize_file_name( (string) $preview['original_name'] ) : '';
		$chunk_job_id  = isset( $preview['chunk_job_id'] ) ? sanitize_text_field( (string) $preview['chunk_job_id'] ) : '';

		return ImportArtifactCleanup::persist_handle_for_reuse( $path, $original_name, $chunk_job_id );
	}
}

