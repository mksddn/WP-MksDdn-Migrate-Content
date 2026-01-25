<?php
/**
 * @file: UnifiedImportOrchestrator.php
 * @description: Orchestrates unified import with automatic type detection and routing
 * @dependencies: SelectedContentImportService, FullSiteImportService, ImportTypeDetector, ServerBackupScanner, ChunkJobRepository
 * @created: 2026-01-25
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates unified import with automatic type detection and routing.
 *
 * @since 2.0.0
 */
class UnifiedImportOrchestrator {

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
	 * Server backup scanner.
	 *
	 * @var ServerBackupScanner
	 */
	private ServerBackupScanner $server_scanner;

	/**
	 * Constructor.
	 *
	 * @param SelectedContentImportService|null $selected_import_service Selected content import service.
	 * @param FullSiteImportService|null        $full_import_service     Full site import service.
	 * @param ImportTypeDetector|null           $type_detector            Import type detector.
	 * @param ServerBackupScanner|null         $server_scanner           Server backup scanner.
	 * @since 2.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null,
		?ImportTypeDetector $type_detector = null,
		?ServerBackupScanner $server_scanner = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
		$this->type_detector            = $type_detector ?? new ImportTypeDetector();
		$this->server_scanner           = $server_scanner ?? new ServerBackupScanner();
	}

	/**
	 * Process unified import request.
	 *
	 * @param array $request_data Request data (chunk_job_id, server_file, or uploaded file in $_FILES).
	 * @return void
	 * @since 2.0.0
	 */
	public function process( array $request_data ): void {
		// Verify nonce for form data processing.
		// Check for unified import nonce first, then fallback to other nonces.
		$nonce_verified = false;
		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
			$nonce_verified = wp_verify_nonce( $nonce, 'mksddn_mc_unified_import' )
				|| wp_verify_nonce( $nonce, 'mksddn_mc_full_import' )
				|| wp_verify_nonce( $nonce, 'import_single_page_nonce' );
		}

		if ( ! $nonce_verified ) {
			wp_die( esc_html__( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Resolve file source.
		$file_info = $this->resolve_file_source( $request_data );

		if ( is_wp_error( $file_info ) ) {
			wp_die( esc_html( $file_info->get_error_message() ) );
		}

		// Detect import type.
		$import_type = $this->type_detector->detect( $file_info['path'], $file_info['extension'] );

		if ( is_wp_error( $import_type ) ) {
			wp_die( esc_html( $import_type->get_error_message() ) );
		}

		// Route to appropriate service.
		if ( 'full' === $import_type ) {
			$this->route_to_full_import( $file_info );
		} else {
			$this->route_to_selected_import( $file_info );
		}
	}

	/**
	 * Resolve file source from request data.
	 *
	 * @param array $request_data Request data.
	 * @return array|WP_Error File info or error.
	 * @since 2.0.0
	 */
	private function resolve_file_source( array $request_data ): array|WP_Error {
		// Check for chunked upload.
		if ( ! empty( $request_data['chunk_job_id'] ) ) {
			return $this->resolve_chunked_file( $request_data['chunk_job_id'] );
		}

		// Check for server file.
		if ( ! empty( $request_data['server_file'] ) ) {
			return $this->resolve_server_file( $request_data['server_file'] );
		}

		// Check for uploaded file.
		return $this->resolve_uploaded_file();
	}

	/**
	 * Resolve chunked file.
	 *
	 * @param string $chunk_job_id Chunk job ID.
	 * @return array|WP_Error File info or error.
	 * @since 2.0.0
	 */
	private function resolve_chunked_file( string $chunk_job_id ): array|WP_Error {
		$repo = new ChunkJobRepository();
		$job  = $repo->get( $chunk_job_id );

		if ( ! $job ) {
			return new WP_Error( 'mksddn_mc_chunk_not_found', __( 'Chunked upload job not found.', 'mksddn-migrate-content' ) );
		}

		$file_path = $job->get_file_path();
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'mksddn_mc_chunk_file_missing', __( 'Chunked upload file not found.', 'mksddn-migrate-content' ) );
		}

		return array(
			'path'      => $file_path,
			'extension' => 'wpbkp',
			'name'      => sprintf( 'chunk:%s', $chunk_job_id ),
			'source'    => 'chunked',
			'chunk_job_id' => $chunk_job_id,
		);
	}

