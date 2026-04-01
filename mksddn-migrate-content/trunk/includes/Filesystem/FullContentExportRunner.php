<?php
/**
 * @file: FullContentExportRunner.php
 * @description: Time-sliced full-site .wpbkp builder and synchronous export wrapper.
 * @dependencies: FullDatabaseExporter, ContentCollector, ChunkJob, PluginConfig, FilesystemHelper
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Chunking\ChunkJob;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Support\ExportMemoryHelper;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the same .wpbkp layout as legacy export using resumable steps.
 */
class FullContentExportRunner {

	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

	private FullDatabaseExporter $db_exporter;

	private ContentCollector $collector;

	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
	}

	/**
	 * Single-request style export (no HTTP chunking).
	 *
	 * @param string $target_path Absolute path to output zip.
	 * @return string|WP_Error Path on success.
	 */
	public function export_synchronous( string $target_path ): string|WP_Error {
		$payload_path = dirname( $target_path ) . '/' . wp_unique_filename( dirname( $target_path ), 'mksddn-payload.json' );
		$list_path    = dirname( $target_path ) . '/' . wp_unique_filename( dirname( $target_path ), 'mksddn-files.ndjson' );

		$state = $this->fresh_state( $payload_path, $list_path );

		while ( true ) {
			$result = $this->execute_step( $state, $target_path, PHP_FLOAT_MAX );
			if ( is_wp_error( $result ) ) {
				$this->cleanup_paths(
					array_filter(
						array(
							$payload_path,
							$list_path,
							isset( $state['zip_disk_path'] ) ? (string) $state['zip_disk_path'] : '',
							$target_path,
						)
					)
				);
				return $result;
			}
			if ( ! empty( $result['done'] ) ) {
				$this->cleanup_paths( array( $payload_path, $list_path ) );
				return $target_path;
			}
		}
	}

	/**
	 * One time-bounded step for resumable REST export.
	 *
	 * @param ChunkJob $job         Job (state in export_runner_state).
	 * @param string   $target_path Final zip path (job .tmp).
	 * @param float    $deadline    microtime( true ) deadline.
	 * @return array<string, mixed>|WP_Error
	 */
	public function run_step_for_job( ChunkJob $job, string $target_path, float $deadline ): array|WP_Error {
		$data  = $job->get_data();
		$state = isset( $data['export_runner_state'] ) && is_array( $data['export_runner_state'] )
			? $data['export_runner_state']
			: $this->fresh_state( $job->get_export_payload_temp_path(), $job->get_export_file_list_path() );

		$result = $this->execute_step( $state, $target_path, $deadline );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! empty( $result['done'] ) ) {
			$job->update(
				array(
					'export_runner_state' => null,
					'mode'                => 'download',
					'total_chunks'        => $result['total_chunks'],
					'chunk_size'          => $result['chunk_size'],
					'size'                => $result['size'],
				)
			);
		} else {
			$job->update(
				array(
					'export_runner_state' => $state,
					'mode'                => 'export_building',
				)
			);
		}

		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fresh_state( string $payload_path, string $list_path ): array {
		return array(
			'phase'        => 'db',
			'payload_path' => $payload_path,
			'list_path'    => $list_path,
			// When set, zip is built here (e.g. sys temp) and copied to target_path when done.
			'zip_disk_path' => null,
			'db'           => array(
				'tables'         => null,
				'ti'             => 0,
				'row_off'        => 0,
				'first_table'    => true,
				'in_rows'        => false,
				'first_row'      => true,
				'header_written' => false,
			),
			'fs'           => array(
				'roots'   => null,
				'ri'      => 0,
				'stack'   => array(),
				'started' => false,
				'root_paths'  => array(),
				'root_counts' => array(),
			),
			'zip'          => array(
				'add_idx' => 0,
			),
		);
	}

	/**
	 * @param array<string, mixed> $state State (by ref).
	 * @return array<string, mixed>|WP_Error
	 */
	private function execute_step( array &$state, string $target_path, float $deadline ): array|WP_Error {
		$original_memory = ExportMemoryHelper::raise_for_export();
		try {
			$dir_result = FilesystemHelper::ensure_directory( $target_path );
			if ( is_wp_error( $dir_result ) ) {
				return $dir_result;
			}

			while ( microtime( true ) < $deadline ) {
				$phase = $state['phase'];
				if ( 'db' === $phase ) {
					$err = $this->phase_db( $state, $deadline );
					if ( is_wp_error( $err ) ) {
						return $err;
					}
					if ( 'db' === $state['phase'] ) {
						continue;
					}
					continue;
				}
				if ( 'zip_base' === $phase ) {
					$err = $this->phase_zip_base( $state, $target_path );
					if ( is_wp_error( $err ) ) {
						return $err;
					}
					continue;
				}
				if ( 'fs_build' === $phase ) {
					$err = $this->phase_fs_build( $state, $deadline );
					if ( is_wp_error( $err ) ) {
						return $err;
					}
					if ( 'fs_build' === $state['phase'] ) {
						continue;
					}
					continue;
				}
				if ( 'zip_files' === $phase ) {
					$err = $this->phase_zip_files( $state, $target_path, $deadline );
					if ( is_wp_error( $err ) ) {
						return $err;
					}
					if ( 'zip_files' === $state['phase'] ) {
						continue;
					}
					continue;
				}
				if ( 'done' === $phase ) {
					$finalize = $this->finalize_zip_to_job_path( $state, $target_path );
					if ( is_wp_error( $finalize ) ) {
						return $finalize;
					}
					$size = filesize( $target_path );
					if ( false === $size || $size < 1 ) {
						return new WP_Error( 'mksddn_chunk_size', __( 'Export file is empty or cannot be read.', 'mksddn-migrate-content' ) );
					}
					$chunk_size = 5242880;
					$total      = (int) max( 1, ceil( $size / $chunk_size ) );

					return array(
						'done'           => true,
						'progress'       => 1.0,
						'total_chunks'   => $total,
						'chunk_size'     => $chunk_size,
						'size'           => $size,
					);
				}
				break;
			}

			return array(
				'done'     => false,
				'progress' => $this->estimate_progress( $state ),
				'message'  => '',
			);
		} finally {
			ExportMemoryHelper::restore( $original_memory );
		}
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function estimate_progress( array $state ): float {
		$phase = $state['phase'];
		if ( 'db' === $phase ) {
			$db = $state['db'];
			$tables = is_array( $db['tables'] ) ? $db['tables'] : array();
			$n      = count( $tables );
			if ( $n < 1 ) {
				return 0.05;
			}
			$t = ( (float) $db['ti'] ) / (float) $n;
			return min( 0.45, max( 0.05, $t * 0.45 ) );
		}
		if ( 'zip_base' === $phase ) {
			return 0.5;
		}
		if ( 'fs_build' === $phase ) {
			return 0.55;
		}
		if ( 'zip_files' === $phase ) {
			$path = $state['list_path'];
			if ( ! is_readable( $path ) ) {
				return 0.6;
			}
			$total = isset( $state['zip']['total_lines'] ) ? (int) $state['zip']['total_lines'] : 0;
			$idx   = isset( $state['zip']['add_idx'] ) ? (int) $state['zip']['add_idx'] : 0;
			if ( $total < 1 ) {
				return 0.7;
			}
			return min( 0.95, 0.6 + ( $idx / $total ) * 0.35 );
		}
		return 0.5;
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function phase_db( array &$state, float $deadline ): true|WP_Error {
		$payload_path = $state['payload_path'];
		$db           = &$state['db'];

		if ( null === $db['tables'] ) {
			$db['tables'] = $this->db_exporter->get_table_names();
		}

		if ( empty( $db['header_written'] ) ) {
			$has_payload = is_readable( $payload_path ) && filesize( $payload_path ) > 0;
			if ( $has_payload ) {
				$db['header_written'] = true;
			} else {
				$err = $this->write_db_payload_header( $payload_path );
				if ( is_wp_error( $err ) ) {
					return $err;
				}
				$db['header_written'] = true;
			}
		}

		$tables = $db['tables'];
		$row_chunk = max( 50, PluginConfig::db_row_chunk_size() );

		while ( $db['ti'] < count( $tables ) && microtime( true ) < $deadline ) {
			$table = $tables[ $db['ti'] ];

			if ( 0 === (int) $db['row_off'] && ! $db['in_rows'] ) {
				if ( ! $db['first_table'] ) {
					$this->payload_append( $payload_path, ',' );
				}
				$db['first_table'] = false;
				$schema            = $this->db_exporter->get_create_table_ddl( $table );
				$key               = wp_json_encode( $table, self::JSON_FLAGS );
				if ( false === $key ) {
					return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
				}
				$schema_json = wp_json_encode( $schema, self::JSON_FLAGS );
				$this->payload_append( $payload_path, $key . ':{"schema":' . $schema_json . ',"rows":[' );
				$db['in_rows']   = true;
				$db['first_row'] = true;
			}

			$rows = $this->db_exporter->fetch_rows_slice( $table, (int) $db['row_off'], $row_chunk );
			if ( null === $rows ) {
				return new WP_Error( 'mksddn_db_export', __( 'Database export failed while reading a table.', 'mksddn-migrate-content' ) );
			}
			if ( array() === $rows ) {
				$this->payload_append( $payload_path, ']}' );
				$db['in_rows']   = false;
				$db['first_row'] = true;
				$db['row_off']   = 0;
				++$db['ti'];
				continue;
			}

			foreach ( $rows as $row ) {
				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
				if ( ! $db['first_row'] ) {
					$this->payload_append( $payload_path, ',' );
				}
				$db['first_row'] = false;
				$enc              = wp_json_encode( $row, self::JSON_FLAGS );
				if ( false === $enc ) {
					return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
				}
				$this->payload_append( $payload_path, $enc );
				++$db['row_off'];
			}
		}

		if ( $db['ti'] >= count( $tables ) ) {
			$this->payload_append( $payload_path, '}}}' );
			$state['phase'] = 'zip_base';
		}

		return true;
	}

	/**
	 * Opening fragment: {"type":"full-site","database":{...,"tables":{
	 */
	private function write_db_payload_header( string $payload_path ): true|WP_Error {
		global $wpdb;

		$uploads = wp_upload_dir();
		$inner   = array(
			'site_url'     => get_option( 'siteurl' ),
			'home_url'     => home_url(),
			'table_prefix' => $wpdb->prefix,
			'paths'        => array(
				'root'    => function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH,
				'content' => WP_CONTENT_DIR,
				'uploads' => $uploads['basedir'],
			),
		);

		$enc = wp_json_encode( $inner, self::JSON_FLAGS );
		if ( false === $enc ) {
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
		}

		$body = substr( $enc, 0, -1 ) . ',"tables":{';
		$data = '{"type":"full-site","database":' . $body;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append not in FilesystemHelper
		if ( false === file_put_contents( $payload_path, $data, LOCK_EX ) ) {
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
		}

		return true;
	}

	private function payload_append( string $path, string $data ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, $data, FILE_APPEND | LOCK_EX );
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function phase_zip_base( array &$state, string $target_path ): WP_Error|true {
		$payload_path = $state['payload_path'];

		if ( ! is_readable( $payload_path ) || filesize( $payload_path ) < 2 ) {
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'PHP Zip extension is not available on this server.', 'mksddn-migrate-content' ) );
		}

		$manifest = array(
			'format_version' => 1,
			'plugin_version' => MKSDDN_MC_VERSION,
			'type'           => 'full-site',
			'created_at_gmt' => gmdate( 'c' ),
			'export_runner_build' => '2026-04-01-fs-scan-fix',
		);

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $manifest_json ) {
			return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
		}

		$zip_path = $this->resolve_zip_disk_path( $state, $target_path );
		$zip      = new ZipArchive();

		if ( ! $this->open_zip_new( $zip, $zip_path ) ) {
			if ( ! isset( $state['zip_disk_path'] ) ) {
				$tmp = wp_tempnam( 'mksddn-full-' );
				if ( ! $tmp ) {
					return $this->zip_open_error( $zip );
				}
				if ( file_exists( $tmp ) ) {
					FilesystemHelper::delete( $tmp );
				}
				$state['zip_disk_path'] = wp_normalize_path( $tmp );
				$zip                    = new ZipArchive();
				if ( ! $this->open_zip_new( $zip, $state['zip_disk_path'] ) ) {
					return $this->zip_open_error( $zip );
				}
			} else {
				return $this->zip_open_error( $zip );
			}
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFile( $payload_path, 'payload/content.json' );
		$zip->close();

		FilesystemHelper::delete( $payload_path );

		$state['phase'] = 'fs_build';
		return true;
	}

	/**
	 * Path where the .wpbkp zip is stored during the build (may differ from job .tmp).
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function resolve_zip_disk_path( array $state, string $target_path ): string {
		if ( ! empty( $state['zip_disk_path'] ) && is_string( $state['zip_disk_path'] ) ) {
			return wp_normalize_path( $state['zip_disk_path'] );
		}

		return wp_normalize_path( $target_path );
	}

	/**
	 * Create or truncate a zip for writing manifest + payload.
	 */
	private function open_zip_new( ZipArchive $zip, string $path ): bool {
		$path = wp_normalize_path( $path );
		if ( file_exists( $path ) ) {
			FilesystemHelper::delete( $path );
		}

		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$result = $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

		return true === $result;
	}

	/**
	 * Open existing zip for adding files (must not use default flags — that is read-only).
	 */
	private function open_zip_append( ZipArchive $zip, string $path ): bool {
		$path = wp_normalize_path( $path );

		if ( ! file_exists( $path ) || filesize( $path ) < 1 ) {
			return false;
		}

		$result = $zip->open( $path, ZipArchive::CREATE );

		return true === $result;
	}

	private function zip_open_error( ZipArchive $zip ): WP_Error {
		$detail = '';
		if ( method_exists( $zip, 'getStatusString' ) ) {
			$detail = $zip->getStatusString();
		}
		if ( '' === $detail && property_exists( $zip, 'status' ) ) {
			$detail = (string) $zip->status;
		}

		$message = __( 'Unable to create archive for full export.', 'mksddn-migrate-content' );
		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		return new WP_Error( 'mksddn_zip_open', $message );
	}

	/**
	 * Copy zip from temp path to job .tmp when the build used a fallback disk path.
	 *
	 * @param array<string, mixed> $state State.
	 */
	private function finalize_zip_to_job_path( array &$state, string $target_path ): true|WP_Error {
		$target_path = wp_normalize_path( $target_path );

		if ( empty( $state['zip_disk_path'] ) || ! is_string( $state['zip_disk_path'] ) ) {
			return true;
		}

		$src = wp_normalize_path( $state['zip_disk_path'] );
		if ( $src === $target_path ) {
			return true;
		}

		if ( ! is_readable( $src ) || filesize( $src ) < 1 ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Export archive is missing before finalize.', 'mksddn-migrate-content' ) );
		}

		FilesystemHelper::delete( $target_path );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- copy fallback
		if ( ! @copy( $src, $target_path ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to copy export archive to the job file.', 'mksddn-migrate-content' ) );
		}

		FilesystemHelper::delete( $src );
		$state['zip_disk_path'] = null;

		return true;
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function phase_fs_build( array &$state, float $deadline ): true|WP_Error {
		$list_path = $state['list_path'];
		$fs        = &$state['fs'];

		$list_dir = FilesystemHelper::ensure_directory( $list_path );
		if ( is_wp_error( $list_dir ) ) {
			return $list_dir;
		}

		if ( null === $fs['roots'] ) {
			$map = FullContentExporter::build_content_directory_map( 'files' );
			$roots = array();

			$required_archive_roots = array(
				'files/wp-content/uploads',
				'files/wp-content/plugins',
				'files/wp-content/themes',
			);

			foreach ( $map as $archive_root => $real_path ) {
				$archive_root = trim( (string) $archive_root, '/' );
				$real_path    = wp_normalize_path( (string) $real_path );

				if ( '' === $real_path ) {
					continue;
				}

				$exists = file_exists( $real_path );
				$is_dir = is_dir( $real_path );

				if ( in_array( $archive_root, $required_archive_roots, true ) ) {
					if ( ! $exists || ! $is_dir ) {
						return new WP_Error(
							'mksddn_mc_export_root_missing',
							sprintf(
								/* translators: 1: archive root (e.g. files/wp-content/plugins), 2: real filesystem path */
								__( 'Required export root is unavailable: %1$s -> %2$s', 'mksddn-migrate-content' ),
								$archive_root,
								$real_path
							),
							array(
								'archive_root' => $archive_root,
								'path'         => $real_path,
							)
						);
					}
					if ( ! is_readable( $real_path ) ) {
						return new WP_Error(
							'mksddn_mc_export_root_unreadable',
							sprintf(
								/* translators: 1: archive root (e.g. files/wp-content/plugins), 2: real filesystem path */
								__( 'Required export root is not readable: %1$s -> %2$s', 'mksddn-migrate-content' ),
								$archive_root,
								$real_path
							),
							array(
								'archive_root' => $archive_root,
								'path'         => $real_path,
							)
						);
					}
				}

				if ( is_dir( $real_path ) ) {
					$roots[] = array(
						'a' => $archive_root,
						'r' => $real_path,
					);
					$fs['root_paths'][ $archive_root ]  = $real_path;
					$fs['root_counts'][ $archive_root ] = 0;
				}
			}
			$fs['roots'] = $roots;
		}

		if ( ! $fs['started'] ) {
			FilesystemHelper::delete( $list_path );
			$fs['started'] = true;
		}

		$scan_max = PluginConfig::full_export_fs_scan_per_step();
		$count    = 0;

		while ( microtime( true ) < $deadline ) {
			if ( $fs['ri'] >= count( $fs['roots'] ) && empty( $fs['stack'] ) ) {
				$touch = $this->ensure_export_file_list( $list_path );
				if ( is_wp_error( $touch ) ) {
					return $touch;
				}
				$state['phase'] = 'zip_files';
				$lines          = $this->count_lines_ndjson( $list_path );
				$state['zip']['total_lines'] = $lines;
				$state['zip']['add_idx']     = 0;
				return true;
			}

			if ( empty( $fs['stack'] ) ) {
				$root = $fs['roots'][ $fs['ri'] ];
				++$fs['ri'];
				$fs['stack'][] = array(
					'path'    => $root['r'],
					'archive' => $root['a'],
					'root'    => $root['a'],
				);
			}

			$frame = array_pop( $fs['stack'] );
			if ( ! is_dir( $frame['path'] ) ) {
				continue;
			}

			$items = @scandir( $frame['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- detailed error returned below
			if ( false === $items ) {
				return new WP_Error(
					'mksddn_mc_export_scandir',
					sprintf(
						/* translators: %s: Absolute directory path. */
						__( 'Cannot read directory while exporting: %s', 'mksddn-migrate-content' ),
						$frame['path']
					),
					array( 'path' => $frame['path'] )
				);
			}

			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}

				$full = $frame['path'] . DIRECTORY_SEPARATOR . $item;
				if ( $this->should_skip_fs_path( $full ) ) {
					continue;
				}

				$rel_archive = $frame['archive'] . '/' . $item;
				$rel_archive = trim( str_replace( '\\', '/', $rel_archive ), '/' );

				if ( is_dir( $full ) ) {
					$fs['stack'][] = array(
						'path'    => $full,
						'archive' => $rel_archive,
						'root'    => isset( $frame['root'] ) ? $frame['root'] : $frame['archive'],
					);
				} else {
					$line = wp_json_encode(
						array(
							'a' => $rel_archive,
							'r' => $full,
						),
						self::JSON_FLAGS
					);
					if ( false === $line ) {
						return new WP_Error( 'mksddn_mc_full_export_payload', __( 'Failed to encode full-site payload.', 'mksddn-migrate-content' ) );
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $list_path, $line . "\n", FILE_APPEND | LOCK_EX );
					$root = isset( $frame['root'] ) ? (string) $frame['root'] : (string) $frame['archive'];
					if ( isset( $fs['root_counts'][ $root ] ) ) {
						++$fs['root_counts'][ $root ];
					}
					++$count;
				}
			}

			// Stop only after finishing the current directory frame, otherwise
			// resumable scan may lose the rest of that directory between steps.
			if ( $count >= $scan_max && microtime( true ) >= $deadline - 0.01 ) {
				break;
			}
		}

		return true;
	}

	/**
	 * Ensures the NDJSON parent directory exists and the list file exists (empty if missing).
	 *
	 * @param string $list_path Absolute path to the file list.
	 * @return true|WP_Error
	 */
	private function ensure_export_file_list( string $list_path ): true|WP_Error {
		$dir_result = FilesystemHelper::ensure_directory( $list_path );
		if ( is_wp_error( $dir_result ) ) {
			return $dir_result;
		}
		if ( is_file( $list_path ) ) {
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = file_put_contents( $list_path, '', LOCK_EX );
		if ( false === $ok ) {
			return new WP_Error(
				'mksddn_mc_full_export_list',
				__( 'Unable to write full-site file list.', 'mksddn-migrate-content' ),
				array( 'path' => $list_path )
			);
		}

		return true;
	}

	private function should_skip_fs_path( string $path ): bool {
		$ignored = array(
			'/mksddn-mc/',
			'/mksddn-migrate-jobs/',
			'/mksddn-migrate-jobs-legacy/',
			'/.git/',
			'/.svn/',
			'/.hg/',
			'/.DS_Store',
		);

		foreach ( $ignored as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return 'wpbkp' === $extension;
	}

	private function count_lines_ndjson( string $path ): int {
		if ( ! is_readable( $path ) ) {
			return 0;
		}
		$n = 0;
		$h = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- counting lines
		if ( false === $h ) {
			return 0;
		}
		while ( ! feof( $h ) ) {
			$line = fgets( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			if ( false !== $line && '' !== trim( $line ) ) {
				++$n;
			}
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $n;
	}

	/**
	 * @param array<string, mixed> $state State.
	 */
	private function phase_zip_files( array &$state, string $target_path, float $deadline ): true|WP_Error {
		$list_path = $state['list_path'];
		$idx       = (int) $state['zip']['add_idx'];

		$zip_path = $this->resolve_zip_disk_path( $state, $target_path );

		$zip = new ZipArchive();
		if ( ! $this->open_zip_append( $zip, $zip_path ) ) {
			return $this->zip_open_error( $zip );
		}

		$list_ok = $this->ensure_export_file_list( $list_path );
		if ( is_wp_error( $list_ok ) ) {
			$zip->close();
			return $list_ok;
		}

		$h = fopen( $list_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $h ) {
			$zip->close();
			return new WP_Error(
				'mksddn_mc_full_export_list',
				__( 'Unable to read full-site file list.', 'mksddn-migrate-content' ),
				array( 'path' => $list_path )
			);
		}

		$current = 0;
		while ( ! feof( $h ) && $current < $idx ) {
			fgets( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			++$current;
		}

		$added = 0;
		// Add until EOF or wall-clock deadline — do not cap at N files per call (that caused
		// truncated archives when the outer request budget ended mid-batch).
		while ( ! feof( $h ) && microtime( true ) < $deadline ) {
			$line = fgets( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			if ( false === $line || '' === trim( $line ) ) {
				break;
			}
			$entry = json_decode( trim( $line ), true );
			if ( ! is_array( $entry ) || empty( $entry['a'] ) || empty( $entry['r'] ) || ! is_file( $entry['r'] ) ) {
				++$idx;
				continue;
			}
			if ( ! is_readable( $entry['r'] ) ) {
				$zip->close();
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'mksddn_mc_export_unreadable_file',
					sprintf(
						/* translators: %s: Absolute file path. */
						__( 'Cannot read file while building full export: %s', 'mksddn-migrate-content' ),
						$entry['r']
					),
					array( 'path' => $entry['r'] )
				);
			}
			if ( ! $this->collector->add_file_to_zip( $zip, $entry['a'], $entry['r'] ) ) {
				$zip->close();
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return new WP_Error(
					'mksddn_mc_export_zip_add',
					sprintf(
						/* translators: 1: Source path, 2: archive path inside zip. */
						__( 'Failed to add file to export archive: %1$s -> %2$s', 'mksddn-migrate-content' ),
						$entry['r'],
						$entry['a']
					),
					array(
						'path'         => $entry['r'],
						'archive_path' => $entry['a'],
					)
				);
			}
			++$idx;
			++$added;
		}

		$eof = feof( $h );
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$zip->close();

		$state['zip']['add_idx'] = $idx;

		if ( $eof ) {
			$this->append_export_debug_file( $state, $zip_path );
			FilesystemHelper::delete( $list_path );
			$state['phase'] = 'done';
			$finalize         = $this->finalize_zip_to_job_path( $state, $target_path );
			if ( is_wp_error( $finalize ) ) {
				return $finalize;
			}
		}

		return true;
	}

	/**
	 * Add internal export diagnostics for troubleshooting missing roots in archives.
	 *
	 * @param array<string, mixed> $state Current export state.
	 * @param string               $zip_path Absolute zip path.
	 */
	private function append_export_debug_file( array $state, string $zip_path ): void {
		$fs_state = isset( $state['fs'] ) && is_array( $state['fs'] ) ? $state['fs'] : array();
		$zip_st   = isset( $state['zip'] ) && is_array( $state['zip'] ) ? $state['zip'] : array();
		$debug    = array(
			'build'        => '2026-04-01-fs-root-debug',
			'generated_at' => gmdate( 'c' ),
			'root_paths'   => isset( $fs_state['root_paths'] ) && is_array( $fs_state['root_paths'] ) ? $fs_state['root_paths'] : array(),
			'root_counts'  => isset( $fs_state['root_counts'] ) && is_array( $fs_state['root_counts'] ) ? $fs_state['root_counts'] : array(),
			'zip'          => array(
				'add_idx'     => isset( $zip_st['add_idx'] ) ? (int) $zip_st['add_idx'] : 0,
				'total_lines' => isset( $zip_st['total_lines'] ) ? (int) $zip_st['total_lines'] : 0,
			),
		);

		$json = wp_json_encode( $debug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return;
		}

		$zip = new ZipArchive();
		if ( ! $this->open_zip_append( $zip, $zip_path ) ) {
			return;
		}
		$zip->addFromString( 'payload/export-debug.json', $json );
		$zip->close();
	}

	/**
	 * @param array<string, string> $paths Absolute paths.
	 */
	private function cleanup_paths( array $paths ): void {
		foreach ( $paths as $p ) {
			if ( $p && file_exists( $p ) ) {
				FilesystemHelper::delete( $p );
			}
		}
	}
}
