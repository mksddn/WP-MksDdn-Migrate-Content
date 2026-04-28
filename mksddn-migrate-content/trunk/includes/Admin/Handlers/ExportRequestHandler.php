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
use MksDdn\MigrateContent\Filesystem\ThemeExporter;
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

		// Extract only necessary fields from $_POST.
		$allowed_fields = $this->extract_selection_fields( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified above.
		
		$builder    = new SelectionBuilder();
		$selection  = $builder->from_request( $allowed_fields );
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
			$this->wp_die_export_error( $result );
		}

		$filename = FilenameBuilder::build( 'full-site', 'wpbkp' );
		$this->stream_file_download( $temp, $filename );
	}

	/**
	 * Handle theme export.
	 *
	 * @return void
	 * @since 2.1.0
	 */
	public function handle_theme_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_theme_export' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$selected_themes = isset( $_POST['selected_themes'] ) && is_array( $_POST['selected_themes'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['selected_themes'] ) )
			: array();

		if ( empty( $selected_themes ) ) {
			wp_die( esc_html__( 'No themes selected for export.', 'mksddn-migrate-content' ) );
		}

		$temp = \wp_tempnam( 'mksddn-themes-' );
		if ( ! $temp ) {
			wp_die( esc_html__( 'Unable to create temporary file.', 'mksddn-migrate-content' ) );
		}

		$exporter = new ThemeExporter();
		$result   = $exporter->export_themes( $selected_themes, $temp );

		if ( is_wp_error( $result ) ) {
			$this->wp_die_export_error( $result );
		}

		$filename = FilenameBuilder::build( 'themes', 'wpbkp' );
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

		if ( ! is_readable( $path ) ) {
			if ( $delete_after && file_exists( $path ) ) {
				FilesystemHelper::delete( $path );
			}
			wp_die( esc_html__( 'Export file exists but is not readable.', 'mksddn-migrate-content' ) );
		}

		$filesize = filesize( $path );
		if ( false === $filesize || $filesize <= 0 ) {
			if ( $delete_after && file_exists( $path ) ) {
				FilesystemHelper::delete( $path );
			}
			wp_die(
				esc_html__(
					'Export file is empty or could not be measured. Check free disk space, hosting quota, and PHP temp directory.',
					'mksddn-migrate-content'
				)
			);
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

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

	/**
	 * Exit with a full error message and optional data hint.
	 *
	 * @param WP_Error $error Export error.
	 */
	private function wp_die_export_error( WP_Error $error ): void {
		$message = $error->get_error_message();
		$data    = $error->get_error_data();
		if ( is_array( $data ) && isset( $data['hint'] ) && is_string( $data['hint'] ) && '' !== $data['hint'] ) {
			$message .= ' ' . $data['hint'];
		}

		wp_die( esc_html( $message ) );
	}

	/**
	 * Extract only necessary fields for selection from POST data.
	 *
	 * @param array $post_data POST data.
	 * @return array Filtered and sanitized array with only selection-related fields.
	 * @since 1.0.0
	 */
	private function extract_selection_fields( array $post_data ): array {
		$allowed = array();

		// Extract and sanitize fields matching pattern selected_*_ids.
		foreach ( $post_data as $key => $value ) {
			if ( preg_match( '/^selected_(.+)_ids/', sanitize_key( $key ) ) ) {
				$sanitized_key = sanitize_key( $key );
				
				$ids = array();
				
				// Handle array (from select or hidden input with []).
				if ( is_array( $value ) ) {
					foreach ( $value as $item ) {
						if ( is_numeric( $item ) ) {
							$ids[] = absint( $item );
						} elseif ( is_string( $item ) && ! empty( trim( $item ) ) ) {
							// Split comma-separated string and convert to integers.
							$split_ids = array_filter( array_map( 'absint', explode( ',', $item ) ) );
							$ids = array_merge( $ids, $split_ids );
						}
					}
				} elseif ( is_string( $value ) && ! empty( trim( $value ) ) ) {
					// Handle comma-separated string from hidden input.
					$ids = array_filter( array_map( 'absint', explode( ',', $value ) ) );
				}
				
				// Merge with existing IDs if field already exists.
				if ( isset( $allowed[ $sanitized_key ] ) ) {
					$allowed[ $sanitized_key ] = array_unique( array_merge( $allowed[ $sanitized_key ], $ids ) );
				} else {
					$allowed[ $sanitized_key ] = array_unique( $ids );
				}
			}
		}

		// Extract and sanitize options_keys if present.
		if ( isset( $post_data['options_keys'] ) ) {
			$allowed['options_keys'] = is_array( $post_data['options_keys'] )
				? array_map( 'sanitize_text_field', $post_data['options_keys'] )
				: array();
		}

		// Extract and sanitize widget_groups if present.
		if ( isset( $post_data['widget_groups'] ) ) {
			$allowed['widget_groups'] = is_array( $post_data['widget_groups'] )
				? array_map( 'sanitize_text_field', $post_data['widget_groups'] )
				: array();
		}

		return $allowed;
	}
}