	/**
	 * Resolve server file.
	 *
	 * @param string $server_file Server file identifier.
	 * @return array|WP_Error File info or error.
	 * @since 2.0.0
	 */
	private function resolve_server_file( string $server_file ): array|WP_Error {
		$file_info = $this->server_scanner->get_file( $server_file );

		if ( is_wp_error( $file_info ) ) {
			return $file_info;
		}

		return array(
			'path'      => $file_info['path'],
			'extension' => $file_info['extension'],
			'name'      => $file_info['name'],
			'source'    => 'server',
			'server_file' => $server_file,
		);
	}

	/**
	 * Resolve uploaded file.
	 *
	 * @return array|WP_Error File info or error.
	 * @since 2.0.0
	 */
	private function resolve_uploaded_file(): array|WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in process() method.
		if ( ! isset( $_FILES['import_file'], $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
			return new WP_Error( 'mksddn_mc_upload_failed', __( 'Failed to upload file.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in process() method.
		$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
		if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'mksddn_mc_upload_security', __( 'File upload security check failed.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in process() method.
		$name      = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( wp_unslash( (string) $_FILES['import_file']['name'] ) ) : '';
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
			return new WP_Error( 'mksddn_mc_invalid_extension', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
		}

		return array(
			'path'      => $tmp_name,
			'extension' => $extension,
			'name'      => $name,
			'source'    => 'upload',
		);
	}

	/**
	 * Route to full site import service.
	 *
	 * @param array $file_info File information.
	 * @return void
	 * @since 2.0.0
	 */
	private function route_to_full_import( array $file_info ): void {
		if ( 'chunked' === $file_info['source'] ) {
			// For chunked uploads, set chunk_job_id in POST.
			$_POST['chunk_job_id'] = $file_info['chunk_job_id'];
			$_REQUEST['_wpnonce']  = wp_create_nonce( 'mksddn_mc_full_import' );
		} elseif ( 'server' === $file_info['source'] ) {
			// For server files, set server_file in POST.
			$_POST['server_file']  = $file_info['server_file'];
			$_REQUEST['_wpnonce']  = wp_create_nonce( 'mksddn_mc_full_import' );
		} else {
			// For uploaded files, move to temp location and set in $_FILES.
			$temp = wp_tempnam( 'mksddn-unified-import-' );
			if ( ! $temp ) {
				wp_die( esc_html__( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
			}

			if ( ! FilesystemHelper::move( $file_info['path'], $temp, true ) ) {
				wp_die( esc_html__( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
			}

			$_FILES['full_import_file'] = array(
				'name'     => $file_info['name'],
				'tmp_name' => $temp,
				'size'     => filesize( $temp ),
				'error'    => UPLOAD_ERR_OK,
				'type'     => 'application/zip',
			);
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'mksddn_mc_full_import' );
		}

		$this->full_import_service->import();
	}

	/**
	 * Route to selected content import service.
	 *
	 * @param array $file_info File information.
	 * @return void
	 * @since 2.0.0
	 */
	private function route_to_selected_import( array $file_info ): void {
		if ( 'chunked' === $file_info['source'] ) {
			// For chunked uploads, set chunk data in POST.
			$_POST['chunk_job_id']   = $file_info['chunk_job_id'];
			$_POST['chunk_file_path'] = $file_info['path'];
			$_REQUEST['_wpnonce']     = wp_create_nonce( 'import_single_page_nonce' );
		} elseif ( 'server' === $file_info['source'] ) {
			// For server files, set server_file in POST.
			$_POST['server_file'] = $file_info['server_file'];
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
		} else {
			// For uploaded files, file is already in $_FILES['import_file'], just set nonce.
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
		}

		$this->selected_import_service->import();
	}
}
