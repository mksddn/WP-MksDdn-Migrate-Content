<?php
/**
 * @file: ThemeImportService.php
 * @description: Service for importing theme archives
 * @dependencies: Filesystem\ThemeImporter, Admin\Services\ServerBackupScanner, Admin\Services\ResponseHandler, Support\FilesystemHelper, Chunking\ChunkJobRepository, Config\PluginConfig
 * @created: 2026-02-19
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\ThemeImporter;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\ImportLock;
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
	 * Constructor.
	 *
	 * @param ResponseHandler|null    $response_handler Response handler.
	 * @param ServerBackupScanner|null $server_scanner   Server backup scanner.
	 */
	public function __construct(
		?ResponseHandler $response_handler = null,
		?ServerBackupScanner $server_scanner = null
	) {
		$this->response_handler = $response_handler ?? new ResponseHandler();
		$this->server_scanner   = $server_scanner ?? new ServerBackupScanner();
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

		// Accept both unified_import and theme_import nonces for compatibility.
		$nonce_verified = check_admin_referer( 'mksddn_mc_unified_import', '_wpnonce', false )
			|| check_admin_referer( 'mksddn_mc_theme_import', '_wpnonce', false );
		
		if ( ! $nonce_verified ) {
			wp_die( esc_html__( 'Security check failed.', 'mksddn-migrate-content' ) );
		}

		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Continue execution even if client disconnects.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$upload       = $this->resolve_upload( $chunk_job_id );

		if ( is_wp_error( $upload ) ) {
			$this->response_handler->redirect_with_status( 'error', $upload->get_error_message() );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$import_mode = isset( $_POST['import_mode'] ) ? sanitize_key( $_POST['import_mode'] ) : 'replace';
		$import_mode = in_array( $import_mode, array( 'merge', 'replace' ), true ) ? $import_mode : 'replace';

		$lock       = new ImportLock();
		$lock_token = null;
		$status     = 'success';
		$message    = null;

		try {
			$lock_token = $lock->acquire();
			if ( ! $lock_token ) {
				$this->response_handler->redirect_with_status( 'error', __( 'Another import is already running. Please wait for it to finish.', 'mksddn-migrate-content' ) );
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
				
				// Log import errors for debugging.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[MksDdn MC] Theme import failed: %s', $message ) );
				}
			} else {
				// Log successful import for debugging.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[MksDdn MC] Theme import completed successfully (mode: %s)', $import_mode ) );
				}
			}
		} finally {
			$this->cleanup( $upload['temp'], $upload['cleanup'], $upload['job'] );
			if ( $lock_token ) {
				$lock->release( $lock_token );
			}
		}

		if ( 'error' === $status ) {
			$this->response_handler->redirect_with_status( 'error', $message );
			return;
		}

		$this->response_handler->redirect_with_status( 'success' );
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
		$tmp_name = isset( $file['tmp_name'] ) ? sanitize_text_field( wp_unslash( (string) $file['tmp_name'] ) ) : '';
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
