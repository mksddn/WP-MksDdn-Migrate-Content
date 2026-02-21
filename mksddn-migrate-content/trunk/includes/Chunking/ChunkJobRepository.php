<?php
/**
 * Job repository helper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Contracts\ChunkJobRepositoryInterface;
use MksDdn\MigrateContent\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ChunkJobRepository implements ChunkJobRepositoryInterface {

	private string $storage_dir;

	public function __construct() {
		$dirs            = PluginConfig::get_required_directories();
		$this->storage_dir = $dirs['jobs'];

		if ( ! is_dir( $this->storage_dir ) && ! wp_mkdir_p( $this->storage_dir ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( 'MksDdn Migrate Content: Failed to create directory: %s', $this->storage_dir ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}

		$this->cleanup_expired();
	}

	public function get( string $job_id ): ChunkJob {
		return new ChunkJob( $job_id, $this->storage_dir );
	}

	public function create(): ChunkJob {
		$job_id = wp_generate_password( 20, false, false );
		return new ChunkJob( $job_id, $this->storage_dir );
	}

	/**
	 * Remove expired job files and orphan .tmp files.
	 *
	 * @param int $ttl Time-to-live in seconds. Jobs older than this are deleted.
	 * @return void
	 */
	private function cleanup_expired( int $ttl = DAY_IN_SECONDS ): void {
		if ( ! is_dir( $this->storage_dir ) ) {
			return;
		}

		$dir = trailingslashit( $this->storage_dir );
		$json_files = glob( $dir . '*.json' );
		if ( false === $json_files ) {
			$json_files = array();
		}

		$cutoff = time() - $ttl;
		foreach ( $json_files as $file ) {
			$json = FilesystemHelper::instance()->get_contents( $file );
			$data = is_string( $json ) ? json_decode( $json, true ) : null;
			$created = ( isset( $data['created_at'] ) && is_numeric( $data['created_at'] ) ) ? (int) $data['created_at'] : 0;
			if ( $created > 0 && $created >= $cutoff ) {
				continue;
			}

			$job_id = basename( $file, '.json' );
			( new ChunkJob( $job_id, $this->storage_dir ) )->delete();
		}

		$tmp_files = glob( $dir . '*.tmp' );
		if ( false !== $tmp_files ) {
			foreach ( $tmp_files as $tmp_path ) {
				$job_id = basename( $tmp_path, '.tmp' );
				if ( ! file_exists( $dir . $job_id . '.json' ) ) {
					FilesystemHelper::delete( $tmp_path );
				}
			}
		}
	}
}

