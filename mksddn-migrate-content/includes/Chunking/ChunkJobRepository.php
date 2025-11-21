<?php
/**
 * Job repository helper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Chunking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChunkJobRepository {

	private string $storage_dir;

	public function __construct() {
		$this->storage_dir = trailingslashit( WP_CONTENT_DIR ) . 'mksddn-migrate-jobs/';
		$this->ensure_storage_dir();
		$this->cleanup_expired();
	}

	public function get( string $job_id ): ChunkJob {
		return new ChunkJob( $job_id, $this->storage_dir );
	}

	public function create(): ChunkJob {
		$job_id = wp_generate_password( 20, false, false );
		return new ChunkJob( $job_id, $this->storage_dir );
	}

	private function ensure_storage_dir(): void {
		if ( ! is_dir( $this->storage_dir ) ) {
			wp_mkdir_p( $this->storage_dir );
		}
	}

	private function cleanup_expired( int $ttl = DAY_IN_SECONDS ): void {
		if ( ! is_dir( $this->storage_dir ) ) {
			return;
		}

		$files = glob( $this->storage_dir . '*.json' );
		if ( ! $files ) {
			return;
		}

		$cutoff = time() - $ttl;
		foreach ( $files as $file ) {
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			$created = isset( $data['created_at'] ) ? (int) $data['created_at'] : 0;
			if ( $created > 0 && $created >= $cutoff ) {
				continue;
			}

			$job_id = basename( $file, '.json' );
			( new ChunkJob( $job_id, $this->storage_dir ) )->delete();
		}
	}
}

