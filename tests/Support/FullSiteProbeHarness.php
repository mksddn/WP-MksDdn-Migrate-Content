<?php
/**
 * Probe tables/files and minimal full-site archives for integration tests.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Support;

use WP_Error;
use ZipArchive;

/**
 * Disposable probe fixtures that avoid rewriting core WordPress tables.
 */
final class FullSiteProbeHarness {

	/** @var string */
	private string $tmpdir;

	/** @var list<string> */
	private array $tracked_tables = array();

	/** @var list<string> */
	private array $tracked_dirs = array();

	public function __construct( string $tmpdir ) {
		$this->tmpdir = $tmpdir;
	}

	/**
	 * Disable WP_UnitTestCase temporary-table rewrites so RENAME works.
	 */
	public function disable_temporary_tables( \WP_UnitTestCase $case ): void {
		global $wpdb;

		remove_filter( 'query', array( $case, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $case, '_drop_temporary_tables' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT;' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET autocommit = 1;' );
	}

	/**
	 * Create probe table with one row. Returns absolute table name.
	 *
	 * @param string $suffix Short suffix after mksddn_t_.
	 * @param string $label  Row label.
	 */
	public function create_probe_table( string $suffix, string $label = 'probe-ok' ): string {
		global $wpdb;

		$table = $wpdb->prefix . 'mksddn_t_' . preg_replace( '/[^a-z0-9_]/', '', $suffix );
		$this->tracked_tables[] = $table;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			'CREATE TABLE `' . str_replace( '`', '``', $table ) . '` (
				id bigint(20) unsigned NOT NULL,
				label varchar(100) NOT NULL,
				PRIMARY KEY (id)
			) ENGINE=InnoDB'
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$table,
			array(
				'id'    => 1,
				'label' => $label,
			),
			array( '%d', '%s' )
		);

		return $table;
	}

	/**
	 * Drop tracked tables and leftover swap names.
	 */
	public function cleanup_tables(): void {
		global $wpdb;

		foreach ( $this->tracked_tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		}
		$this->tracked_tables = array();

		$like  = $wpdb->esc_like( $wpdb->prefix . 'mksddn_t_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		foreach ( (array) $found as $name ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', (string) $name ) . '`' );
		}
	}

	/**
	 * Plant a file under uploads/mksddn-mc-probe/.
	 *
	 * @param string $contents File body.
	 * @return string Absolute path.
	 */
	public function plant_upload_probe( string $contents = "probe-ok\n" ): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'mksddn-mc-probe';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$this->tracked_dirs[] = $dir;
		$path                 = $dir . '/hello.txt';
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Absolute plugins root used by FullContentExporter.
	 */
	public function plugins_root(): string {
		return dirname( plugin_dir_path( MKSDDN_MC_FILE ) );
	}

	/**
	 * Plant probe plugin file under the exporter plugins root.
	 *
	 * @param string $contents File body.
	 * @return string Absolute path.
	 */
	public function plant_plugin_probe( string $contents = "plugin-probe\n" ): string {
		$dir = trailingslashit( $this->plugins_root() ) . 'mksddn-mc-probe-plugin';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$this->tracked_dirs[] = $dir;
		$path                 = $dir . '/probe.txt';
		file_put_contents( $path, $contents );
		return $path;
	}

	/**
	 * Remove planted directories.
	 */
	public function cleanup_dirs(): void {
		foreach ( $this->tracked_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				ArchiveFixtureBuilder::rrmdir( $dir );
			}
		}
		$this->tracked_dirs = array();
	}

	/**
	 * Build a minimal full-site .wpbkp (exporter layout) with one probe table + upload file.
	 *
	 * @param string $table Absolute table name already created (or to recreate on import).
	 * @param string $label Row label stored in dump.
	 * @param string $upload_body Upload file body.
	 * @return string Absolute archive path.
	 */
	public function build_minimal_full_site_archive( string $table, string $label = 'restored', string $upload_body = "restored-ok\n" ): string {
		global $wpdb;

		$schema = 'CREATE TABLE `' . str_replace( '`', '``', $table ) . '` (
			id bigint(20) unsigned NOT NULL,
			label varchar(100) NOT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB';

		$payload = array(
			'type'     => 'full-site',
			'database' => array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$table => array(
						'schema' => $schema,
						'rows'   => array(
							array(
								'id'    => 1,
								'label' => $label,
							),
						),
					),
				),
			),
		);

		$archive = $this->tmpdir . '/minimal-full.wpbkp';
		$zip     = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Unable to create minimal full-site archive.' );
		}

		$manifest = array(
			'format_version' => 1,
			'type'           => 'full-site',
			'created_at_gmt' => gmdate( 'c' ),
		);
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest ) );
		$zip->addFromString( 'payload/content.json', (string) wp_json_encode( $payload ) );
		$zip->addFromString( 'files/wp-content/uploads/mksddn-mc-probe/hello.txt', $upload_body );
		$zip->close();

		return $archive;
	}

	/**
	 * Build a full-site archive containing only remote user tables (foreign prefix).
	 *
	 * @param array<string,mixed> $users_rows Users rows.
	 * @param array<string,mixed> $meta_rows  Usermeta rows.
	 * @param string              $prefix     Remote table prefix.
	 * @return string Absolute path.
	 */
	public function build_users_only_archive( array $users_rows, array $meta_rows, string $prefix = 'zzz_' ): string {
		$users_table    = $prefix . 'users';
		$usermeta_table = $prefix . 'usermeta';

		$payload = array(
			'type'     => 'full-site',
			'database' => array(
				'table_prefix' => $prefix,
				'tables'       => array(
					$users_table    => array(
						'schema' => '',
						'rows'   => $users_rows,
					),
					$usermeta_table => array(
						'schema' => '',
						'rows'   => $meta_rows,
					),
				),
			),
		);

		$archive = $this->tmpdir . '/users-only.wpbkp';
		$zip     = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Unable to create users-only archive.' );
		}

		$manifest = array(
			'format_version' => 1,
			'type'           => 'full-site',
		);
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $manifest ) );
		$zip->addFromString( 'payload/content.json', (string) wp_json_encode( $payload ) );
		$zip->close();

		return $archive;
	}

	/**
	 * Read probe table label or null if missing.
	 *
	 * @param string $table Table name.
	 */
	public function probe_label( string $table ): ?string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( (string) $exists !== $table ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var( 'SELECT label FROM `' . str_replace( '`', '``', $table ) . '` WHERE id = 1' );
		return null === $value ? null : (string) $value;
	}
}
