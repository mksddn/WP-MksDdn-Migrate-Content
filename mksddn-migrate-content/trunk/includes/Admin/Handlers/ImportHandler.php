<?php
/**
 * @file: ImportHandler.php
 * @description: Handler for import operations
 * @dependencies: Archive\Extractor, Import\ImportHandler, Recovery\SnapshotManager, Recovery\HistoryRepository, Recovery\JobLock, Chunking\ChunkJobRepository, Users\UserDiffBuilder, Users\UserPreviewStore, Filesystem\FullContentImporter, Support\SiteUrlGuard, Support\FilesystemHelper, Admin\Services\NotificationService, Admin\Services\ProgressService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Handlers;

use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\ProgressService;
use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Import\ImportHandler as ImportService;
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
 * Handler for import operations.
 *
 * @since 1.0.0
 */
class ImportHandler {

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

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
	 * Progress service.
	 *
	 * @var ProgressService
	 */
	private ProgressService $progress;

	/**
	 * Constructor.
	 *
	 * @param Extractor|null          $extractor        Archive extractor.
	 * @param SnapshotManager|null    $snapshot_manager Snapshot manager.
	 * @param HistoryRepository|null  $history          History repository.
	 * @param JobLock|null            $job_lock         Job lock.
	 * @param UserPreviewStore|null   $preview_store    User preview store.
	 * @param NotificationService|null $notifications    Notification service.
	 * @param ProgressService|null    $progress         Progress service.
	 * @since 1.0.0
	 */
	public function __construct(
		?Extractor $extractor = null,
		?SnapshotManager $snapshot_manager = null,
		?HistoryRepository $history = null,
		?JobLock $job_lock = null,
		?UserPreviewStore $preview_store = null,
		?NotificationService $notifications = null,
		?ProgressService $progress = null
	) {
		$this->extractor        = $extractor ?? new Extractor();
		$this->snapshot_manager = $snapshot_manager ?? new SnapshotManager();
		$this->history          = $history ?? new HistoryRepository();
		$this->job_lock         = $job_lock ?? new JobLock();
		$this->preview_store    = $preview_store ?? new UserPreviewStore();
		$this->notifications    = $notifications ?? new NotificationService();
		$this->progress         = $progress ?? new ProgressService();
	}

