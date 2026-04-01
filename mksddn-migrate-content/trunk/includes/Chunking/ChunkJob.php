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

	public function get_file_path(): string {
		return $this->dir . $this->id . '.tmp';
	}

	/**
	 * Temporary JSON payload while building resumable full-site export.
	 *
	 * @return string Absolute path.
	 * @since 2.1.5
	 */
	public function get_export_payload_temp_path(): string {
		return $this->dir . $this->id . '-export-payload.json';
	}

	/**
	 * NDJSON file list for resumable zip (one file entry per line).
	 *
	 * @return string Absolute path.
	 * @since 2.1.5
	 */
	public function get_export_file_list_path(): string {
		return $this->dir . $this->id . '-export-files.json';
	}

	public function delete(): void {
		$runner = $this->data['export_runner_state'] ?? null;
		if ( is_array( $runner ) && ! empty( $runner['zip_disk_path'] ) && is_string( $runner['zip_disk_path'] ) ) {
			$alt = $runner['zip_disk_path'];
			if ( $alt !== $this->get_file_path() && file_exists( $alt ) ) {
				FilesystemHelper::delete( $alt );
			}
		}

		FilesystemHelper::delete( $this->dir . $this->id . '.json' );
		FilesystemHelper::delete( $this->get_file_path() );
		FilesystemHelper::delete( $this->get_export_payload_temp_path() );
		FilesystemHelper::delete( $this->get_export_file_list_path() );
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
		);

		$this->save();
	}

	private function save(): void {
		$path = $this->dir . $this->id . '.json';
		FilesystemHelper::put_contents( $path, wp_json_encode( $this->data ) ?: '{}' );
	}
}

