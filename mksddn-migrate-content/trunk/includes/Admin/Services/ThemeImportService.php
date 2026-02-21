<?php
/**
 * @file: ThemeImportService.php
 * @description: Service for importing theme archives
 * @dependencies: Filesystem\ThemeImporter, Admin\Services\ServerBackupScanner, Admin\Services\ResponseHandler, Support\FilesystemHelper, Chunking\ChunkJobRepository, Config\PluginConfig, Themes\ThemePreviewStore
 * @created: 2026-02-19
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\ThemeImporter;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\ImportLock;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for importing theme archives.
 *
 * @since 2.1.0
 */
class ThemeImportService {

	/**
	 * Response handler.
	 *
	 * @var ResponseHandler
	 */
	private ResponseHandler $response_handler;

	/**
	 * Server backup scanner.
	 *
	 * @var ServerBackupScanner
	 */
	private ServerBackupScanner $server_scanner;

	/**
	 * Theme preview store.
	 *
	 * @var ThemePreviewStore
	 */
	private ThemePreviewStore $preview_store;

	/**
	 * Constructor.
	 *
	 * @param ResponseHandler|null     $response_handler Response handler.
	 * @param ServerBackupScanner|null $server_scanner   Server backup scanner.
	 * @param ThemePreviewStore|null   $preview_store    Theme preview store.
	 */
	public function __construct(
		?ResponseHandler $response_handler = null,
		?ServerBackupScanner $server_scanner = null,
		?ThemePreviewStore $preview_store = null
	) {
		$this->response_handler = $response_handler ?? new ResponseHandler();
		$this->server_scanner   = $server_scanner ?? new ServerBackupScanner();
		$this->preview_store    = $preview_store ?? new ThemePreviewStore();
	}

	/**
	 * Log debug message if WP_DEBUG is enabled.
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	private function log_debug( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging only when WP_DEBUG is enabled
			error_log( sprintf( '[MksDdn MC] %s', $message ) );
		}
	}

	/**
	 * Handle theme import request.
	 *
	 * @return void
	 */
	public function import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( $preview_id ) {
			check_admin_referer( 'mksddn_mc_theme_preview_' . $preview_id );
			$this->finalize_from_preview( $preview_id );
			return;
		}

		// Accept both unified_import and theme_import nonces for compatibility.
		$nonce_verified = check_admin_referer( 'mksddn_mc_unified_import', '_wpnonce', false )
			|| check_admin_referer( 'mksddn_mc_theme_import', '_wpnonce', false );

