<?php
/**
 * Integration: full-site export shape + minimal DB+FS import (no core table rewrite).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Tests\Support\FullSiteProbeHarness;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Export asserts archive contents; import uses synthetic minimal archives only.
 */
final class FullSiteExportImportTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var FullSiteProbeHarness|null */
	private ?FullSiteProbeHarness $harness = null;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir  = sys_get_temp_dir() . '/mksddn-mc-full-e2e-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		$this->harness = new FullSiteProbeHarness( $this->tmpdir );
		$this->harness->disable_temporary_tables( $this );
	}

	public function tearDown(): void {
		if ( $this->harness ) {
			$this->harness->cleanup_tables();
			$this->harness->cleanup_dirs();
		}
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		wp_set_current_user( 0 );
		$this->harness = null;
		parent::tearDown();
	}

	public function test__full_content_exporter__archive_contains_probe_table_and_upload(): void {
		$table = $this->harness->create_probe_table( 'exportshape', 'export-shape' );
		$this->harness->plant_upload_probe( "upload-probe\n" );
		$plugin_path = $this->harness->plant_plugin_probe( "plugin-probe\n" );
		$mu_path     = $this->harness->plant_mu_plugin_probe( "<?php // mu-plugin-probe\n" );

		$archive = $this->tmpdir . '/full-export.wpbkp';
		$result  = ( new FullContentExporter() )->export_to( $archive );
		self::assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertFileExists( $archive );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $archive ) );

		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		self::assertIsArray( $manifest );
		self::assertSame( 'full-site', $manifest['type'] ?? '' );
		self::assertArrayHasKey( 'plugin_version', $manifest );

		$payload_json = (string) $zip->getFromName( 'payload/content.json' );
		self::assertNotSame( '', $payload_json );
		self::assertStringContainsString( $table, $payload_json );
		self::assertStringContainsString( 'export-shape', $payload_json );

		$upload_entry = $zip->locateName( 'files/wp-content/uploads/mksddn-mc-probe/hello.txt' );
		self::assertNotFalse( $upload_entry );
		self::assertSame( "upload-probe\n", (string) $zip->getFromIndex( $upload_entry ) );

		$plugin_entry = $zip->locateName( 'files/wp-content/plugins/mksddn-mc-probe-plugin/probe.txt' );
		self::assertNotFalse( $plugin_entry );
		self::assertSame( "plugin-probe\n", (string) $zip->getFromIndex( $plugin_entry ) );

		$mu_entry = $zip->locateName( 'files/wp-content/mu-plugins/mksddn-mc-probe-mu.php' );
		self::assertNotFalse( $mu_entry );
		self::assertSame( "<?php // mu-plugin-probe\n", (string) $zip->getFromIndex( $mu_entry ) );

		$zip->close();
		unlink( $archive );
		self::assertFileExists( $plugin_path );
		self::assertFileExists( $mu_path );
	}

	public function test__full_content_importer__plugin_and_mu_plugin_bodies_roundtrip(): void {
		// Exporter reads dirname(MKSDDN_MC_FILE); importer writes under the WordPress
		// content root. Export for real, drop the database payload so core tables stay
		// untouched, wipe the importer destinations, then assert the exported bytes return.
		$plugin_source = $this->harness->plant_plugin_probe( "plugin-body-v1\n" );
		$mu_source     = $this->harness->plant_mu_plugin_probe( "<?php // mu-body-v1\n" );

		$archive  = $this->tmpdir . '/plugin-roundtrip.wpbkp';
		$exported = ( new FullContentExporter() )->export_to( $archive );
		self::assertIsString( $exported, is_wp_error( $exported ) ? $exported->get_error_message() : '' );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $archive ) );
		self::assertSame( "plugin-body-v1\n", (string) $zip->getFromName( 'files/wp-content/plugins/mksddn-mc-probe-plugin/probe.txt' ) );
		self::assertSame( "<?php // mu-body-v1\n", (string) $zip->getFromName( 'files/wp-content/mu-plugins/mksddn-mc-probe-mu.php' ) );
		$zip->addFromString( 'payload/content.json', (string) wp_json_encode( array( 'type' => 'full-site' ) ) );
		$zip->close();

		$home        = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$plugin_dest = trailingslashit( $home ) . 'wp-content/plugins/mksddn-mc-probe-plugin/probe.txt';
		$mu_dest     = trailingslashit( $home ) . 'wp-content/mu-plugins/mksddn-mc-probe-mu.php';
		$this->harness->track_path( dirname( $plugin_dest ) );
		$this->harness->track_path( $mu_dest );

		if ( ! is_dir( dirname( $plugin_dest ) ) ) {
			mkdir( dirname( $plugin_dest ), 0777, true );
		}
		file_put_contents( $plugin_dest, "wiped-plugin\n" );
		if ( $mu_dest !== $mu_source ) {
			if ( ! is_dir( dirname( $mu_dest ) ) ) {
				mkdir( dirname( $mu_dest ), 0777, true );
			}
			file_put_contents( $mu_dest, "<?php // wiped-mu\n" );
		} else {
			file_put_contents( $mu_source, "<?php // wiped-mu\n" );
		}

		$buffer_level = ob_get_level();
		$imported     = ( new FullContentImporter() )->import_from( $archive, new SiteUrlGuard() );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );
		self::assertSame( "plugin-body-v1\n", (string) file_get_contents( $plugin_dest ) );
		self::assertSame( "<?php // mu-body-v1\n", (string) file_get_contents( $mu_dest ) );
		self::assertFileExists( $plugin_source );
		unlink( $archive );
	}

	public function test__full_content_importer__rewrites_urls_and_upload_paths(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mksddn_t_urlrw';
		$this->harness->create_probe_table( 'urlrw', 'placeholder' );

		$uploads     = wp_upload_dir();
		$old_uploads = '/var/www/old/wp-content/uploads';
		$serialized  = serialize(
			array(
				'url' => 'https://old.example/about',
			)
		);

		$schema = 'CREATE TABLE `' . str_replace( '`', '``', $table ) . '` (
			id bigint(20) unsigned NOT NULL,
			label varchar(255) NOT NULL,
			meta_blob longtext NOT NULL,
			path_value varchar(255) NOT NULL,
			PRIMARY KEY (id)
		) ENGINE=InnoDB';

		// Recreate with wider schema for rewrite assertions.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $schema );

		$archive = $this->harness->build_minimal_full_site_archive(
			$table,
			'ignored',
			"path-ok\n",
			array(),
			array(
				'schema'   => $schema,
				'site_url' => 'https://old.example',
				'home_url' => 'https://old.example',
				'paths'    => array(
					'root'    => '/var/www/old',
					'content' => '/var/www/old/wp-content',
					'uploads' => $old_uploads,
				),
				'rows'     => array(
					array(
						'id'         => 1,
						'label'      => 'https://old.example/plain',
						'meta_blob'  => $serialized,
						'path_value' => $old_uploads . '/probe.jpg',
					),
				),
			)
		);

		$buffer_level = ob_get_level();
		$imported     = ( new FullContentImporter() )->import_from( $archive, new SiteUrlGuard() );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( 'SELECT label, meta_blob, path_value FROM `' . str_replace( '`', '``', $table ) . '` WHERE id = 1', ARRAY_A );
		self::assertIsArray( $row );

		$home = untrailingslashit( (string) home_url() );
		self::assertSame( $home . '/plain', $row['label'] );

		$decoded = unserialize( $row['meta_blob'] );
		self::assertIsArray( $decoded );
		self::assertSame( $home . '/about', $decoded['url'] );

		self::assertSame( trailingslashit( $uploads['basedir'] ) . 'probe.jpg', $row['path_value'] );
		// DomainReplacer maps untrailingslashit old → untrailingslashit new; trailing slash variants also mapped.
		self::assertStringNotContainsString( 'old.example', $row['label'] );
		self::assertStringNotContainsString( '/var/www/old', $row['path_value'] );
	}

	public function test__full_content_importer__restores_theme_backup_on_extract_failure(): void {
		$theme_slug = 'mksddn-mc-rollback-theme';
		$theme_dir  = trailingslashit( get_theme_root() ) . $theme_slug;
		if ( is_dir( $theme_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $theme_dir );
		}
		mkdir( $theme_dir, 0777, true );
		file_put_contents( $theme_dir . '/style.css', "original-style\n" );
		file_put_contents( $theme_dir . '/index.php', "<?php\n// original\n" );
		$this->harness->track_path( $theme_dir );

		$uploads     = wp_upload_dir();
		$fail_target = trailingslashit( $uploads['basedir'] ) . 'mksddn-mc-fail-target';
		if ( is_file( $fail_target ) ) {
			unlink( $fail_target );
		}
		if ( ! is_dir( $fail_target ) ) {
			mkdir( $fail_target, 0777, true );
		}
		$this->harness->track_path( $fail_target );

		$table   = $this->harness->create_probe_table( 'themerb', 'theme-rb' );
		$archive = $this->harness->build_minimal_full_site_archive(
			$table,
			'theme-rb',
			"upload-ok\n",
			array(
				'files/wp-content/themes/' . $theme_slug . '/style.css' => "from-archive\n",
				'files/wp-content/themes/' . $theme_slug . '/index.php' => "<?php\n// archive\n",
				// Intentionally a file path that already exists as a directory on disk.
				'files/wp-content/uploads/mksddn-mc-fail-target' => 'should-not-overwrite-dir',
			)
		);

		$buffer_level = ob_get_level();
		$imported     = ( new FullContentImporter() )->import_from( $archive, new SiteUrlGuard() );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( is_wp_error( $imported ), 'Extract must fail when target path is an existing directory.' );
		self::assertFileExists( $theme_dir . '/style.css' );
		self::assertSame( "original-style\n", (string) file_get_contents( $theme_dir . '/style.css' ) );
		self::assertTrue( is_dir( $fail_target ) );
	}

	public function test__full_content_importer__rejects_zip_slip_paths(): void {
		$table   = $this->harness->create_probe_table( 'zipslip', 'zip-slip' );
		$outside = trailingslashit( WP_CONTENT_DIR ) . 'mksddn-mc-zipslip-outside.txt';
		if ( file_exists( $outside ) ) {
			unlink( $outside );
		}
		$this->harness->track_path( $outside );

		$archive = $this->harness->build_minimal_full_site_archive(
			$table,
			'zip-slip',
			"safe\n",
			array(
				'files/wp-content/uploads/../../mksddn-mc-zipslip-outside.txt' => 'pwned',
				'files/wp-content/uploads/evil' . "\0" . 'x.txt'               => 'null-byte',
				'files/wp-content/uploads/../plugins/mksddn-mc-slip.txt'       => 'parent-escape',
			)
		);

		$buffer_level = ob_get_level();
		$imported     = ( new FullContentImporter() )->import_from( $archive, new SiteUrlGuard() );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );
		self::assertFileDoesNotExist( $outside );
		self::assertFileDoesNotExist( trailingslashit( WP_PLUGIN_DIR ) . 'mksddn-mc-slip.txt' );
		$uploads = wp_upload_dir();
		self::assertFileExists( trailingslashit( $uploads['basedir'] ) . 'mksddn-mc-probe/hello.txt' );
	}

	public function test__full_content_importer__restores_probe_table_and_upload_together(): void {
		$table = $this->harness->create_probe_table( 'importdb', 'will-drop' );
		$upload = $this->harness->plant_upload_probe( "will-delete\n" );

		$archive = $this->harness->build_minimal_full_site_archive( $table, 'restored-label', "restored-ok\n" );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		unlink( $upload );
		self::assertNull( $this->harness->probe_label( $table ) );
		self::assertFileDoesNotExist( $upload );

		$posts_before = (int) wp_count_posts( 'post' )->publish;
		$home_before  = (string) get_option( 'home' );

		$buffer_level = ob_get_level();
		$importer     = new FullContentImporter();
		$result       = $importer->import_from( $archive, new SiteUrlGuard() );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( 'restored-label', $this->harness->probe_label( $table ) );
		self::assertFileExists( $upload );
		self::assertSame( "restored-ok\n", (string) file_get_contents( $upload ) );
		self::assertSame( $posts_before, (int) wp_count_posts( 'post' )->publish );
		self::assertSame( $home_before, (string) get_option( 'home' ) );
	}

	public function test__full_content_importer__user_merge_create_keep_replace(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$local_id = self::factory()->user->create(
			array(
				'user_login' => 'merge-local',
				'user_email' => 'merge-local-' . uniqid() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$local      = get_userdata( $local_id );
		$local_email = strtolower( $local->user_email );
		$new_email   = 'merge-new-' . uniqid() . '@example.com';

		$users_rows = array(
			array(
				'ID'              => 501,
				'user_login'      => 'merge-new-user',
				'user_email'      => $new_email,
				'user_pass'       => wp_hash_password( 'secret' ),
				'display_name'    => 'Merge New',
				'user_nicename'   => 'merge-new-user',
				'user_url'        => '',
				'user_registered' => current_time( 'mysql' ),
				'user_status'     => 0,
			),
			array(
				'ID'              => 502,
				'user_login'      => 'remote-replaced',
				'user_email'      => $local->user_email,
				'user_pass'       => wp_hash_password( 'other' ),
				'display_name'    => 'Replaced Name',
				'user_nicename'   => 'remote-replaced',
				'user_url'        => '',
				'user_registered' => current_time( 'mysql' ),
				'user_status'     => 0,
			),
		);
		$meta_rows = array(
			array(
				'umeta_id'   => 1,
				'user_id'    => 501,
				'meta_key'   => 'zzz_capabilities',
				'meta_value' => serialize( array( 'subscriber' => true ) ),
			),
			array(
				'umeta_id'   => 2,
				'user_id'    => 502,
				'meta_key'   => 'zzz_capabilities',
				'meta_value' => serialize( array( 'author' => true ) ),
			),
		);

		$archive = $this->harness->build_users_only_archive( $users_rows, $meta_rows, 'zzz_' );
		$posts_before = (int) wp_count_posts( 'post' )->publish;

		$plan = array(
			strtolower( $new_email ) => array(
				'import' => true,
				'mode'   => 'replace',
			),
			$local_email              => array(
				'import' => true,
				'mode'   => 'replace',
			),
		);

		$buffer_level = ob_get_level();
		$importer     = new FullContentImporter();
		$result       = $importer->import_from(
			$archive,
			new SiteUrlGuard(),
			array(
				'user_merge' => array(
					'enabled' => true,
					'plan'    => $plan,
					'tables'  => array(
						'users'    => 'zzz_users',
						'usermeta' => 'zzz_usermeta',
						'prefix'   => 'zzz_',
					),
				),
			)
		);
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$created = get_user_by( 'email', $new_email );
		self::assertInstanceOf( \WP_User::class, $created );
		self::assertSame( 'remote-replaced', get_userdata( $local_id )->user_login );
		self::assertSame( $posts_before, (int) wp_count_posts( 'post' )->publish );

		$summary = $importer->get_user_merge_summary();
		self::assertGreaterThanOrEqual( 1, (int) ( $summary['created'] ?? 0 ) );
		self::assertGreaterThanOrEqual( 1, (int) ( $summary['updated'] ?? 0 ) );
	}
}
