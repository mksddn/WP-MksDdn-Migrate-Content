<?php
/**
 * @file: ThemePreviewRequestHandler.php
 * @description: Handler for theme preview request operations
 * @dependencies: Themes\ThemePreviewStore, Admin\Services\NotificationService, Support\FilesystemHelper, Chunking\ChunkJobRepository
 * @created: 2026-02-21
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Contracts\ThemePreviewRequestHandlerInterface;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for theme preview request operations.
 *
 * @since 2.1.0
 */
class ThemePreviewRequestHandler implements ThemePreviewRequestHandlerInterface {

	/**
	 * Theme preview store.
	 *
	 * @var ThemePreviewStore
	 */
	private ThemePreviewStore $preview_store;

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param ThemePreviewStore|null  $preview_store Theme preview store.
	 * @param NotificationService|null $notifications Notification service.
	 * @since 2.1.0
	 */
	public function __construct( ?ThemePreviewStore $preview_store = null, ?NotificationService $notifications = null ) {
		$this->preview_store = $preview_store ?? new ThemePreviewStore();
		$this->notifications = $notifications ?? new NotificationService();
	}

	/**
	 * Handle cancel theme preview.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	public function handle_cancel_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( '' === $preview_id ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Preview identifier is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_cancel_theme_preview_' . $preview_id );

		$preview = $this->preview_store->get( $preview_id );
		if ( $preview ) {
			$this->cleanup_preview_resources( $preview );
			$this->preview_store->delete( $preview_id );
		}

		$this->notifications->redirect_with_notice( 'success', __( 'Theme import cancelled.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Cleanup temp resources associated with preview.
	 *
	 * @param array $preview Preview payload.
	 * @return void
	 * @since 2.1.0
	 */
	private function cleanup_preview_resources( array $preview ): void {
		$temp    = isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '';
		$cleanup = ! empty( $preview['cleanup'] );
		$job     = null;

		if ( ! empty( $preview['chunk_job_id'] ) ) {
			$repo = new ChunkJobRepository();
			$job  = $repo->get( sanitize_text_field( (string) $preview['chunk_job_id'] ) );
		}

		if ( $cleanup && $temp && file_exists( $temp ) ) {
			FilesystemHelper::delete( $temp );
		}

		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}
}

