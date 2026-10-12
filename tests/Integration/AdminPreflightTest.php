<?php
/**
 * Integration: preflight store, activation dirs, deactivation cleanup.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\PreflightReportStore;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\DeactivationCleanup;
use MksDdn\MigrateContent\Support\PreflightStagingPath;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;

/**
 * Unified preflight handle + cleanup behaviours.
 */
final class AdminPreflightTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir;

	public function setUp(): void {
		parent::setUp();
		PluginConfig::create_required_directories();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-admin-int-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__plugin_config__creates_required_directories(): void {
		$dirs = PluginConfig::get_required_directories();
		self::assertNotEmpty( $dirs['imports'] ?? '' );
		self::assertNotEmpty( $dirs['jobs'] ?? '' );
		self::assertDirectoryExists( $dirs['imports'] );
		self::assertDirectoryExists( $dirs['jobs'] );
	}

	public function test__preflight_report_store__roundtrip(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$store   = new PreflightReportStore();

		$saved = $store->save(
			$user_id,
			array(
				'type'     => 'selected',
				'status'   => 'ok',
				'warnings' => array( 'demo' ),
			),
			array(
				'kind' => 'server',
				'file' => 'demo.wpbkp',
			)
		);

		self::assertIsString( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : '' );
		$loaded = $store->get_for_user( $saved, $user_id );
		self::assertIsArray( $loaded );
	}

	public function test__import_type_detector__routes_selected_archive(): void {
		$path = ArchiveFixtureBuilder::create_wpbkp(
			$this->tmpdir . '/selected.wpbkp',
			array(
				'type'           => 'bundle',
				'format_version' => 1,
				'plugin_version' => MKSDDN_MC_VERSION,
			),
			array(
				'payload/content.json' => '{"items":[]}',
			)
		);

		self::assertSame( 'selected', ( new ImportTypeDetector() )->detect( $path, 'wpbkp' ) );
	}

	public function test__preflight_staging_path__allows_imports_file(): void {
		$imports = PluginConfig::imports_dir();
		$file    = trailingslashit( $imports ) . 'int-test.wpbkp';
		file_put_contents( $file, 'x' );
		self::assertTrue( PreflightStagingPath::is_allowed_path( $file ) );
		self::assertFalse( PreflightStagingPath::is_ephemeral_path( $file ) );
		unlink( $file );
	}

	public function test__deactivation_cleanup__clears_import_lock_transient(): void {
		set_transient( 'mksddn_mc_import_lock', array( 'token' => 'x', 'created_at' => time() ), 60 );
		DeactivationCleanup::run();
		self::assertFalse( get_transient( 'mksddn_mc_import_lock' ) );
	}
}
