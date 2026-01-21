<?php
/**
 * REST controller for chunk upload/download.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Contracts\ChunkJobRepositoryInterface;
use MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface;
use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChunkRestController {

	private ChunkJobRepositoryInterface $repository;

	private HistoryRepositoryInterface $history;

	private int $chunk_size = 5242880; // 5 MB.

	/**
	 * Constructor.
	 *
	 * @param ChunkJobRepositoryInterface  $repository Chunk job repository.
	 * @param HistoryRepositoryInterface|null $history    History repository (optional).
	 * @since 1.0.0
	 */
	public function __construct( ChunkJobRepositoryInterface $repository, ?HistoryRepositoryInterface $history = null ) {
		$this->repository = $repository;
		$this->history    = $history ?? new HistoryRepository();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'mksddn/v1',
			'/chunk/init',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'init_job' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

		register_rest_route(
			'mksddn/v1',
			'/chunk/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_chunk' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

		register_rest_route(
			'mksddn/v1',
			'/chunk/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);
		register_rest_route(
			'mksddn/v1',
			'/chunk/download/init',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'init_download' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

		register_rest_route(
			'mksddn/v1',
			'/chunk/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_chunk' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

		register_rest_route(
			'mksddn/v1',
			'/chunk/cancel',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'cancel_job' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

		register_rest_route(
			'mksddn/v1',
			'/import/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_import_status' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);
	}

	public function ensure_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public function init_job( WP_REST_Request $request ) {
		$job   = $this->repository->create();
		$total = max( 1, absint( $request->get_param( 'total_chunks' ) ) );
		$requested_chunk = absint( $request->get_param( 'chunk_size' ) );
		$chunk_size      = $this->chunk_size;

		if ( $requested_chunk >= 262144 && $requested_chunk <= 5242880 ) { // 256 KB – 5 MB.
			$chunk_size = $requested_chunk;
		}

		$job->update(
			array(
				'total_chunks' => $total,
				'checksum'     => sanitize_text_field( $request->get_param( 'checksum' ) ),
				'chunk_size'   => $chunk_size,
				'mode'         => 'upload',
			)
		);

		return array(
			'job_id' => $job->get_data()['id'],
			'chunk_size' => $chunk_size,
		);
	}

	public function upload_chunk( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		$index  = absint( $request->get_param( 'index' ) );
		$chunk  = $request->get_param( 'chunk' );

		if ( empty( $job_id ) || null === $chunk ) {
			return new WP_Error( 'mksddn_invalid_chunk', __( 'Missing chunk data.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$job   = $this->repository->get( $job_id );
		$file  = $job->get_file_path();
		$bytes = base64_decode( $chunk, true );

		if ( false === $bytes ) {
			return new WP_Error( 'mksddn_chunk_decode', __( 'Invalid chunk payload.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$reset = 0 === $index;
		if ( ! FilesystemHelper::write_bytes( $file, $bytes, $reset ) ) {
			return new WP_Error( 'mksddn_chunk_write', __( 'Unable to write chunk.', 'mksddn-migrate-content' ), array( 'status' => 500 ) );
		}

		$job->update(
			array(
				'received_chunks' => $index + 1,
				'completed'       => ( $index + 1 ) >= ( $job->get_data()['total_chunks'] ?? PHP_INT_MAX ),
			)
		);

		return array(
			'next_index' => $index + 1,
			'completed'  => $job->get_data()['completed'],
		);
	}

	public function init_download( WP_REST_Request $request ) {
		$job    = $this->repository->create();
		$job_id = $job->get_data()['id'];
		$file   = $job->get_file_path();
		
		$dir_result = FilesystemHelper::ensure_directory( $file );
		if ( is_wp_error( $dir_result ) ) {
			$job->delete();
			return new WP_Error( 'mksddn_dir_create', __( 'Unable to create export directory.', 'mksddn-migrate-content' ), array( 'status' => 500 ) );
		}

		// Mark job as in progress.
		$job->update(
			array(
				'mode'         => 'download',
				'status'       => 'processing',
			)
		);

		// Return job_id immediately, then run export after response is sent.
		// This allows client to track and cancel the export even if page is closed.
		$response = array(
			'job_id'       => $job_id,
			'status'       => 'processing',
			'total_chunks' => 0,
		);

		// Run export after response is sent (WordPress REST API will handle response output).
		register_shutdown_function( function() use ( $job_id, $file ) {
			$this->process_export_background( $job_id, $file );
		} );

		return $response;
	}

	/**
	 * Process export in background.
	 *
	 * @param string $job_id Job ID.
	 * @param string $file   Export file path.
	 * @return void
	 */
	private function process_export_background( string $job_id, string $file ): void {
		$json_path = $this->repository->get_storage_dir() . $job_id . '.json';
		
		// Check if job was cancelled before starting.
		if ( ! file_exists( $json_path ) ) {
			return;
		}

		// Create exporter with cancellation check callback.
		$check_cancelled = function() use ( $json_path, $job_id ) {
			// Clear stat cache to ensure we get fresh file status.
			clearstatcache( true, $json_path );
			$exists = file_exists( $json_path );
			
			// Always log cancellation check for debugging (not just when cancelled).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				static $check_count = 0;
				$check_count++;
				// Log every 100th check to avoid log spam.
				if ( 0 === $check_count % 100 || ! $exists ) {
					error_log( sprintf( 'MksDdn Migrate Export: Cancellation check #%d for job %s - file exists: %s', $check_count, $job_id, $exists ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			
			return ! $exists;
		};

		$export = new FullContentExporter();
		
		// Set cancellation check callback.
		$export->set_cancellation_check( $check_cancelled );
		
		// Log that callback was set.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'MksDdn Migrate Export: Starting background export for job %s', $job_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		
		$result = $export->export_to( $file );

		// Check if job was cancelled during export.
		if ( ! file_exists( $json_path ) ) {
			// Job was cancelled, clean up export file.
			if ( file_exists( $file ) ) {
				FilesystemHelper::delete( $file );
			}
			return;
		}

		if ( is_wp_error( $result ) ) {
			// Check if it's a cancellation error.
			if ( 'mksddn_mc_export_cancelled' === $result->get_error_code() ) {
				// Job was cancelled, clean up export file.
				if ( file_exists( $file ) ) {
					FilesystemHelper::delete( $file );
				}
				return;
			}

			$job = $this->repository->get( $job_id );
			if ( $job ) {
				$job->update(
					array(
						'status' => 'error',
						'error'  => $result->get_error_message(),
					)
				);
			}
			return;
		}

		// Final check if job was cancelled.
		if ( ! file_exists( $json_path ) ) {
			if ( file_exists( $file ) ) {
				FilesystemHelper::delete( $file );
			}
			return;
		}

		$size = filesize( $file );
		if ( false === $size || 0 === $size ) {
			$job = $this->repository->get( $job_id );
			if ( $job ) {
				$job->delete();
			}
			return;
		}

		$total_chunks = (int) max( 1, ceil( $size / $this->chunk_size ) );

		$job = $this->repository->get( $job_id );
		if ( $job ) {
			$job->update(
				array(
					'total_chunks' => $total_chunks,
					'chunk_size'   => $this->chunk_size,
					'mode'         => 'download',
					'status'       => 'ready',
					'size'         => $size,
				)
			);
		}
	}

	/**
	 * Check if job exists (not cancelled).
	 *
	 * @param string $job_id Job ID.
	 * @return bool True if job exists, false if cancelled.
	 */
	private function is_job_active( string $job_id ): bool {
		$json_path = $this->repository->get_storage_dir() . $job_id . '.json';
		return file_exists( $json_path );
	}

	public function download_chunk( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		$index  = absint( $request->get_param( 'index' ) );

		if ( empty( $job_id ) ) {
			return new WP_Error( 'mksddn_missing_job', __( 'Job ID is required.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$job   = $this->repository->get( $job_id );
		
		// Check if job still exists (wasn't cancelled).
		$json_path = $this->repository->get_storage_dir() . $job_id . '.json';
		if ( ! file_exists( $json_path ) ) {
			return new WP_Error( 'mksddn_job_cancelled', __( 'Export job was cancelled.', 'mksddn-migrate-content' ), array( 'status' => 410 ) );
		}
		
		$data  = $job->get_data();
		$file  = $job->get_file_path();
		$total = (int) ( $data['total_chunks'] ?? 0 );

		if ( $total && $index >= $total ) {
			return new WP_Error( 'mksddn_chunk_oob', __( 'Chunk index out of bounds.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		if ( ! file_exists( $file ) ) {
			return new WP_Error( 'mksddn_job_file_missing', __( 'Job data not found.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$chunk_size = $data['chunk_size'] ?? $this->chunk_size;
		$data = FilesystemHelper::read_bytes( $file, $index * $chunk_size, $chunk_size );

		if ( false === $data ) {
			return new WP_Error( 'mksddn_chunk_read', __( 'Unable to read chunk.', 'mksddn-migrate-content' ), array( 'status' => 500 ) );
		}

		$completed = ( $index + 1 ) >= ( $job->get_data()['total_chunks'] ?? PHP_INT_MAX );
		$response  = array(
			'chunk'     => base64_encode( $data ),
			'completed' => $completed,
		);

		if ( $completed ) {
			$job->delete();
		}

		return $response;
	}

	public function cancel_job( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		if ( empty( $job_id ) ) {
			return new WP_Error( 'mksddn_missing_job', __( 'Job ID is required.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$json_path = $this->repository->get_storage_dir() . $job_id . '.json';
		$file_path = $this->repository->get_storage_dir() . $job_id . '.tmp';
		
		// Log cancellation attempt.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( 'MksDdn Migrate Export: Cancellation requested for job %s', $job_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		
		// Delete files directly using native PHP functions for immediate effect.
		$deleted_json = false;
		$deleted_file = false;
		
		if ( file_exists( $json_path ) ) {
			// Clear stat cache before deletion.
			clearstatcache( true, $json_path );
			$deleted_json = @unlink( $json_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		
		if ( file_exists( $file_path ) ) {
			// Clear stat cache before deletion.
			clearstatcache( true, $file_path );
			$deleted_file = @unlink( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		
		// Clear stat cache after deletion.
		clearstatcache( true, $json_path );
		clearstatcache( true, $file_path );
		
		// Verify deletion.
		$still_exists = file_exists( $json_path );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( $still_exists ) {
				error_log( sprintf( 'MksDdn Migrate Export: Warning - Job %s file still exists after delete attempt (deleted_json: %s, deleted_file: %s)', $job_id, $deleted_json ? 'yes' : 'no', $deleted_file ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				error_log( sprintf( 'MksDdn Migrate Export: Job %s cancelled and deleted successfully', $job_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		return array(
			'deleted' => ! $still_exists,
		);
	}

	public function get_status( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		if ( empty( $job_id ) ) {
			return new WP_Error( 'mksddn_missing_job', __( 'Job ID is required.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$job = $this->repository->get( $job_id );
		return $job->get_data();
	}

	/**
	 * Get import status by history ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|WP_Error
	 * @since 1.0.0
	 */
	public function get_import_status( WP_REST_Request $request ): array|WP_Error {
		$history_id = sanitize_text_field( $request->get_param( 'history_id' ) );
		if ( empty( $history_id ) ) {
			return new WP_Error( 'mksddn_missing_id', __( 'History ID is required.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$entry = $this->history->find( $history_id );

		if ( ! $entry ) {
			return new WP_Error( 'mksddn_not_found', __( 'Import not found.', 'mksddn-migrate-content' ), array( 'status' => 404 ) );
		}

		return array(
			'status'   => $entry['status'] ?? 'unknown',
			'progress' => $entry['progress'] ?? array( 'percent' => 0, 'message' => '' ),
		);
	}
}

