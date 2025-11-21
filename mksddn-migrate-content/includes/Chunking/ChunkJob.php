<?php
/**
 * Chunked job metadata storage.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Chunking;

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

	public function delete(): void {
		@unlink( $this->dir . $this->id . '.json' );
		@unlink( $this->get_file_path() );
	}

	private function load(): void {
		wp_mkdir_p( $this->dir );
		$path = $this->dir . $this->id . '.json';

		if ( file_exists( $path ) ) {
			$json       = file_get_contents( $path ); // phpcs:ignore
			$this->data = json_decode( $json, true ) ?: array();
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
		file_put_contents( $path, wp_json_encode( $this->data ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
	}
}

