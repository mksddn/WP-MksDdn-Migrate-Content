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
		$this->harness->plant_plugin_probe( "plugin-probe\n" );

		$archive = $this->tmpdir . '/full-export.wpbkp';
		$result  = ( new FullContentExporter() )->export_to( $archive );
		self::assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertFileExists( $archive );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $archive ) );

		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		self::assertIsArray( $manifest );
		self::assertSame( 'full-site', $manifest['type'] ?? '' );

		$payload_json = (string) $zip->getFromName( 'payload/content.json' );
		self::assertNotSame( '', $payload_json );
		self::assertStringContainsString( $table, $payload_json );
		self::assertStringContainsString( 'export-shape', $payload_json );

		$upload_entry = $zip->locateName( 'files/wp-content/uploads/mksddn-mc-probe/hello.txt' );
		self::assertNotFalse( $upload_entry );

		$plugin_entry = $zip->locateName( 'files/wp-content/plugins/mksddn-mc-probe-plugin/probe.txt' );
		self::assertNotFalse( $plugin_entry );

		$zip->close();
		unlink( $archive );
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
