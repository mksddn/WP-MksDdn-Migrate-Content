<?php
/**
 * REST controller for chunk upload/download.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Contracts\ChunkJobRepositoryInterface;
use MksDdn\MigrateContent\Services\PluginLogger;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChunkRestController {

	private ChunkJobRepositoryInterface $repository;

	private int $chunk_size = 5242880; // 5 MB.

	/**
	 * Constructor.
	 *
	 * @param ChunkJobRepositoryInterface $repository Chunk job repository.
	 * @since 1.0.0
	 */
	public function __construct( ChunkJobRepositoryInterface $repository ) {
		$this->repository = $repository;
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
			'/chunk/ping',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => array( $this, 'ensure_permission' ),
			)
		);

	}

	/**
	 * Lightweight health check for chunked transfer REST routes.
	 *
	 * @return array{ok: bool}
	 */
	public function ping(): array {
		return array(
			'ok' => true,
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

	/**
	 * Create a download job and schedule the archive build in the background.
	 *
	 * The heavy work (DB dump + wp-content zip) happens outside this request via
	 * WP-Cron, so the response returns immediately and never triggers a
	 * reverse-proxy/PHP-FPM read timeout on large sites. The client polls
	 * `chunk/status` until the job becomes `ready`, then downloads chunks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array{job_id:string,status:string}
	 */
	public function init_download( WP_REST_Request $request ) {
		$job = $this->repository->create();

		$job->update(
			array(
				'mode'       => 'download',
				'status'     => 'pending',
				'chunk_size' => $this->chunk_size,
			)
		);

		$job_id = $job->get_data()['id'];

		$scheduled = wp_schedule_single_event( time(), FullExportBuilder::CRON_HOOK, array( $job_id ), true );
		if ( true !== $scheduled ) {
			$message = is_wp_error( $scheduled )
				? $scheduled->get_error_message()
				: __( 'WordPress could not schedule the export task.', 'mksddn-migrate-content' );

			$job->delete();
			PluginLogger::log( 'Failed to schedule full export build: ' . $message, 'ChunkRestController' );

			return new WP_Error(
				'mksddn_export_schedule_failed',
				__( 'Could not start the background export. Please check that WordPress Cron is available and try again.', 'mksddn-migrate-content' ),
				array( 'status' => 500 )
			);
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return array(
			'job_id' => $job_id,
			'status' => 'pending',
		);
	}

	public function download_chunk( WP_REST_Request $request ) {
		$job_id = sanitize_text_field( $request->get_param( 'job_id' ) );
		$index  = absint( $request->get_param( 'index' ) );

		if ( empty( $job_id ) ) {
			return new WP_Error( 'mksddn_missing_job', __( 'Job ID is required.', 'mksddn-migrate-content' ), array( 'status' => 400 ) );
		}

		$job   = $this->repository->get( $job_id );
		$data  = $job->get_data();
		$file  = $job->get_file_path();
		$total = (int) ( $data['total_chunks'] ?? 0 );

		if ( 'download' === ( $data['mode'] ?? '' ) && 'ready' !== ( $data['status'] ?? '' ) ) {
			return new WP_Error( 'mksddn_job_not_ready', __( 'Export archive is not ready yet.', 'mksddn-migrate-content' ), array( 'status' => 409 ) );
		}

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

		$job = $this->repository->get( $job_id );

		$data = $job->get_data();
		if ( 'download' === ( $data['mode'] ?? '' ) && in_array( $data['status'] ?? '', array( 'pending', 'building' ), true ) ) {
			if ( $job->update_if_status( array( 'pending', 'building' ), array( 'status' => 'cancelled' ) ) ) {
				return array(
					'cancelled' => true,
				);
			}

			$job = $this->repository->get( $job_id );
		}

		$job->delete();

		return array(
			'deleted' => true,
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

}