		if ( ! $nonce_verified ) {
			wp_die( esc_html__( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$upload       = $this->resolve_upload( $chunk_job_id );

		if ( is_wp_error( $upload ) ) {
			$this->response_handler->redirect_with_theme_status( 'error', $upload->get_error_message() );
			return;
		}

		$import_mode = $this->sanitize_import_mode( $_POST['import_mode'] ?? 'replace' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$this->execute_import( $upload, $import_mode );
	}

	/**
	 * Finalize theme import from stored preview.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 2.1.0
	 */
	private function finalize_from_preview( string $preview_id ): void {
		$preview = $this->preview_store->get( $preview_id );
		if ( ! $preview ) {
			$this->response_handler->redirect_with_theme_status( 'error', __( 'Theme import session expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->response_handler->redirect_with_theme_status( 'error', __( 'You are not allowed to continue this theme import.', 'mksddn-migrate-content' ) );
			return;
		}

		$import_mode = $this->sanitize_import_mode( $_POST['import_mode'] ?? 'replace' );
		$upload      = array(
			'temp'          => isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '',
			'cleanup'       => ! empty( $preview['cleanup'] ),
			'chunk_job_id'  => isset( $preview['chunk_job_id'] ) ? sanitize_text_field( (string) $preview['chunk_job_id'] ) : '',
			'original_name' => $preview['original_name'] ?? '',
			'job'           => null,
		);

		if ( $upload['chunk_job_id'] ) {
			$repo          = new ChunkJobRepository();
			$upload['job'] = $repo->get( $upload['chunk_job_id'] );
		}

		if ( empty( $upload['temp'] ) || ! file_exists( $upload['temp'] ) ) {
			$this->preview_store->delete( $preview_id );
			$this->response_handler->redirect_with_theme_status( 'error', __( 'Import file is missing. Restart the upload.', 'mksddn-migrate-content' ) );
			return;
		}

		$this->preview_store->delete( $preview_id );
		$this->execute_import( $upload, $import_mode );
	}

	/**
	 * Execute theme import.
	 *
	 * @param array  $upload      Upload data.
	 * @param string $import_mode Import mode.
	 * @return void
	 * @since 2.1.0
	 */
	private function execute_import( array $upload, string $import_mode ): void {
		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Continue execution even if client disconnects.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$lock       = new ImportLock();
		$lock_token = null;
		$status     = 'success';
		$message    = null;

		try {
			$lock_token = $lock->acquire();
			if ( ! $lock_token ) {
				$this->response_handler->redirect_with_theme_status( 'error', __( 'Another import is already running. Please wait for it to finish.', 'mksddn-migrate-content' ) );
				return;
			}

			// Register shutdown function to ensure lock is released even on fatal errors.
			register_shutdown_function(
				function() use ( $lock, &$lock_token ) {
					if ( $lock_token ) {
						$lock->release( $lock_token );
					}
				}
			);

			$importer = new ThemeImporter( $import_mode );
			$result   = $importer->import_themes( $upload['temp'] );

			if ( is_wp_error( $result ) ) {
				$status  = 'error';
				$message = $result->get_error_message();
				$this->log_debug( sprintf( 'Theme import failed: %s', $message ) );
			} else {
				$this->log_debug( sprintf( 'Theme import completed successfully (mode: %s)', $import_mode ) );
			}
		} finally {
			$this->cleanup( $upload['temp'], $upload['cleanup'], $upload['job'] );
			if ( $lock_token ) {
				$lock->release( $lock_token );
			}
		}

		if ( 'error' === $status ) {
			$this->response_handler->redirect_with_theme_status( 'error', $message );
			return;
		}

		$this->response_handler->redirect_with_theme_status( 'success' );
	}

	/**
	 * Sanitize import mode value.
	 *
	 * @param string $import_mode Import mode.
	 * @return string
	 * @since 2.1.0
	 */
	private function sanitize_import_mode( string $import_mode ): string {
		$import_mode = sanitize_key( $import_mode );
		return in_array( $import_mode, array( 'merge', 'replace' ), true ) ? $import_mode : 'replace';
	}

	/**
	 * Resolve uploaded file or chunk job.
	 *
	 * @param string $chunk_job_id Chunk job identifier.
	 * @return array|WP_Error
	 */
	public function resolve_upload( string $chunk_job_id ): array|WP_Error {
		$chunk_disabled = PluginConfig::is_chunked_disabled();
		$result         = array(
			'temp'          => '',
			'cleanup'       => false,
			'job'           => null,
			'chunk_job_id'  => $chunk_job_id,
			'original_name' => '',
		);

		if ( $chunk_job_id ) {
			if ( $chunk_disabled ) {
				return new WP_Error( 'mksddn_mc_chunk_disabled', __( 'Chunked uploads are disabled on this site.', 'mksddn-migrate-content' ) );
			}

			$repo = new ChunkJobRepository();
			$job  = $repo->get( $chunk_job_id );
			$path = $job->get_file_path();

			if ( ! file_exists( $path ) ) {
				return new WP_Error( 'mksddn_mc_chunk_missing', __( 'Chunked upload is incomplete. Please retry.', 'mksddn-migrate-content' ) );
			}

			$result['temp']          = $path;
			$result['job']           = $job;
			$result['original_name'] = sprintf( 'chunk:%s', $chunk_job_id );

			return $result;
		}

		// Check if server file is provided.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in import().
		$server_file = isset( $_POST['server_file'] ) ? sanitize_text_field( wp_unslash( $_POST['server_file'] ) ) : '';

		if ( $server_file ) {
			$file_info = $this->server_scanner->get_file( $server_file );

			if ( is_wp_error( $file_info ) ) {
				return $file_info;
			}

			if ( 'wpbkp' !== $file_info['extension'] ) {
				return new WP_Error( 'mksddn_mc_invalid_type', __( 'Please select a .wpbkp archive generated by this plugin.', 'mksddn-migrate-content' ) );
			}

			$result['temp']          = $file_info['path'];
			$result['cleanup']       = false;
			$result['original_name'] = $file_info['name'];

			return $result;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in import().
		if ( ! isset( $_FILES['theme_import_file'], $_FILES['theme_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'No file uploaded.', 'mksddn-migrate-content' ) );
		}

		$file     = $_FILES['theme_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized below, nonce verified upstream
		// tmp_name is a server path, not user input - use direct cast, will be validated with is_uploaded_file().
		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$name     = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( (string) $file['name'] ) ) : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 ) {
			return new WP_Error( 'mksddn_mc_invalid_size', __( 'Invalid file size.', 'mksddn-migrate-content' ) );
		}

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'wpbkp' !== $ext ) {
			return new WP_Error( 'mksddn_mc_invalid_type', __( 'Please upload a .wpbkp archive generated by this plugin.', 'mksddn-migrate-content' ) );
		}

		$temp = wp_tempnam( 'mksddn-theme-import-' );
		if ( ! $temp ) {
			return new WP_Error( 'mksddn_mc_temp_unavailable', __( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
		}

		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'mksddn_mc_move_failed', __( 'Uploaded file could not be verified.', 'mksddn-migrate-content' ) );
		}

		if ( ! FilesystemHelper::move( $tmp_name, $temp, true ) ) {
			return new WP_Error( 'mksddn_mc_move_failed', __( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
		}

		$result['temp']          = $temp;
		$result['cleanup']       = true;
		$result['original_name'] = $name;

		return $result;
	}

	/**
	 * Cleanup temp files and chunk jobs.
	 *
	 * @param string     $temp    Temp file path.
	 * @param bool       $cleanup Whether temp should be removed.
	 * @param object|null $job    Chunk job instance.
	 * @return void
	 */
	private function cleanup( string $temp, bool $cleanup, $job ): void {
		if ( $cleanup && $temp && file_exists( $temp ) ) {
			FilesystemHelper::delete( $temp );
		}

		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}
}
