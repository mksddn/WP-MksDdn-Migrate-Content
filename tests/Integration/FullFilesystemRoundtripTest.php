<?php
/**
 * Integration: full-site filesystem-only import and ContentCollector skips.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Filesystem\ContentCollector;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Imports one uploads probe file without touching the live database.
 */
final class FullFilesystemRoundtripTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var string */
	private string $probe_dir = '';

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-fs-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );

		$root             = function_exists( 'get_home_path' ) ? get_home_path() : ABSPATH;
		$this->probe_dir  = trailingslashit( $root ) . 'wp-content/uploads/mksddn-mc-probe';
	}

	public function tearDown(): void {
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		if ( '' !== $this->probe_dir && is_dir( $this->probe_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $this->probe_dir );
		}
		parent::tearDown();
	}

	public function test__full_content_importer__writes_uploads_probe_without_database(): void {
		$archive = $this->tmpdir . '/fs-only.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$archive,
			array(
				'type'    => 'full-site',
				'version' => '1.0',
			),
			array(
				'payload/content.json' => wp_json_encode(
					array(
						'type' => 'full-site',
					)
				),
				'files/wp-content/uploads/mksddn-mc-probe/hello.txt' => "probe-ok\n",
			)
		);

		$buffer_level = ob_get_level();
		$importer     = new FullContentImporter();
		$result       = $importer->import_from( $archive );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertFalse( $importer->was_database_mutated() );

		$target = trailingslashit( $this->probe_dir ) . 'hello.txt';
		self::assertFileExists( $target );
		self::assertSame( "probe-ok\n", (string) file_get_contents( $target ) );
	}

	public function test__content_collector__packs_file_and_skips_runtime_paths(): void {
		$source = $this->tmpdir . '/pack-src';
		mkdir( $source . '/ok', 0777, true );
		mkdir( $source . '/mksddn-mc/nested', 0777, true );
		file_put_contents( $source . '/ok/keep.txt', 'keep' );
		file_put_contents( $source . '/mksddn-mc/nested/skip.txt', 'skip' );
		file_put_contents( $source . '/ok/backup.wpbkp', 'archive' );

		$zip_path = $this->tmpdir . '/collector.zip';
		$zip      = new ZipArchive();
		self::assertTrue( true === $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );

		$stats = ( new ContentCollector() )->append_directories(
			$zip,
			array( 'files/wp-content/uploads' => $source )
		);
		$zip->close();

		self::assertIsArray( $stats, is_wp_error( $stats ) ? $stats->get_error_message() : '' );
		self::assertGreaterThanOrEqual( 1, $stats['files'] );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $zip_path ) );
		self::assertNotFalse( $zip->locateName( 'files/wp-content/uploads/ok/keep.txt' ) );
		self::assertFalse( $zip->locateName( 'files/wp-content/uploads/mksddn-mc/nested/skip.txt' ) );
		self::assertFalse( $zip->locateName( 'files/wp-content/uploads/ok/backup.wpbkp' ) );
		$zip->close();
	}
}
