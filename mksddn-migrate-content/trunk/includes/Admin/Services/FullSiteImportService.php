<?php
/**
 * @file: FullSiteImportService.php
 * @description: Service for importing full site archives
 * @dependencies: Recovery\SnapshotManager, Recovery\HistoryRepository, Recovery\JobLock, Users\UserDiffBuilder, Users\UserPreviewStore, Filesystem\FullContentImporter, Support\SiteUrlGuard, Support\FilesystemHelper, Chunking\ChunkJobRepository, Config\PluginConfig, Admin\Services\NotificationService, Admin\Services\ResponseHandler
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Recovery\JobLock;
use MksDdn\MigrateContent\Recovery\SnapshotManager;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use MksDdn\MigrateContent\Users\UserDiffBuilder;
use MksDdn\MigrateContent\Users\UserPreviewStore;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for importing full site archives.
 *
 * @since 1.0.0
 */
class FullSiteImportService {

	/**
	 * Snapshot manager.
	 *
	 * @var SnapshotManager
	 */
	private SnapshotManager $snapshot_manager;

	/**
	 * History repository.
	 *
	 * @var HistoryRepository
	 */
	private HistoryRepository $history;

	/**
	 * Job lock.
	 *
	 * @var JobLock
	 */
	private JobLock $job_lock;

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
	 * @param SnapshotManager|null    $snapshot_manager Snapshot manager.
	 * @param HistoryRepository|null  $history          History repository.
	 * @param JobLock|null            $job_lock         Job lock.
	 * @param UserPreviewStore|null   $preview_store    User preview store.
	 * @param NotificationService|null $notifications    Notification service.
	 * @param ResponseHandler|null    $response_handler Response handler.
	 * @param ServerBackupScanner|null $server_scanner   Server backup scanner.
	 * @since 1.0.0
	 */
	public function __construct(
		?SnapshotManager $snapshot_manager = null,
		?HistoryRepository $history = null,
		?JobLock $job_lock = null,
		?UserPreviewStore $preview_store = null,
		?NotificationService $notifications = null,
		?ResponseHandler $response_handler = null,
		?ServerBackupScanner $server_scanner = null
	) {
		$this->snapshot_manager = $snapshot_manager ?? new SnapshotManager();
		$this->history          = $history ?? new HistoryRepository();
		$this->job_lock         = $job_lock ?? new JobLock();
		$this->preview_store    = $preview_store ?? new UserPreviewStore();
		$this->notifications    = $notifications ?? new NotificationService();
		$this->response_handler = $response_handler ?? new ResponseHandler( $this->notifications );
		$this->server_scanner   = $server_scanner ?? new ServerBackupScanner();
	}