	/**
	 * Handle selected content import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_selected_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method || ! check_admin_referer( 'import_single_page_nonce' ) ) {
			return;
		}

		$lock_id = $this->job_lock->acquire( 'selected-import' );
		if ( is_wp_error( $lock_id ) ) {
			$this->notifications->show_error( esc_html( $lock_id->get_error_message() ) );
			return;
		}

		$history_id = null;

		try {
			if ( ! isset( $_FILES['import_file'], $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
				$this->notifications->show_error( esc_html__( 'Failed to upload file.', 'mksddn-migrate-content' ) );
				$this->progress->update( 100, __( 'Upload failed', 'mksddn-migrate-content' ) );
				return;
			}

			$file     = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['import_file']['tmp_name'] ) : '';
			$filename = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( (string) $_FILES['import_file']['name'] ) : '';
			$size     = isset( $_FILES['import_file']['size'] ) ? (int) $_FILES['import_file']['size'] : 0;

			if ( 0 >= $size ) {
				$this->notifications->show_error( esc_html__( 'Invalid file size.', 'mksddn-migrate-content' ) );
				return;
			}

			$this->progress->update( 10, __( 'Validating file…', 'mksddn-migrate-content' ) );

			$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
			$mime = function_exists( 'mime_content_type' ) && '' !== $file ? mime_content_type( $file ) : '';

			$result = $this->prepare_import_payload( $ext, $mime, $file );

			if ( is_wp_error( $result ) ) {
				$this->notifications->show_error( $result->get_error_message() );
				$this->progress->update( 100, __( 'Import aborted', 'mksddn-migrate-content' ) );
				return;
			}

			$this->progress->update( 40, __( 'Parsing content…', 'mksddn-migrate-content' ) );

			$snapshot = $this->snapshot_manager->create(
				array(
					'label' => 'pre-import-selected',
					'meta'  => array( 'file' => $filename ),
				)
			);

			if ( is_wp_error( $snapshot ) ) {
				$this->notifications->show_error( $snapshot->get_error_message() );
				return;
			}

			$history_id = $this->history->start(
				'import',
				array(
					'mode'           => 'selected',
					'file'           => $filename,
					'snapshot_id'    => $snapshot['id'],
					'snapshot_label' => $snapshot['label'] ?? $snapshot['id'],
				)
			);

			$payload                 = $result['payload'];
			$payload_type            = $result['type'];
			$payload['type']         = $payload_type;
			$payload['_mksddn_media'] = $payload['_mksddn_media'] ?? $result['media'];

			$import_handler = new ImportService();

			if ( 'archive' === $result['media_source'] ) {
				$import_handler->set_media_file_loader(
					function ( string $archive_path ) use ( $file ) {
						return $this->extractor->extract_media_file( $archive_path, $file );
					}
				);
			}

			$this->progress->update( 70, __( 'Importing content…', 'mksddn-migrate-content' ) );

			$import_result = $this->process_import( $import_handler, $payload_type, $payload );

			if ( $import_result ) {
				$this->progress->update( 100, __( 'Completed', 'mksddn-migrate-content' ) );
				// translators: %s is imported item type.
				$this->notifications->show_success( sprintf( esc_html__( '%s imported successfully!', 'mksddn-migrate-content' ), ucfirst( (string) $payload_type ) ) );
				if ( $history_id ) {
					$this->history->finish( $history_id, 'success' );
				}
			} else {
				$this->progress->update( 100, __( 'Import failed', 'mksddn-migrate-content' ) );
				$this->notifications->show_error( esc_html__( 'Failed to import content.', 'mksddn-migrate-content' ) );
				if ( $history_id ) {
					$this->history->finish(
						$history_id,
						'error',
						array( 'message' => __( 'Selected import failed.', 'mksddn-migrate-content' ) )
					);
				}
			}
		} finally {
			$this->job_lock->release( $lock_id );
		}
	}

	/**
	 * Handle full site import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_full_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_full_import' );

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( $preview_id ) {
			$this->finalize_full_import_from_preview( $preview_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_full_import.
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$upload       = $this->resolve_full_import_upload( $chunk_job_id );

		if ( is_wp_error( $upload ) ) {
			$this->notifications->redirect_full_status( 'error', $upload->get_error_message() );
		}

		$diff_builder = new UserDiffBuilder();
		$diff         = $diff_builder->build( $upload['temp'] );

		if ( is_wp_error( $diff ) ) {
			$this->cleanup_full_import( $upload['temp'], $upload['cleanup'], $upload['job'] );
			$this->notifications->redirect_full_status( 'error', $diff->get_error_message() );
		}

		if ( empty( $diff['incoming'] ) ) {
			$this->execute_full_import( $upload );
			return;
		}

		$preview_id = $this->preview_store->create(
			array(
				'file_path'     => $upload['temp'],
				'chunk_job_id'  => $upload['chunk_job_id'],
				'cleanup'       => $upload['cleanup'],
				'original_name' => $upload['original_name'],
				'summary'       => $diff,
			)
		);

		$this->redirect_user_preview( $preview_id );
	}

	/**
	 * Prepare import payload from uploaded file.
	 *
	 * @param string $extension File extension (lowercase).
	 * @param string $mime      Detected mime type.
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error
	 * @since 1.0.0
	 */
	private function prepare_import_payload( string $extension, string $mime, string $file_path ): array|WP_Error {
		switch ( $extension ) {
			case 'json':
				$json_mimes = array( 'application/json', 'text/plain', 'application/octet-stream' );
				if ( '' !== $mime && ! in_array( $mime, $json_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a JSON export created by this plugin.', 'mksddn-migrate-content' ) );
				}

				$data = $this->read_json_payload( $file_path );
				if ( is_wp_error( $data ) ) {
					return $data;
				}

				return array(
					'type'         => $data['type'] ?? 'page',
					'payload'      => $data,
					'media'        => $data['_mksddn_media'] ?? array(),
					'media_source' => 'json',
				);

			case 'wpbkp':
				$archive_mimes = array( 'application/octet-stream', 'application/zip', 'application/x-zip-compressed' );
				if ( '' !== $mime && ! in_array( $mime, $archive_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a .wpbkp archive created by this plugin.', 'mksddn-migrate-content' ) );
				}

				$extracted = $this->extractor->extract( $file_path );
				if ( is_wp_error( $extracted ) ) {
					return $extracted;
				}

				return array(
					'type'         => $extracted['type'],
					'payload'      => $extracted['payload'],
					'media'        => $extracted['media'] ?? array(),
					'media_source' => 'archive',
				);
		}

		return new WP_Error( 'mksddn_mc_invalid_type', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Read and decode JSON payload.
	 *
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error
	 * @since 1.0.0
	 */
	private function read_json_payload( string $file_path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file -- Local temporary file validated earlier.
		$json = file_get_contents( $file_path );
		if ( false === $json ) {
			return new WP_Error( 'mksddn_mc_json_unreadable', __( 'Unable to read JSON file.', 'mksddn-migrate-content' ) );
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_json_invalid', __( 'Invalid JSON structure.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}

	/**
	 * Process import by type.
	 *
	 * @param ImportService $import_handler Handler instance.
	 * @param string        $type           Payload type.
	 * @param array         $data          Payload.
	 * @return bool
	 * @since 1.0.0
	 */
	private function process_import( ImportService $import_handler, string $type, array $data ): bool {
		if ( 'bundle' === $type ) {
			return $import_handler->import_bundle( $data );
		}

		return $import_handler->import_single_page( $data );
	}

	/**
	 * Continue import using stored preview selection.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 1.0.0
	 */
	private function finalize_full_import_from_preview( string $preview_id ): void {
		$preview = $this->preview_store->get( $preview_id );
		if ( ! $preview ) {
			$this->notifications->redirect_full_status( 'error', __( 'User selection expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->notifications->redirect_full_status( 'error', __( 'You are not allowed to complete this user selection.', 'mksddn-migrate-content' ) );
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$plan    = $this->build_user_plan_from_request( $summary );

		if ( is_wp_error( $plan ) ) {
			$this->notifications->redirect_full_status( 'error', $plan->get_error_message() );
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
			$this->notifications->redirect_full_status( 'error', __( 'Import file is missing. Restart the upload.', 'mksddn-migrate-content' ) );
		}

		$options = array(
			'user_merge' => array(
				'enabled' => true,
				'plan'    => $plan,
				'tables'  => $summary['tables'] ?? array(),
			),
		);

		$this->preview_store->delete( $preview_id );
		$this->execute_full_import( $upload, $options );
	}

	/**
	 * Build user plan from request.
	 *
	 * @param array $summary Preview summary.
	 * @return array|WP_Error
	 * @since 1.0.0
	 */
	private function build_user_plan_from_request( array $summary ) {
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle_full_import(); raw plan sanitized below.
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
	private function resolve_full_import_upload( string $chunk_job_id ) {
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_full_import().
		if ( ! isset( $_FILES['full_import_file'], $_FILES['full_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'No file uploaded.', 'mksddn-migrate-content' ) );
		}

		$file     = $_FILES['full_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized below, nonce verified upstream
		$tmp_name = isset( $file['tmp_name'] ) ? \sanitize_text_field( \wp_unslash( (string) $file['tmp_name'] ) ) : '';
		$name     = isset( $file['name'] ) ? \sanitize_file_name( \wp_unslash( (string) $file['name'] ) ) : '';
		$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 ) {
			return new WP_Error( 'mksddn_mc_invalid_size', __( 'Invalid file size.', 'mksddn-migrate-content' ) );
		}

		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'wpbkp' !== $ext ) {
			return new WP_Error( 'mksddn_mc_invalid_type', __( 'Please upload a .wpbkp archive generated by this plugin.', 'mksddn-migrate-content' ) );
		}

		$temp = \wp_tempnam( 'mksddn-full-import-' );
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
	 * Execute full import with optional user merge options.
	 *
	 * @param array $upload  Upload data.
	 * @param array $options Import options.
	 * @return void
	 * @since 1.0.0
	 */
	private function execute_full_import( array $upload, array $options = array() ): void {
		$temp = $upload['temp'] ?? '';
		if ( '' === $temp || ! file_exists( $temp ) ) {
			$this->notifications->redirect_full_status( 'error', __( 'Import file is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$cleanup       = ! empty( $upload['cleanup'] );
		$job           = $upload['job'] ?? null;
		$original_name = $upload['original_name'] ?? '';

		$lock_id = $this->job_lock->acquire( 'full-import' );
		if ( is_wp_error( $lock_id ) ) {
			$this->cleanup_full_import( $temp, $cleanup, $job );
			$this->notifications->redirect_full_status( 'error', $lock_id->get_error_message() );
		}

		$snapshot = $this->snapshot_manager->create(
			array(
				'label'           => 'pre-import-full',
				'include_plugins' => true,
				'include_themes'  => true,
				'meta'            => array( 'file' => $original_name ),
			)
		);

		if ( is_wp_error( $snapshot ) ) {
			$this->job_lock->release( $lock_id );
			$this->cleanup_full_import( $temp, $cleanup, $job );
			$this->notifications->redirect_full_status( 'error', $snapshot->get_error_message() );
		}

		$history_id = $this->history->start(
			'import',
			array(
				'mode'           => 'full',
				'file'           => $original_name,
				'snapshot_id'    => $snapshot['id'],
				'snapshot_label' => $snapshot['label'] ?? $snapshot['id'],
			)
		);

		$site_guard = new SiteUrlGuard();
		$importer   = new FullContentImporter();
		$result     = $importer->import_from( $temp, $site_guard, $options );

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

		$this->job_lock->release( $lock_id );
		$this->cleanup_full_import( $temp, $cleanup, $job );
		$this->notifications->redirect_full_status( $status, $message );
	}

	/**
	 * Restore snapshot.
	 *
	 * @param array  $snapshot Snapshot metadata.
	 * @param string $action   manual|auto.
	 * @return true|WP_Error
	 * @since 1.0.0
	 */
	private function restore_snapshot( array $snapshot, string $action = 'manual' ) {
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
		update_option( 'ai1wm_storage_path', $target );
	}

	/**
	 * Redirect to user preview.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 1.0.0
	 */
	private function redirect_user_preview( string $preview_id ): void {
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$url  = add_query_arg( array( 'mksddn_mc_user_review' => $preview_id ), $base );
		wp_safe_redirect( $url );
		exit;
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
	private function cleanup_full_import( string $temp, bool $cleanup, $job ): void {
		if ( $cleanup && $temp && file_exists( $temp ) ) {
			FilesystemHelper::delete( $temp );
		}

		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}
}

