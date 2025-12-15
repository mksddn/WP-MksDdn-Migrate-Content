<?php
/**
 * Handles creation/listing/removal of recovery snapshots.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Recovery;

use MksDdn\MigrateContent\Contracts\SnapshotManagerInterface;
use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Filesystem\ContentCollector;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages snapshot archives stored under uploads.
 */
class SnapshotManager implements SnapshotManagerInterface {

	private const DEFAULT_RETENTION = 3;

	private FullDatabaseExporter $db_exporter;
	private ContentCollector $collector;
	private string $base_dir;
	private int $retention;

	/**
	 * Setup manager.
	 *
	 * @param FullDatabaseExporter|null $db_exporter Optional DB exporter.
	 * @param ContentCollector|null     $collector   Optional collector.
	 * @param string|null               $base_dir    Optional base directory.
	 * @param int                       $retention   Max snapshots to keep.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null, ?string $base_dir = null, int $retention = self::DEFAULT_RETENTION ) {
		$upload_dir   = wp_upload_dir();
		$this->base_dir = $base_dir ?? trailingslashit( $upload_dir['basedir'] ) . 'mksddn-mc/snapshots';
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
		$this->retention   = max( 1, $retention );
	}

	/**
	 * Create snapshot archive.
	 *
	 * @param array $args Snapshot options.
	 * @return array|WP_Error Metadata or error.
	 */
	public function create( array $args = array() ): array|WP_Error {
		$defaults = array(
			'label'            => 'pre-import',
			'include_plugins'  => false,
			'include_themes'   => false,
			'meta'             => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		if ( ! wp_mkdir_p( $this->base_dir ) ) {
			return new WP_Error( 'mksddn_snapshot_dir', __( 'Unable to prepare snapshot directory.', 'mksddn-migrate-content' ) );
		}

		$id          = gmdate( 'YmdHis' ) . '-' . wp_generate_uuid4();
		$dir         = trailingslashit( $this->base_dir ) . $id;
		$archive     = $dir . '/snapshot.wpbkp';
		$meta_file   = $dir . '/snapshot.json';
		$created_at  = gmdate( 'c' );
		$include_map = $this->map_targets(
			(bool) $args['include_plugins'],
			(bool) $args['include_themes']
		);

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'mksddn_snapshot_dir', __( 'Unable to create snapshot folder.', 'mksddn-migrate-content' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'mksddn_snapshot_zip', __( 'Unable to create snapshot archive.', 'mksddn-migrate-content' ) );
		}

		$payload = array(
			'type'     => 'snapshot',
			'database' => $this->db_exporter->export(),
		);

		$manifest = array(
			'format_version' => 1,
			'plugin_version' => MKSDDN_MC_VERSION,
			'type'           => 'snapshot',
			'label'          => sanitize_text_field( $args['label'] ),
			'created_at_gmt' => $created_at,
			'includes'       => array_keys( $include_map ),
		);

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$payload_json  = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		if ( false === $manifest_json || false === $payload_json ) {
			$zip->close();
			return new WP_Error( 'mksddn_snapshot_payload', __( 'Failed to encode snapshot metadata.', 'mksddn-migrate-content' ) );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'payload/content.json', $payload_json );
		$this->collector->append_directories( $zip, $include_map );
		$zip->close();

		$details = array(
			'id'          => $id,
			'label'       => sanitize_text_field( $args['label'] ),
			'created_at'  => $created_at,
			'path'        => $archive,
			'size'        => file_exists( $archive ) ? (int) filesize( $archive ) : 0,
			'includes'    => array_keys( $include_map ),
			'meta'        => is_array( $args['meta'] ) ? $args['meta'] : array(),
		);

		file_put_contents( $meta_file, wp_json_encode( $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$this->enforce_retention();

		return $details;
	}

	/**
	 * Get snapshot metadata by ID.
	 *
	 * @param string $snapshot_id Snapshot ID.
	 * @return array|null
	 */
	public function get( string $snapshot_id ): ?array {
		foreach ( $this->all() as $snapshot ) {
			if ( $snapshot['id'] === $snapshot_id ) {
				return $snapshot;
			}
		}

		return null;
	}

	/**
	 * List snapshots sorted by date desc.
	 *
	 * @return array
	 */
	public function all(): array {
		if ( ! is_dir( $this->base_dir ) ) {
			return array();
		}

		$entries = array();
		$items   = glob( trailingslashit( $this->base_dir ) . '*/snapshot.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_glob

		if ( empty( $items ) ) {
			return array();
		}

		foreach ( $items as $meta_file ) {
			$data = json_decode( file_get_contents( $meta_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				continue;
			}

			$data['path'] = dirname( $meta_file ) . '/snapshot.wpbkp';
			$entries[]    = $data;
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' );
			}
		);

		return $entries;
	}

	/**
	 * Remove snapshot directory.
	 *
	 * @param string $id Snapshot ID.
	 * @return bool|WP_Error True on success, false or error on failure.
	 */
	public function delete( string $id ): bool|WP_Error {
		$snapshot = $this->get( $id );
		if ( ! $snapshot ) {
			return new WP_Error( 'mksddn_snapshot_not_found', __( 'Snapshot not found.', 'mksddn-migrate-content' ) );
		}

		$dir = dirname( $snapshot['path'] );
		$result = $this->remove_directory( $dir );

		return $result;
	}

	/**
	 * Map archive roots to filesystem directories.
	 *
	 * @param bool $include_plugins Include plugin dir.
	 * @param bool $include_themes  Include theme dir.
	 * @return array<string,string>
	 */
	private function map_targets( bool $include_plugins, bool $include_themes ): array {
		$map = array(
			'files/wp-content/uploads' => WP_CONTENT_DIR . '/uploads',
		);

		if ( $include_plugins ) {
			$map['files/wp-content/plugins'] = WP_PLUGIN_DIR;
		}

		if ( $include_themes ) {
			$map['files/wp-content/themes'] = get_theme_root();
		}

		return $map;
	}

	/**
	 * Remove old snapshots beyond retention limit.
	 */
	private function enforce_retention(): void {
		$list = $this->all();
		if ( count( $list ) <= $this->retention ) {
			return;
		}

		$excess = array_slice( $list, $this->retention );
		foreach ( $excess as $snapshot ) {
			$this->remove_directory( dirname( $snapshot['path'] ) );
		}
	}

	/**
	 * Remove directory recursively.
	 *
	 * @param string $dir Target directory.
	 * @return bool True on success, false on failure.
	 */
	private function remove_directory( string $dir ): bool {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		return FilesystemHelper::delete( $dir, true );
	}
}


