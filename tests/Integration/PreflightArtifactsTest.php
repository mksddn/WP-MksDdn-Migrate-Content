<?php
/**
 * Integration: preflight claim races, artifact staging, backup delete, deactivation.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Services\PreflightReportStore;
use MksDdn\MigrateContent\Admin\Services\ServerBackupScanner;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\DeactivationCleanup;
use MksDdn\MigrateContent\Support\ImportArtifactCleanup;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;

/**
 * Safety paths around staged archives and preflight sessions.
 */
final class PreflightArtifactsTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var int */
	private int $admin_id = 0;

	/** @var string[] */
	private array $seeded_files = array();

	public function setUp(): void {
		parent::setUp();
		PluginConfig::create_required_directories();
		$this->tmpdir   = sys_get_temp_dir() . '/mksddn-mc-pf-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		foreach ( $this->seeded_files as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		$this->seeded_files = array();
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test__preflight_report_store__claim_busy_blocked_invalid_and_release(): void {
		$store  = new PreflightReportStore();
		$handle = array(
			'source_type' => 'server',
			'filename'    => 'fixture.wpbkp',
		);

		$id = $store->save(
			$this->admin_id,
			array(
				'status'  => 'ok',
				'errors'  => array(),
				'summary' => array(),
			),
			$handle
		);
		self::assertIsString( $id );

		$first = $store->claim_for_import( $id, $this->admin_id );
		self::assertIsArray( $first );
		self::assertSame( 'importing', $first['phase'] );

		$busy = $store->claim_for_import( $id, $this->admin_id );
		self::assertTrue( is_wp_error( $busy ) );
		self::assertSame( 'mksddn_mc_preflight_busy', $busy->get_error_code() );

		self::assertTrue( $store->release_import_claim( $id, $this->admin_id ) );
		$again = $store->claim_for_import( $id, $this->admin_id );
		self::assertIsArray( $again );

		$error_id = $store->save(
			$this->admin_id,
			array(
				'status' => 'error',
				'errors' => array( 'bad archive' ),
			),
			$handle
		);
		self::assertIsString( $error_id );
		$blocked = $store->claim_for_import( $error_id, $this->admin_id );
		self::assertTrue( is_wp_error( $blocked ) );
		self::assertSame( 'mksddn_mc_preflight_blocked', $blocked->get_error_code() );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$invalid = $store->claim_for_import( $id, $other );
		self::assertTrue( is_wp_error( $invalid ) );
		self::assertSame( 'mksddn_mc_preflight_invalid', $invalid->get_error_code() );
	}

	public function test__import_artifact_cleanup__stage_persist_and_discard(): void {
		// Source must live on the same volume as preflight/imports so rename works
		// (sys_get_temp_dir() is often a different mount than uploads/).
		$staging_src_dir = trailingslashit( WP_CONTENT_DIR ) . 'uploads/mksddn-mc-test-src';
		if ( ! is_dir( $staging_src_dir ) ) {
			wp_mkdir_p( $staging_src_dir );
		}
		$source = $staging_src_dir . '/upload-' . uniqid( '', true ) . '.wpbkp';
		file_put_contents( $source, 'wpbkp-bytes' );
		$this->seeded_files[] = $source;

		$staged = ImportArtifactCleanup::stage_into_preflight( $source, 'upload.wpbkp', 'wpbkp' );
		self::assertIsArray( $staged, is_wp_error( $staged ) ? $staged->get_error_message() : '' );
		self::assertFileExists( $staged['path'] );
		$this->seeded_files[] = $staged['path'];

		$bad = $staging_src_dir . '/note-' . uniqid( '', true ) . '.txt';
		file_put_contents( $bad, 'nope' );
		$this->seeded_files[] = $bad;
		$reject = ImportArtifactCleanup::stage_into_preflight( $bad, 'note.txt', 'txt' );
		self::assertTrue( is_wp_error( $reject ) );
		self::assertSame( 'mksddn_mc_import_file_invalid_type', $reject->get_error_code() );

		self::assertTrue( ImportArtifactCleanup::persist_for_reuse( $staged['path'], 'persisted.wpbkp' ) );
		$imports = PluginConfig::imports_dir();
		$found   = glob( trailingslashit( $imports ) . '*.wpbkp' );
		self::assertIsArray( $found );
		self::assertNotEmpty( $found );
		$persisted = (string) end( $found );
		$this->seeded_files[] = $persisted;
		self::assertFileExists( $persisted );

		ImportArtifactCleanup::discard_unmanaged_temp( $persisted );
		self::assertFileExists( $persisted );

		$orphan = $this->tmpdir . '/orphan.tmp';
		file_put_contents( $orphan, 'temp' );
		ImportArtifactCleanup::discard_unmanaged_temp( $orphan );
		self::assertFileDoesNotExist( $orphan );

		if ( is_dir( $staging_src_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $staging_src_dir );
		}
	}

	public function test__server_backup_scanner__delete_and_path_guard(): void {
		$imports = PluginConfig::imports_dir();
		$inside  = trailingslashit( $imports ) . 'scanner-del-' . uniqid() . '.wpbkp';
		file_put_contents( $inside, 'del-me' );
		$this->seeded_files[] = $inside;

		$outside = $this->tmpdir . '/outside.wpbkp';
		file_put_contents( $outside, 'safe' );

		$scanner = new ServerBackupScanner();
		self::assertTrue( true === $scanner->delete_file( basename( $inside ) ) );
		self::assertFileDoesNotExist( $inside );

		$traversal = $scanner->delete_file( '../' . basename( $outside ) );
		self::assertTrue( is_wp_error( $traversal ) );
		self::assertFileExists( $outside );
	}

	public function test__deactivation_cleanup__clears_jobs_keeps_imports(): void {
		$dirs = PluginConfig::get_required_directories();
		$job  = trailingslashit( $dirs['jobs'] ) . 'seed-job.txt';
		$pre  = trailingslashit( PluginConfig::preflight_dir() ) . 'seed-preflight.txt';
		$imp  = trailingslashit( PluginConfig::imports_dir() ) . 'seed-import.wpbkp';

		file_put_contents( $job, 'job' );
		file_put_contents( $pre, 'pre' );
		file_put_contents( $imp, 'imp' );
		$this->seeded_files[] = $imp;

		DeactivationCleanup::run();

		self::assertFileDoesNotExist( $job );
		self::assertFileDoesNotExist( $pre );
		self::assertFileExists( $imp );
	}
}
