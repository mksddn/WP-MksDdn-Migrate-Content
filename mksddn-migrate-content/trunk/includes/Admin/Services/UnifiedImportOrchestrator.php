<?php
/**
 * @file: UnifiedImportOrchestrator.php
 * @description: Orchestrates unified import with automatic type detection and routing
 * @dependencies: SelectedContentImportService, FullSiteImportService, ImportTypeDetector, ServerBackupScanner, ChunkJobRepository, Themes\ThemePreviewStore, ResponseHandler, ImportPreflightService, PreflightReportStore
 * @created: 2026-01-25
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Contracts\ThemePreviewStoreInterface;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;
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
	 * Theme preview store.
	 *
	 * @var ThemePreviewStoreInterface
	 */
	private ThemePreviewStoreInterface $theme_preview_store;

	/**
	 * Response handler.
	 *
	 * @var ResponseHandler
	 */
	private ResponseHandler $response_handler;

	/**
	 * Preflight (dry-run) analyzer.
	 *
	 * @var ImportPreflightService
	 */
	private ImportPreflightService $preflight_service;

	/**
	 * Preflight report storage.
	 *
	 * @var PreflightReportStore
	 */
	private PreflightReportStore $preflight_report_store;

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
	 * @param ThemePreviewStoreInterface|null  $theme_preview_store      Theme preview store.
	 * @param ResponseHandler|null             $response_handler         Response handler.
	 * @param ImportPreflightService|null      $preflight_service        Preflight analyzer.
	 * @param PreflightReportStore|null        $preflight_report_store   Preflight report store.
	 * @since 2.0.0
	 */
	public function __construct(
		?SelectedContentImportService $selected_import_service = null,
		?FullSiteImportService $full_import_service = null,
		?ImportTypeDetector $type_detector = null,
		?ServerBackupScanner $server_scanner = null,
		?ThemePreviewStoreInterface $theme_preview_store = null,
		?ResponseHandler $response_handler = null,
		?ImportPreflightService $preflight_service = null,
		?PreflightReportStore $preflight_report_store = null
	) {
		$this->selected_import_service = $selected_import_service ?? new SelectedContentImportService();
		$this->full_import_service      = $full_import_service ?? new FullSiteImportService();
		$this->type_detector            = $type_detector ?? new ImportTypeDetector();
		$this->server_scanner           = $server_scanner ?? new ServerBackupScanner();
		$this->theme_preview_store      = $theme_preview_store ?? new ThemePreviewStore();
		$this->response_handler         = $response_handler ?? new ResponseHandler();
		$this->preflight_service        = $preflight_service ?? new ImportPreflightService();
		$this->preflight_report_store   = $preflight_report_store ?? new PreflightReportStore();
	}

	/**
	 * Process unified import request.
	 *
	 * @param array $request_data Request data (preflight_report_id, chunk_job_id, server_file, or uploaded file in $_FILES).
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
				|| wp_verify_nonce( $nonce, 'mksddn_mc_theme_import' )
				|| wp_verify_nonce( $nonce, 'import_single_page_nonce' );
		}

		if ( ! $nonce_verified ) {
			wp_die( esc_html__( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		$preflight_report_id = isset( $request_data['preflight_report_id'] )
			? sanitize_text_field( (string) $request_data['preflight_report_id'] )
			: '';

		if ( '' !== $preflight_report_id ) {
			$file_info = $this->resolve_preflight_import( $preflight_report_id );
		} else {
			$file_info = $this->resolve_file_source( $request_data );
		}

		if ( is_wp_error( $file_info ) ) {
			wp_die( esc_html( $file_info->get_error_message() ) );
		}

		// Detect import type.
		$import_type = $this->type_detector->detect( $file_info['path'], $file_info['extension'] );

		if ( is_wp_error( $import_type ) ) {
			wp_die( esc_html( $import_type->get_error_message() ) );
		}

		// First step (no preflight id): always run analysis and redirect to the report.
		if ( '' === $preflight_report_id ) {
			$this->run_preflight( $file_info, $import_type );
			return;
		}

		// Second step: run the real import using the same file reference from preflight.
		if ( 'full' === $import_type ) {
			$this->route_to_full_import( $file_info );
		} elseif ( 'themes' === $import_type ) {
			$this->route_to_theme_preview( $file_info );
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
	 * Resolve file from a completed preflight session (second import step).
	 *
	 * @param string $report_id Preflight report id.
	 * @return array|WP_Error File info or error.
	 */
	private function resolve_preflight_import( string $report_id ): array|WP_Error {
		$bucket = $this->preflight_report_store->get_bucket_for_user( $report_id, (int) get_current_user_id() );
		if ( ! $bucket || empty( $bucket['import_handle'] ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		$h  = $bucket['import_handle'];
		$st = isset( $h['source_type'] ) ? sanitize_key( (string) $h['source_type'] ) : '';

		if ( 'chunked' === $st ) {
			$jid = isset( $h['chunk_job_id'] ) ? sanitize_text_field( (string) $h['chunk_job_id'] ) : '';
			return '' !== $jid ? $this->resolve_chunked_file( $jid ) : new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		if ( 'server' === $st ) {
			$sf = isset( $h['server_file'] ) ? sanitize_text_field( (string) $h['server_file'] ) : '';
			return '' !== $sf ? $this->resolve_server_file( $sf ) : new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		if ( 'staged' === $st ) {
			$path = isset( $h['staged_path'] ) ? (string) $h['staged_path'] : '';
			if ( '' === $path || ! file_exists( $path ) ) {
				return new WP_Error(
					'mksddn_mc_staged_missing',
					__( 'Staged import file is missing. Run preflight again.', 'mksddn-migrate-content' )
				);
			}

			$ext = isset( $h['extension'] ) ? strtolower( (string) $h['extension'] ) : '';
			$name = isset( $h['original_name'] ) ? (string) $h['original_name'] : basename( $path );

			return array(
				'path'      => $path,
				'extension' => $ext,
				'name'      => $name,
				'source'    => 'staged',
			);
		}

		return new WP_Error(
			'mksddn_mc_preflight_invalid',
			__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
		);
	}

	/**
	 * Build persistent import handle after preflight (so the next step does not re-upload).
	 *
	 * @param array  $file_info Resolved file info from the first request.
	 * @param string $report_id Report id (directory name for staged browser uploads).
	 * @return array|WP_Error
	 */
	private function build_import_handle( array $file_info, string $report_id ): array|WP_Error {
		if ( 'chunked' === $file_info['source'] ) {
			return array(
				'source_type'   => 'chunked',
				'chunk_job_id'  => $file_info['chunk_job_id'],
				'extension'     => 'wpbkp',
				'original_name' => $file_info['name'],
			);
		}

		if ( 'server' === $file_info['source'] ) {
			return array(
				'source_type'   => 'server',
				'server_file'   => $file_info['server_file'],
				'extension'     => $file_info['extension'],
				'original_name' => $file_info['name'],
			);
		}

		if ( 'upload' === $file_info['source'] ) {
			$uploads = wp_upload_dir();
			if ( ! empty( $uploads['error'] ) ) {
				return new WP_Error(
					'mksddn_mc_upload_dir',
					__( 'Unable to prepare import storage. Check uploads directory permissions.', 'mksddn-migrate-content' )
				);
			}

			$safe_id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $report_id );
			if ( '' === $safe_id ) {
				return new WP_Error( 'mksddn_mc_preflight_invalid', __( 'Invalid preflight session.', 'mksddn-migrate-content' ) );
			}

			$dir = trailingslashit( $uploads['basedir'] ) . 'mksddn-mc/preflight/' . $safe_id;
			if ( ! wp_mkdir_p( $dir ) ) {
				return new WP_Error(
					'mksddn_mc_stage_mkdir',
					__( 'Could not create directory for the next import step.', 'mksddn-migrate-content' )
				);
			}

			$basename = sanitize_file_name( $file_info['name'] );
			$dest     = $dir . '/' . wp_unique_filename( $dir, $basename );

			if ( ! FilesystemHelper::copy( $file_info['path'], $dest, true ) ) {
				return new WP_Error(
					'mksddn_mc_stage_copy',
					__( 'Could not stash import file for the next step.', 'mksddn-migrate-content' )
				);
			}

			return array(
				'source_type'   => 'staged',
				'staged_path'   => $dest,
				'extension'     => $file_info['extension'],
				'original_name' => $file_info['name'],
			);
		}

		return new WP_Error( 'mksddn_mc_unknown_source', __( 'Invalid file source.', 'mksddn-migrate-content' ) );
	}

	/**
	 * MIME type hint for synthetic $_FILES entries.
	 *
	 * @param string $extension File extension (wpbkp or json).
	 * @return string
	 */
	private function mime_for_import_file( string $extension ): string {
		return 'json' === strtolower( $extension ) ? 'application/json' : 'application/zip';
	}

	/**
	 * Prepare file for import service.
	 *
	 * @param array  $file_info File information.
	 * @param string $file_key  Key for $_FILES array.
	 * @param string $nonce_action Nonce action name.
	 * @return void
	 * @since 2.1.0
	 */
	private function prepare_file_for_import( array $file_info, string $file_key, string $nonce_action ): void {
		if ( 'chunked' === $file_info['source'] ) {
			$_POST['chunk_job_id'] = $file_info['chunk_job_id'];
			$_REQUEST['_wpnonce']  = wp_create_nonce( $nonce_action );
		} elseif ( 'server' === $file_info['source'] ) {
			$_POST['server_file'] = $file_info['server_file'];
			$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
		} elseif ( 'staged' === $file_info['source'] ) {
			$_POST['preflight_staged_path'] = $file_info['path'];
			$_POST['preflight_staged_name'] = $file_info['name'];
			$_POST['preflight_staged_ext']  = $file_info['extension'];
			$_REQUEST['_wpnonce']           = wp_create_nonce( $nonce_action );
		} else {
			$temp = wp_tempnam( 'mksddn-unified-import-' );
			if ( ! $temp ) {
				wp_die( esc_html__( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
			}

			if ( ! FilesystemHelper::move( $file_info['path'], $temp, true ) ) {
				wp_die( esc_html__( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
			}

			$_FILES[ $file_key ] = array(
				'name'     => $file_info['name'],
				'tmp_name' => $temp,
				'size'     => filesize( $temp ),
				'error'    => UPLOAD_ERR_OK,
				'type'     => $this->mime_for_import_file( $file_info['extension'] ?? '' ),
			);
			$_REQUEST['_wpnonce'] = wp_create_nonce( $nonce_action );
		}
	}

	/**
	 * Route to full site import service.
	 *
	 * @param array $file_info File information.
	 * @return void
	 * @since 2.0.0
	 */
	private function route_to_full_import( array $file_info ): void {
		$this->prepare_file_for_import( $file_info, 'full_import_file', 'mksddn_mc_full_import' );
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
		} elseif ( 'staged' === $file_info['source'] ) {
			$_POST['preflight_staged_path'] = $file_info['path'];
			$_POST['preflight_staged_name'] = $file_info['name'];
			$_POST['preflight_staged_ext']  = $file_info['extension'];
			$_REQUEST['_wpnonce']           = wp_create_nonce( 'import_single_page_nonce' );
		} else {
			// For direct HTTP uploads, file is already in $_FILES['import_file'].
			$_REQUEST['_wpnonce'] = wp_create_nonce( 'import_single_page_nonce' );
		}

		$this->selected_import_service->import();
	}

	/**
	 * Route to theme preview step before import.
	 *
	 * @param array $file_info File information.
	 * @return void
	 * @since 2.1.0
	 */
	private function route_to_theme_preview( array $file_info ): void {
		$preview_payload = $this->prepare_file_for_preview( $file_info );

		if ( is_wp_error( $preview_payload ) ) {
			wp_die( esc_html( $preview_payload->get_error_message() ) );
		}

		$preview_id = $this->theme_preview_store->create( $preview_payload );
		$this->response_handler->redirect_to_theme_preview( $preview_id );
	}

	/**
	 * Prepare file info for theme preview step.
	 *
	 * @param array $file_info File information.
	 * @return array|WP_Error
	 * @since 2.1.0
	 */
	private function prepare_file_for_preview( array $file_info ): array|WP_Error {
		$result = array(
			'file_path'     => '',
			'cleanup'       => false,
			'chunk_job_id'  => '',
			'original_name' => $file_info['name'] ?? '',
		);

		if ( 'chunked' === $file_info['source'] ) {
			$result['file_path']    = $file_info['path'];
			$result['chunk_job_id'] = $file_info['chunk_job_id'] ?? '';
			return $result;
		}

		if ( 'server' === $file_info['source'] ) {
			$result['file_path'] = $file_info['path'];
			return $result;
		}

		if ( 'staged' === $file_info['source'] ) {
			$result['file_path'] = $file_info['path'];
			return $result;
		}

		$temp = wp_tempnam( 'mksddn-theme-preview-' );
		if ( ! $temp ) {
			return new WP_Error( 'mksddn_mc_temp_unavailable', __( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
		}

		if ( ! FilesystemHelper::move( $file_info['path'], $temp, true ) ) {
			return new WP_Error( 'mksddn_mc_move_failed', __( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
		}

		$result['file_path'] = $temp;
		$result['cleanup']   = true;

		return $result;
	}

	/**
	 * Run preflight analysis, persist import handle for step two, redirect with report id.
	 *
	 * @param array  $file_info   Resolved file info.
	 * @param string $import_type Detected import type.
	 * @return void
	 */
	private function run_preflight( array $file_info, string $import_type ): void {
		$report    = $this->preflight_service->analyze( $file_info, $import_type );
		$report_id = wp_generate_password( 24, false, false );
		$handle    = $this->build_import_handle( $file_info, $report_id );

		if ( is_wp_error( $handle ) ) {
			wp_die( esc_html( $handle->get_error_message() ) );
		}

		$this->preflight_report_store->save( (int) get_current_user_id(), $report, $handle, $report_id );
		$this->response_handler->redirect_to_preflight_report( $report_id );
	}
}
