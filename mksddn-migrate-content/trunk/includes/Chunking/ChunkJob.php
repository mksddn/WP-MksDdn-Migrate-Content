<?php
/**
 * Chunked job metadata storage.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents a chunked transfer job.
 */
class ChunkJob {

	private string $id;

	private string $dir;

	private array $data = array();

	public function __construct( string $id, string $dir ) {
		$this->id  = sanitize_key( $id );
		$this->dir = trailingslashit( $dir );
		$this->load();
	}

	public function get_data(): array {
		return $this->data;
	}

	public function update( array $payload ): void {
		$this->data = array_merge( $this->data, $payload );
		$this->save();
	}

	/**
	 * Update a job only when its current status matches one of the allowed values.
	 *
	 * The metadata file is locked while it is read and written to prevent a
	 * cancellation request from being overwritten by the background export worker.
	 *
	 * @param string|string[] $expected_status Allowed current status value(s).
	 * @param array           $payload         Values to persist.
	 * @return bool Whether the job was updated.
	 */
	public function update_if_status( $expected_status, array $payload ): bool {
		$path    = $this->dir . $this->id . '.json';
		$allowed = (array) $expected_status;
		$handle  = fopen( $path, 'c+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- an exclusive lock is required for atomic job status updates.

		if ( ! $handle || ! flock( $handle, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- paired with fopen to synchronize job state.
			if ( $handle ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen.
			}
			return false;
		}

		rewind( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- paired with fopen.
		$json = stream_get_contents( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_get_contents -- paired with fopen.
		$data = json_decode( $json ?: '', true );

		if ( ! is_array( $data ) || ! in_array( $data['status'] ?? '', $allowed, true ) ) {
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- paired with fopen.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen.
			return false;
		}

		$this->data = array_merge( $data, $payload );
		$encoded    = wp_json_encode( $this->data );

		if ( false === $encoded ) {
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- paired with fopen.
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen.
			return false;
		}

		rewind( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rewind -- paired with fopen.
		ftruncate( $handle, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- paired with fopen.
		$written = fwrite( $handle, $encoded ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- paired with fopen.
		fflush( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fflush -- paired with fopen.
		flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- paired with fopen.
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen.

		return false !== $written;
	}

	public function get_file_path(): string {
		return $this->dir . $this->id . '.tmp';
	}

	public function delete(): void {
		FilesystemHelper::delete( $this->dir . $this->id . '.json' );
		FilesystemHelper::delete( $this->get_file_path() );
	}

	private function load(): void {
		$path = $this->dir . $this->id . '.json';

		if ( file_exists( $path ) ) {
			$json       = FilesystemHelper::instance()->get_contents( $path );
			$this->data = json_decode( $json ?: '', true ) ?: array();
			return;
		}

		$this->data = array(
			'id'               => $this->id,
			'created_at'       => time(),
			'received_chunks'  => 0,
			'total_chunks'     => null,
			'completed'        => false,
			'checksum'         => '',
			'chunk_size'       => 5 * 1024 * 1024,
			'mode'             => 'upload',
			'size'             => 0,
			'status'           => '',
			'error'            => '',
		);

		$this->save();
	}

	private function save(): void {
		$path = $this->dir . $this->id . '.json';
		FilesystemHelper::put_contents( $path, wp_json_encode( $this->data ) ?: '{}' );
	}
}

