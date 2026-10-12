<?php
/**
 * Unit tests for PreflightStagingPath allowlist.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\PreflightStagingPath;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use Unitest_WP_Copy\WP_Options;
use WP_Mock\Tools\TestCase;

/**
 * Path allowlist for imports/ and preflight/ staging dirs.
 */
final class PreflightStagingPathTest extends TestCase {

	/** @var string */
	private string $uploads;

	/** @var array|null */
	private $options_state;

	public function setUp(): void {
		parent::setUp();
		$this->options_state = WP_Options::save_state();
		$this->uploads       = sys_get_temp_dir() . '/mksddn-mc-uploads-' . uniqid( '', true );
		$imports             = $this->uploads . '/mksddn-mc/imports';
		$preflight           = $this->uploads . '/mksddn-mc/preflight';
		mkdir( $imports, 0777, true );
		mkdir( $preflight, 0777, true );

		\WP_Mock::userFunction( 'wp_upload_dir' )->andReturn(
			array(
				'basedir' => $this->uploads,
				'baseurl' => 'https://wp.test/wp-content/uploads',
				'error'   => false,
			)
		);
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->uploads );
		if ( null !== $this->options_state ) {
			WP_Options::restore_state( $this->options_state );
		}
		parent::tearDown();
	}

	public function test__is_allowed_path__accepts_imports_and_preflight_files(): void {
		$import_file    = $this->uploads . '/mksddn-mc/imports/backup.wpbkp';
		$preflight_file = $this->uploads . '/mksddn-mc/preflight/staged.wpbkp';
		file_put_contents( $import_file, 'x' );
		file_put_contents( $preflight_file, 'y' );

		self::assertTrue( PreflightStagingPath::is_allowed_path( $import_file ) );
		self::assertTrue( PreflightStagingPath::is_allowed_path( $preflight_file ) );
		self::assertFalse( PreflightStagingPath::is_ephemeral_path( $import_file ) );
		self::assertTrue( PreflightStagingPath::is_ephemeral_path( $preflight_file ) );
	}

	public function test__is_allowed_path__rejects_outside_and_missing(): void {
		$outside = $this->uploads . '/evil.wpbkp';
		file_put_contents( $outside, 'z' );

		self::assertFalse( PreflightStagingPath::is_allowed_path( $outside ) );
		self::assertFalse( PreflightStagingPath::is_allowed_path( $this->uploads . '/mksddn-mc/imports/missing.wpbkp' ) );
		self::assertFalse( PreflightStagingPath::is_allowed_path( '' ) );
	}

	public function test__is_allowed_path__rejects_directory_even_under_imports(): void {
		$dir = $this->uploads . '/mksddn-mc/imports/subdir';
		mkdir( $dir, 0777, true );
		self::assertFalse( PreflightStagingPath::is_allowed_path( $dir ) );
		self::assertFalse( PreflightStagingPath::is_ephemeral_path( $dir ) );
	}
}
