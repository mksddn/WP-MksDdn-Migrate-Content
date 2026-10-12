<?php
/**
 * Unit tests for ImportTypeDetector.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Admin;

use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_Mock\Tools\TestCase;

/**
 * Routing detection for json / full / themes / selected archives.
 */
final class ImportTypeDetectorTest extends TestCase {

	/** @var string */
	private string $tmpdir;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-type-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__detect__json_is_selected(): void {
		$path = $this->tmpdir . '/bundle.json';
		file_put_contents( $path, '{"items":[]}' );

		$result = ( new ImportTypeDetector() )->detect( $path, 'json' );
		self::assertSame( 'selected', $result );
	}

	public function test__detect__full_site_manifest(): void {
		$path = $this->tmpdir . '/full.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$path,
			array(
				'type'           => 'full',
				'format_version' => 1,
				'plugin_version' => '2.8.0',
			),
			array(
				'payload/content.json' => '{"tables":[]}',
			)
		);

		$result = ( new ImportTypeDetector() )->detect( $path, 'wpbkp' );
		self::assertSame( 'full', $result );
	}

	public function test__detect__themes_manifest(): void {
		$path = $this->tmpdir . '/themes.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$path,
			array(
				'type'           => 'themes',
				'format_version' => 1,
				'plugin_version' => '2.8.0',
				'themes'         => array( 'twentytwentyfour' ),
			)
		);

		$result = ( new ImportTypeDetector() )->detect( $path, 'wpbkp' );
		self::assertSame( 'themes', $result );
	}

	public function test__detect__selected_bundle_manifest(): void {
		$path = $this->tmpdir . '/selected.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$path,
			array(
				'type'           => 'bundle',
				'format_version' => 1,
				'plugin_version' => '2.8.0',
			),
			array(
				'payload/content.json' => '{"items":[{"post_type":"page","post_name":"about"}]}',
			)
		);

		$result = ( new ImportTypeDetector() )->detect( $path, 'wpbkp' );
		self::assertSame( 'selected', $result );
	}

	public function test__detect__missing_file_is_error(): void {
		$result = ( new ImportTypeDetector() )->detect( $this->tmpdir . '/nope.wpbkp', 'wpbkp' );
		self::assertTrue( is_wp_error( $result ) );
	}
}