	/**
	 * Handle full site import request.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_full_import' );

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( $preview_id ) {
			$this->finalize_from_preview( $preview_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in import().
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$upload       = $this->resolve_upload( $chunk_job_id );

		if ( is_wp_error( $upload ) ) {
			$this->response_handler->redirect_with_status( 'error', $upload->get_error_message() );
		}

		$diff_builder = new UserDiffBuilder();
		$diff         = $diff_builder->build( $upload['temp'] );

		if ( is_wp_error( $diff ) ) {
			$this->cleanup( $upload['temp'], $upload['cleanup'], $upload['job'] );
			$this->response_handler->redirect_with_status( 'error', $diff->get_error_message() );
		}

		if ( empty( $diff['incoming'] ) ) {
			$this->execute( $upload );
			return;
		}

		$preview_id = $this->preview_store->create(
			array(
				'file_path'     => $upload['temp'],
				'chunk_job_id'  => $upload['chunk_job_id'],
				'cleanup'        => $upload['cleanup'],
				'original_name' => $upload['original_name'],
				'summary'       => $diff,
			)
		);

		$this->response_handler->redirect_to_user_preview( $preview_id );
	}

	/**
	 * Finalize import using stored preview selection.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 1.0.0
	 */
	public function finalize_from_preview( string $preview_id ): void {
		$preview = $this->preview_store->get( $preview_id );
		if ( ! $preview ) {
			$this->response_handler->redirect_with_status( 'error', __( 'User selection expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->response_handler->redirect_with_status( 'error', __( 'You are not allowed to complete this user selection.', 'mksddn-migrate-content' ) );
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$plan    = $this->build_user_plan( $summary );

		if ( is_wp_error( $plan ) ) {
			$this->response_handler->redirect_with_status( 'error', $plan->get_error_message() );
		}

		$upload = array(
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
			$this->response_handler->redirect_with_status( 'error', __( 'Import file is missing. Restart the upload.', 'mksddn-migrate-content' ) );
		}

		$options = array(
			'user_merge' => array(
				'enabled' => true,
				'plan'    => $plan,
				'tables'  => $summary['tables'] ?? array(),
			),
		);

		$this->preview_store->delete( $preview_id );
		$this->execute( $upload, $options );
	}

	/**
	 * Execute full import with optional user merge options.
	 *
	 * @param array $upload  Upload data.
	 * @param array $options Import options.
	 * @return void
	 * @since 1.0.0
	 */
	public function execute( array $upload, array $options = array() ): void {
		// Always log to help debug import issues.
		error_log( 'MksDdn Migrate: FullSiteImportService::execute() called' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MksDdn Migrate: Options: ' . wp_json_encode( $options ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Increase max execution time via ini_set as fallback.
		@ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

		// Continue execution even if client disconnects.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$temp = $upload['temp'] ?? '';
		if ( '' === $temp || ! file_exists( $temp ) ) {
			error_log( 'MksDdn Migrate: Import file missing: ' . $temp ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->response_handler->redirect_with_status( 'error', __( 'Import file is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$cleanup       = ! empty( $upload['cleanup'] );
		$job           = $upload['job'] ?? null;
		$original_name = $upload['original_name'] ?? '';

		error_log( sprintf( 'MksDdn Migrate: Starting import of file: %s', $original_name ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Create history entry early to get ID for response.
		$history_id = $this->history->start(
			'import',
			array(
				'mode' => 'full',
				'file' => $original_name,
			)
		);

		error_log( sprintf( 'MksDdn Migrate: History ID created: %s', $history_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Redirect to admin page with progress indicator IMMEDIATELY to prevent timeout.
		// This must happen before any long-running operations.
		$this->redirect_to_import_progress( $history_id );

		$lock_id = $this->job_lock->acquire( 'full-import' );
		if ( is_wp_error( $lock_id ) ) {
			error_log( 'MksDdn Migrate: Failed to acquire lock: ' . $lock_id->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->history->finish( $history_id, 'error', array( 'message' => $lock_id->get_error_message() ) );
			$this->cleanup( $temp, $cleanup, $job );
			return;
		}

		error_log( 'MksDdn Migrate: Lock acquired successfully' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Register shutdown function to release lock on fatal errors.
		// This ensures lock is released even if script dies with fatal error.
		register_shutdown_function(
			function () use ( $lock_id, $temp, $cleanup, $job ) {
				$error = error_get_last();
				if ( null !== $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR ), true ) ) {
					error_log( sprintf( 'MksDdn Migrate: Fatal error detected, releasing lock: %s', $error['message'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					$this->job_lock->release( $lock_id );
					$this->cleanup( $temp, $cleanup, $job );
				}
			}
		);

		// Check file size to determine if snapshot creation should be skipped.
		$file_size = file_exists( $temp ) ? filesize( $temp ) : 0;
		$file_size_mb = round( $file_size / ( 1024 * 1024 ), 2 );
		$file_size_gb = round( $file_size / ( 1024 * 1024 * 1024 ), 2 );
		$large_file_threshold = 1024 * 1024 * 1024; // 1GB threshold.
		$very_large_file_threshold = 10 * 1024 * 1024 * 1024; // 10GB threshold.

		error_log( sprintf( 'MksDdn Migrate: Import file size: %s MB (%s GB)', $file_size_mb, $file_size_gb ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		$snapshot = null;
		$skip_snapshot = false;

		// For very large files (>1GB), skip snapshot creation to save memory.
		// Snapshot creation exports entire database which can exhaust memory for large sites.
		// For extremely large files (>10GB), this is critical to prevent memory exhaustion.
		if ( $file_size > $large_file_threshold ) {
			$reason = $file_size > $very_large_file_threshold 
				? sprintf( 'extremely large (%s GB)', $file_size_gb )
				: sprintf( 'very large (%s MB)', $file_size_mb );
			error_log( sprintf( 'MksDdn Migrate: File is %s. Skipping snapshot creation to save memory.', $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$skip_snapshot = true;
			$snapshot = array(
				'id'    => 'skipped-large-file',
				'label' => 'pre-import-full (skipped)',
				'path'  => '',
			);
		} else {
			// Increase memory limit before creating snapshot (snapshot export can be memory-intensive).
			$original_memory_limit = ini_get( 'memory_limit' );
			$current_memory_bytes = wp_convert_hr_to_bytes( $original_memory_limit );
			// For files up to 1GB, use 2GB memory limit for snapshot creation.
			$target_memory_mb = 2048; // 2GB for snapshot creation.
			$target_memory_bytes = $target_memory_mb * 1024 * 1024;

			if ( $current_memory_bytes < $target_memory_bytes && '-1' !== $original_memory_limit ) {
				@ini_set( 'memory_limit', $target_memory_mb . 'M' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
				error_log( sprintf( 'MksDdn Migrate: Increased memory limit to %d MB for snapshot creation', $target_memory_mb ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			error_log( 'MksDdn Migrate: Creating snapshot...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$snapshot = $this->snapshot_manager->create(
				array(
					'label'           => 'pre-import-full',
					'include_plugins' => true,
					'include_themes'  => true,
					'meta'            => array( 'file' => $original_name ),
				)
			);

			// Free memory immediately after snapshot creation.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Restore original memory limit.
			if ( '-1' !== $original_memory_limit && '' !== $original_memory_limit ) {
				@ini_set( 'memory_limit', $original_memory_limit ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
			}

			if ( is_wp_error( $snapshot ) ) {
				error_log( 'MksDdn Migrate: Failed to create snapshot: ' . $snapshot->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				// Don't fail import if snapshot creation fails - log warning but continue.
				error_log( 'MksDdn Migrate: Warning - Continuing import without snapshot due to snapshot creation failure' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				$snapshot = array(
					'id'    => 'failed',
					'label' => 'pre-import-full (failed)',
					'path'  => '',
				);
			} else {
				error_log( 'MksDdn Migrate: Snapshot created successfully' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		// Update history with snapshot info (even if skipped).
		$this->history->update_context(
			$history_id,
			array(
				'snapshot_id'    => $snapshot['id'] ?? 'none',
				'snapshot_label' => $snapshot['label'] ?? $snapshot['id'] ?? 'none',
				'snapshot_skipped' => $skip_snapshot,
			)
		);

		$site_guard = new SiteUrlGuard();
		$importer   = new FullContentImporter();

		error_log( 'MksDdn Migrate: Calling importer->import_from()...' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Set progress callback to update history and touch lock.
		$importer->set_progress_callback(
			function ( int $percent, string $message ) use ( $history_id, $lock_id ) {
				$this->history->update_progress( $history_id, $percent, $message );
				// Touch lock every 10% to prevent expiration during long imports.
				if ( $percent % 10 === 0 ) {
					$this->job_lock->touch( $lock_id, 600 ); // Extend by 10 minutes.
				}
			}
		);

		try {
			$result = $importer->import_from( $temp, $site_guard, $options );

			error_log( sprintf( 'MksDdn Migrate: importer->import_from() returned: %s', is_wp_error( $result ) ? 'WP_Error: ' . $result->get_error_message() : 'success' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			$status  = 'success';
			$message = null;

			if ( is_wp_error( $result ) ) {
				$status  = 'error';
				$message = $result->get_error_message();
				$this->history->finish(
					$history_id,
					'error',
					array( 'message' => $message )
				);

				// Only attempt rollback if snapshot was actually created.
				if ( ! $skip_snapshot && isset( $snapshot['path'] ) && ! empty( $snapshot['path'] ) && file_exists( $snapshot['path'] ) ) {
					$rollback = $this->restore_snapshot( $snapshot, 'auto' );
					if ( is_wp_error( $rollback ) ) {
						$message .= ' ' . sprintf(
							/* translators: %s error message */
							__( 'Automatic rollback failed: %s', 'mksddn-migrate-content' ),
							$rollback->get_error_message()
						);
					} else {
						$message .= ' ' . __( 'Previous state was restored automatically.', 'mksddn-migrate-content' );
					}
				} else {
					$message .= ' ' . __( 'Note: Snapshot was not created due to large file size, so automatic rollback is not available.', 'mksddn-migrate-content' );
				}
			} else {
				$site_guard->restore();
				$this->normalize_plugin_storage();

				$history_context = array();
				$merge_summary   = $importer->get_user_merge_summary();
				if ( ! empty( $merge_summary ) ) {
					$history_context['user_selection'] = sprintf(
						'created:%d updated:%d skipped:%d',
						(int) ( $merge_summary['created'] ?? 0 ),
						(int) ( $merge_summary['updated'] ?? 0 ),
						(int) ( $merge_summary['skipped'] ?? 0 )
					);
				}

				$this->history->finish( $history_id, 'success', $history_context );
			}

			// Update history context with final status.
			if ( $message ) {
				$this->history->update_context( $history_id, array( 'message' => $message ) );
			}
		} finally {
			// Always release lock, even if import failed or was interrupted.
			error_log( sprintf( 'MksDdn Migrate: Releasing lock: %s', $lock_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$this->job_lock->release( $lock_id );
			$this->cleanup( $temp, $cleanup, $job );
		}
	}

	/**
	 * Build user plan from request.
	 *
	 * @param array $summary Preview summary.
	 * @return array|WP_Error
	 * @since 1.0.0
	 */
	public function build_user_plan( array $summary ): array|WP_Error {
		$incoming = isset( $summary['incoming'] ) && is_array( $summary['incoming'] ) ? $summary['incoming'] : array();
		if ( empty( $incoming ) ) {
			return array();
		}

		$defaults = array();
		foreach ( $incoming as $entry ) {
			$email = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
			if ( ! $email ) {
				continue;
			}

			$defaults[ strtolower( $email ) ] = $email;
		}

		if ( empty( $defaults ) ) {
			return new WP_Error( 'mksddn_mc_user_plan_empty', __( 'No users available for selection.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in import(); raw plan sanitized below.
		$raw_plan_input = isset( $_POST['user_plan'] ) && is_array( $_POST['user_plan'] ) ? wp_unslash( $_POST['user_plan'] ) : array();
		$raw_plan       = array();
		foreach ( $raw_plan_input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$raw_plan[] = array(
				'email'  => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
				'import' => ! empty( $row['import'] ),
				'mode'   => isset( $row['mode'] ) ? sanitize_text_field( $row['mode'] ) : '',
			);
		}

		$plan = array();

		foreach ( $defaults as $email ) {
			$plan[ $email ] = array(
				'import' => false,
				'mode'   => 'replace',
			);
		}

		foreach ( $raw_plan as $row ) {
			$email = $row['email'];
			if ( ! $email ) {
				continue;
			}

			$lookup = strtolower( $email );
			if ( ! isset( $defaults[ $lookup ] ) ) {
				continue;
			}

			$import = ! empty( $row['import'] );
			$mode   = 'keep' === $row['mode'] ? 'keep' : 'replace';

			$plan[ $email ] = array(
				'import' => $import,
				'mode'   => $mode,
			);
		}

		return $plan;
	}

	/**
	 * Resolve uploaded file or chunk job.
	 *
	 * @param string $chunk_job_id Chunk job identifier.
	 * @return array|WP_Error
	 * @since 1.0.0
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
		if ( ! isset( $_FILES['full_import_file'], $_FILES['full_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'No file uploaded.', 'mksddn-migrate-content' ) );
		}

		$file     = $_FILES['full_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized below, nonce verified upstream
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

		$temp = wp_tempnam( 'mksddn-full-import-' );
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
	 * Restore snapshot.
	 *
	 * @param array  $snapshot Snapshot metadata.
	 * @param string $action   manual|auto.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function restore_snapshot( array $snapshot, string $action = 'manual' ): bool|WP_Error {
		if ( empty( $snapshot['path'] ) || ! file_exists( $snapshot['path'] ) ) {
			return new WP_Error( 'mksddn_snapshot_missing', __( 'Snapshot archive is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$history_entry = $this->history->start(
			'rollback',
			array(
				'snapshot_id'    => $snapshot['id'] ?? '',
				'snapshot_label' => $snapshot['label'] ?? '',
				'action'         => $action,
			)
		);

		$guard    = new SiteUrlGuard();
		$importer = new FullContentImporter();
		$result   = $importer->import_from( $snapshot['path'], $guard );

		if ( is_wp_error( $result ) ) {
			$this->history->finish(
				$history_entry,
				'error',
				array( 'message' => $result->get_error_message() )
			);

			return $result;
		}

		$guard->restore();
		$this->history->finish( $history_entry, 'success' );

		$guard->restore();
		$this->normalize_plugin_storage();
		return true;
	}

	/**
	 * Normalize storage paths for known plugins.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function normalize_plugin_storage(): void {
		$target = trailingslashit( WP_CONTENT_DIR ) . 'ai1wm-backups';
		wp_mkdir_p( $target );
		update_option( 'mksddn_mc_storage_path', $target );
	}

	/**
	 * Cleanup temp files and chunk jobs.
	 *
	 * @param string     $temp    Temp file path.
	 * @param bool       $cleanup Whether temp should be removed.
	 * @param object|null $job    Chunk job instance.
	 * @return void
	 * @since 1.0.0
	 */
	private function cleanup( string $temp, bool $cleanup, $job ): void {
		if ( $cleanup && $temp && file_exists( $temp ) ) {
			FilesystemHelper::delete( $temp );
		}

		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}

	/**
	 * Redirect to admin page with import progress indicator.
	 *
	 * Sends redirect to browser then continues execution in background.
	 * Uses fastcgi_finish_request() if available to close connection while
	 * allowing PHP to continue processing the import.
	 *
	 * @param string $history_id History entry ID for status polling.
	 * @return void
	 * @since 1.0.0
	 */
	private function redirect_to_import_progress( string $history_id ): void {
		// Ensure all output buffers are cleared.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Build redirect URL.
		$redirect_url = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$redirect_url = add_query_arg(
			array(
				'mksddn_mc_import_status' => $history_id,
			),
			$redirect_url
		);

		// Set HTTP 302 Redirect headers.
		if ( ! headers_sent() ) {
			// Send standard HTTP 302 Found redirect.
			http_response_code( 302 );
			
			// Disable caching.
			nocache_headers();
			
			// Send redirect location.
			header( 'Location: ' . esc_url_raw( $redirect_url ) );
			
			// Explicitly set content length to 0 since we have no body.
			header( 'Content-Length: 0' );
			
			// Suggest browser to close connection.
			header( 'Connection: close' );
		}

		error_log( 'MksDdn Migrate: Sending HTTP 302 redirect to: ' . esc_url_raw( $redirect_url ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Flush output to send headers immediately.
		if ( function_exists( 'flush' ) ) {
			flush();
		}

		error_log( 'MksDdn Migrate: Redirect headers flushed, now closing connection' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// Close connection to browser for different server types.
		$this->close_client_connection();

		error_log( 'MksDdn Migrate: Connection closed, import process continuing in background' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		// DO NOT call exit() - import must continue after response sent to browser.
		// fastcgi_finish_request() already closed the browser connection.
	}

	/**
	 * Close client connection gracefully across different server types.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function close_client_connection(): void {
		// Disable output buffering and compression.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Set content length to prevent chunked transfer encoding.
		if ( ! headers_sent() && ob_get_length() === false ) {
			header( 'Content-Length: 0' );
		}

		// Try FastCGI first (PHP-FPM, Nginx).
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			error_log( 'MksDdn Migrate: Closing client connection via fastcgi_finish_request()' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			fastcgi_finish_request();
			return;
		}

		// For Apache + mod_php: use connection_aborted() check.
		if ( function_exists( 'connection_aborted' ) ) {
			// Send Connection: close header to signal client to close.
			if ( ! headers_sent() ) {
				header( 'Connection: close' );
			}
			// Wait a moment for output to flush.
			error_log( 'MksDdn Migrate: Closing client connection via Connection: close header' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			usleep( 100000 ); // 0.1 seconds.
		}

		// Last resort: ignore user abort to allow background execution.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		error_log( 'MksDdn Migrate: Client connection handling complete - import continues in background' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

