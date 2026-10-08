<?php
/**
 * Integration: FilesystemHelper and ExportMemoryHelper behavioral checks.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Support\ExportMemoryHelper;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;

/**
 * FS put/read/protect and memory raise/restore contracts.
 */
final class SupportHelpersTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-fs-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__filesystem_helper__put_read_ensure_and_protect(): void {
		$file = $this->tmpdir . '/nested/dir/probe.txt';
		$ok   = FilesystemHelper::ensure_directory( $file );
		self::assertTrue( true === $ok, is_wp_error( $ok ) ? $ok->get_error_message() : '' );
		self::assertTrue( is_dir( dirname( $file ) ) );

		self::assertTrue( FilesystemHelper::put_contents( $file, "hello-fs\n" ) );
		self::assertSame( "hello-fs\n", file_get_contents( $file ) );
		self::assertSame( 'hello', FilesystemHelper::read_bytes( $file, 0, 5 ) );

		FilesystemHelper::protect_directory_from_web( $this->tmpdir . '/nested' );
		self::assertFileExists( $this->tmpdir . '/nested/.htaccess' );
		self::assertStringContainsString( 'Deny from all', (string) file_get_contents( $this->tmpdir . '/nested/.htaccess' ) );
	}

	public function test__export_memory_helper__raise_and_restore(): void {
		$before = (string) ini_get( 'memory_limit' );
		$orig   = ExportMemoryHelper::raise_for_export();
		self::assertSame( $before, $orig );

		$after_raise = (string) ini_get( 'memory_limit' );
		self::assertNotSame( '', $after_raise );

		ExportMemoryHelper::restore( $orig );
		self::assertSame( $orig, (string) ini_get( 'memory_limit' ) );

		self::assertIsBool( ExportMemoryHelper::is_memory_critical() );
		self::assertFalse( ExportMemoryHelper::is_memory_critical() );
	}
}
