<?php
/**
 * @file: ImportRequestHandler.php
 * @description: Handler for import request operations
 * @dependencies: Admin\Services\SelectedContentImportService, Admin\Services\FullSiteImportService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Support\MimeTypeHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handler for import request operations.
 *
 * @since 1.0.0
 */
class ImportRequestHandler implements ImportRequestHandlerInterface {

	/**
	 * Selected content import service.
	 *
	 * @var SelectedContentImportService
	 */
	private SelectedContentImportService $selected_import_service;

	/**
	 * Full site import service.
	 *
	 * @var FullSiteImportService
	 */
	private FullSiteImportService $full_import_service;

	/**
	 * Import type detector.
	 *
	 * @var ImportTypeDetector
	 */
	private ImportTypeDetector $type_detector;

	/**
	 * Constructor.
	 *
	 * @param SelectedContentImportService|null $selected_import_service Selected content import service.
	 * @param FullSiteImportService|null        $full_import_service     Full site import service.
	 * @param ImportTypeDetector|null          $type_detector          Import type detector.
	 * @since 1.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null,
		?ImportTypeDetector $type_detector = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
		$this->type_detector            = $type_detector ?? new ImportTypeDetector();
	}

	/**
	 * Handle selected content import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_import(): void {
		$this->selected_import_service->import();
	}

	/**
	 * Handle full site import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_import(): void {
		$this->full_import_service->import();
	}

	/**
	 * Handle unified import with automatic type detection.
	 *
	 * @return void
	 * @since 1.4.0
	 */
	public function handle_unified_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_unified_import' );

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method ) {
			return;
		}

		// Check for chunk job ID (from chunked upload).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';

		// Check if server file is provided.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$server_file = isset( $_POST['server_file'] ) ? sanitize_text_field( wp_unslash( $_POST['server_file'] ) ) : '';

		$file_path   = '';
		$extension   = '';
		$is_uploaded = false;
		$file_name   = '';

		// Handle chunked upload.
		if ( $chunk_job_id ) {
			// For chunked uploads, we need to detect type from the chunk job file.
			$repo = new \MksDdn\MigrateContent\Chunking\ChunkJobRepository();
			$job  = $repo->get( $chunk_job_id );
			if ( ! $job ) {
				wp_die( esc_html__( 'Chunked upload job not found.', 'mksddn-migrate-content' ) );
			}

			$file_path = $job->get_file_path();
			if ( ! file_exists( $file_path ) ) {
				wp_die( esc_html__( 'Chunked upload file not found.', 'mksddn-migrate-content' ) );
			}

			$extension = 'wpbkp'; // Chunked uploads are always .wpbkp files.
			$file_name = sprintf( 'chunk:%s', $chunk_job_id );

			// Detect import type and route accordingly.
			$import_type = $this->type_detector->detect( $file_path, $extension );
			if ( is_wp_error( $import_type ) ) {
				wp_die( esc_html( $import_type->get_error_message() ) );
			}

			if ( 'full' === $import_type ) {
				// Prepare for full import.
				$_POST['chunk_job_id'] = $chunk_job_id;
				$_REQUEST['_wpnonce'] = wp_create_nonce( 'mksddn_mc_full_import' );
				$this->full_import_service->import();
			} else {
				// Selected content import - use chunked file path directly.
				// We'll pass it as a special server file path to bypass upload validation.
				// Store chunk job ID in a way that SelectedContentImportService can use it.
				$_POST['chunk_job_id'] = $chunk_job_id;
				$_POST['chunk_file_path'] = $file_path;
				$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
				$this->selected_import_service->import();
			}
			return;
		}

		if ( $server_file ) {
			// Server file selected.
			$scanner = new \MksDdn\MigrateContent\Admin\Services\ServerBackupScanner();
			$file_info = $scanner->get_file( $server_file );

			if ( is_wp_error( $file_info ) ) {
				wp_die( esc_html( $file_info->get_error_message() ) );
			}

			$file_path = $file_info['path'];
			$extension = $file_info['extension'];
			$file_name = $file_info['name'];
		} else {
			// File uploaded.
			if ( ! isset( $_FILES['import_file'], $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
				wp_die( esc_html__( 'Failed to upload file.', 'mksddn-migrate-content' ) );
			}

			// Verify that the file was actually uploaded via HTTP POST.
			$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
			if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
				wp_die( esc_html__( 'File upload security check failed.', 'mksddn-migrate-content' ) );
			}

			$name = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['import_file']['name'] ) ) : '';
			$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

			if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
				wp_die( esc_html__( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
			}

			$file_path = $tmp_name;
			$file_name = $name;
			$is_uploaded = true;
		}

		// Detect import type.
		$import_type = $this->type_detector->detect( $file_path, $extension );

		if ( is_wp_error( $import_type ) ) {
			wp_die( esc_html( $import_type->get_error_message() ) );
		}

		// Route to appropriate handler.
		if ( 'full' === $import_type ) {
			// For full import, prepare file data and call full import service.
			if ( $is_uploaded ) {
				// Move uploaded file to temp location for full import service.
				$temp = wp_tempnam( 'mksddn-unified-import-' );
				if ( ! $temp ) {
					wp_die( esc_html__( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
				}

				if ( ! \MksDdn\MigrateContent\Support\FilesystemHelper::move( $file_path, $temp, true ) ) {
					wp_die( esc_html__( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
				}

				// Prepare $_FILES array for full import service.
				$_FILES['full_import_file'] = array(
					'name'     => $file_name,
					'tmp_name' => $temp,
					'size'     => filesize( $temp ),
					'error'    => UPLOAD_ERR_OK,
					'type'     => 'application/zip',
				);
			} else {
				// Server file - set it in POST.
				$_POST['server_file'] = $server_file;
			}

			// Store original nonce and set new one for full import.
			$original_nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'mksddn_mc_full_import' );
			$this->full_import_service->import();
		} else {
			// Selected content import.
			if ( $is_uploaded ) {
				// File already in $_FILES['import_file'], just need to set nonce.
				$original_nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
				$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
			} else {
				// Server file - set it in POST.
				$_POST['server_file'] = $server_file;
				$original_nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
				$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
			}

			$this->selected_import_service->import();
		}
	}

}

