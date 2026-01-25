<?php
/**
 * @file: SelectedContentImportService.php
 * @description: Service for importing selected content (pages, posts, etc.)
 * @dependencies: Archive\Extractor, Import\ImportHandler, Admin\Services\NotificationService, Admin\Services\ProgressService, Admin\Services\ImportFileValidator, Admin\Services\ImportPayloadPreparer
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Import\ImportHandler as ImportService;
use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Support\ImportLock;
use MksDdn\MigrateContent\Support\MimeTypeHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for importing selected content.
 *
 * @since 1.0.0
 */
class SelectedContentImportService {

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

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
	 * File validator.
	 *
	 * @var ImportFileValidator
	 */
	private ImportFileValidator $file_validator;

	/**
	 * Payload preparer.
	 *
	 * @var ImportPayloadPreparer
	 */
	private ImportPayloadPreparer $payload_preparer;

	/**
	 * Server backup scanner.
	 *
	 * @var ServerBackupScanner
	 */
	private ServerBackupScanner $server_scanner;

	/**
	 * Constructor.
	 *
	 * @param Extractor|null              $extractor        Archive extractor.
	 * @param NotificationService|null    $notifications    Notification service.
	 * @param ProgressService|null        $progress         Progress service.
	 * @param ImportFileValidator|null    $file_validator   File validator.
	 * @param ImportPayloadPreparer|null  $payload_preparer Payload preparer.
	 * @param ServerBackupScanner|null    $server_scanner   Server backup scanner.
	 * @since 1.0.0
	 */
	public function __construct(
		?Extractor $extractor = null,
		?NotificationService $notifications = null,
		?ProgressService $progress = null,
		?ImportFileValidator $file_validator = null,
		?ImportPayloadPreparer $payload_preparer = null,
		?ServerBackupScanner $server_scanner = null
	) {
		$this->extractor        = $extractor ?? new Extractor();
		$this->notifications    = $notifications ?? new NotificationService();
		$this->progress         = $progress ?? new ProgressService();
		$this->file_validator   = $file_validator ?? new ImportFileValidator();
		$this->payload_preparer  = $payload_preparer ?? new ImportPayloadPreparer( $this->extractor );
		$this->server_scanner   = $server_scanner ?? new ServerBackupScanner();
	}

	/**
	 * Import selected content from uploaded file.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function import(): void {
		// Start output buffering early to catch any accidental output.
		if ( ! ob_get_level() ) {
			ob_start();
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method || ! check_admin_referer( 'import_single_page_nonce' ) ) {
			return;
		}

		$lock       = new ImportLock();
		$lock_token = $lock->acquire();
		if ( ! $lock_token ) {
			$this->notifications->redirect_with_notice( 'error', __( 'Another import is already running. Please wait for it to finish.', 'mksddn-migrate-content' ) );
			return;
		}

		try {
			// Check if chunked file path is provided (from chunked upload).
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
			$chunk_file_path = isset( $_POST['chunk_file_path'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_file_path'] ) ) : '';

			if ( $chunk_file_path && file_exists( $chunk_file_path ) ) {
				// Validate that file path is within allowed directory (chunk job repository).
				// Chunk files are stored in a controlled directory, so this is safe.
				$real_path = realpath( $chunk_file_path );
				if ( false === $real_path ) {
					$this->notifications->redirect_with_notice( 'error', __( 'Invalid chunked file path.', 'mksddn-migrate-content' ) );
					return;
				}

				// Use chunked file directly.
				$extension = strtolower( pathinfo( $real_path, PATHINFO_EXTENSION ) );
				if ( ! in_array( $extension, array( 'wpbkp', 'json' ), true ) ) {
					$extension = 'wpbkp'; // Default to wpbkp for chunked uploads.
				}

				$file_data = array(
					'name'      => isset( $_POST['chunk_job_id'] ) ? sprintf( 'chunk:%s.wpbkp', sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) ) : 'chunked-upload.wpbkp',
					'path'      => $real_path,
					'size'      => filesize( $real_path ),
					'extension' => $extension,
					'mime'      => MimeTypeHelper::detect( $real_path, $extension ),
				);
			} elseif ( isset( $_POST['server_file'] ) ) {
				// Check if server file is provided.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified above.
				$server_file = sanitize_text_field( wp_unslash( $_POST['server_file'] ) );

				$file_info = $this->server_scanner->get_file( $server_file );

				if ( is_wp_error( $file_info ) ) {
					$this->notifications->redirect_with_notice( 'error', $file_info->get_error_message() );
					return;
				}

				$file_data = array(
					'name'      => $file_info['name'],
					'path'      => $file_info['path'],
					'size'      => $file_info['size'],
					'extension' => $file_info['extension'],
					'mime'      => MimeTypeHelper::detect( $file_info['path'], $file_info['extension'] ),
				);
			} else {
				if ( ! isset( $_FILES['import_file'], $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
					$this->notifications->redirect_with_notice( 'error', __( 'Failed to upload file.', 'mksddn-migrate-content' ) );
					return;
				}

				// Verify that the file was actually uploaded via HTTP POST.
				$tmp_name = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( wp_unslash( $_FILES['import_file']['tmp_name'] ) ) : '';
				if ( ! $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
					$this->notifications->redirect_with_notice( 'error', __( 'File upload security check failed.', 'mksddn-migrate-content' ) );
					return;
				}

				// Pass file data to validator which will sanitize all fields.
				$file_data = $this->file_validator->validate( $_FILES['import_file'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file validated and sanitized by validator
				if ( is_wp_error( $file_data ) ) {
					$this->notifications->redirect_with_notice( 'error', $file_data->get_error_message() );
					return;
				}
			}

			$result = $this->payload_preparer->prepare(
				$file_data['extension'],
				$file_data['mime'],
				$file_data['path']
			);

			if ( is_wp_error( $result ) ) {
				$this->notifications->redirect_with_notice( 'error', $result->get_error_message() );
				return;
			}

			$payload                 = $result['payload'];
			$payload_type            = $result['type'];
			$payload['type']         = $payload_type;
			$payload['_mksddn_media'] = $payload['_mksddn_media'] ?? $result['media'];

			$import_handler = new ImportService();

			if ( 'archive' === $result['media_source'] ) {
				$import_handler->set_media_file_loader(
					function ( string $archive_path ) use ( $file_data ) {
						return $this->extractor->extract_media_file( $archive_path, $file_data['path'] );
					}
				);
			}

			$import_result = $this->process_import( $import_handler, $payload_type, $payload );

			if ( $import_result ) {
				// Get import details for redirect.
				// For bundle, don't pass slug/title as it contains multiple items.
				if ( 'bundle' === $payload_type ) {
					$this->notifications->redirect_with_selected_import_success( $payload_type, '', '', '' );
				} else {
					$slug = isset( $payload['slug'] ) ? sanitize_text_field( $payload['slug'] ) : '';
					$title = isset( $payload['title'] ) ? sanitize_text_field( $payload['title'] ) : '';
					$post_type = isset( $payload['type'] ) ? sanitize_text_field( $payload['type'] ) : 'page';
					
					$this->notifications->redirect_with_selected_import_success( $payload_type, $slug, $title, $post_type );
				}
			} else {
				$this->notifications->redirect_with_notice( 'error', __( 'Failed to import content.', 'mksddn-migrate-content' ) );
			}
		} finally {
			$lock->release( $lock_token );
		}
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
}

