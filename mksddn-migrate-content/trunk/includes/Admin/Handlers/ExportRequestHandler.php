<?php
/**
 * @file: ExportRequestHandler.php
 * @description: Handler for export request operations
 * @dependencies: ExportHandler (service), SelectionBuilder, FullContentExporter, FilenameBuilder, NotificationService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Contracts\ExportRequestHandlerInterface;
use MksDdn\MigrateContent\Export\ExportHandler as ExportService;
use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Selection\SelectionBuilder;
use MksDdn\MigrateContent\Support\FilenameBuilder;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for export request operations.
 *
 * @since 1.0.0
 */
class ExportRequestHandler implements ExportRequestHandlerInterface {

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param NotificationService|null $notifications Notification service.
	 * @since 1.0.0
	 */
	public function __construct( ?NotificationService $notifications = null ) {
		$this->notifications = $notifications ?? new NotificationService();
	}

	/**
	 * Handle selected content export.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		if ( ! check_admin_referer( 'mksddn_mc_selected_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mksddn-migrate-content' ) );
		}

		$builder    = new SelectionBuilder();
		$selection  = $builder->from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified above.
		$format     = sanitize_key( $_POST['export_format'] ?? 'archive' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$with_media = ( 'archive' === $format );

		$export_handler = new ExportService();
		$export_handler->set_collect_media( $with_media );
		$export_handler->export_selected_content( $selection, $format );
	}

	/**
	 * Handle full site export.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_full_export' );

		$temp = \wp_tempnam( 'mksddn-full-' );
		if ( ! $temp ) {
			wp_die( esc_html__( 'Unable to create temporary file.', 'mksddn-migrate-content' ) );
		}

		$exporter = new FullContentExporter();
		$result   = $exporter->export_to( $temp );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$filename = FilenameBuilder::build( 'full-site', 'wpbkp' );
		$this->stream_file_download( $temp, $filename );
	}

	/**
	 * Stream file download.
	 *
	 * @param string $path     File path.
	 * @param string $filename Download filename.
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

