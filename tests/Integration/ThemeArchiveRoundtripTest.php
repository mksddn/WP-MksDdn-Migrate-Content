<?php
/**
 * Integration: theme export/import archive roundtrip.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Filesystem\ThemeExporter;
use MksDdn\MigrateContent\Filesystem\ThemeImporter;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Export a disposable theme from wp-content/themes, detect type, re-import marker.
 */
final class ThemeArchiveRoundtripTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir;

	/** @var string */
	private string $theme_slug = 'mksddn-mc-fixture-theme';

	/** @var string */
	private string $theme_dir;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir    = sys_get_temp_dir() . '/mksddn-mc-theme-int-' . uniqid( '', true );
		$this->theme_dir = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $this->theme_slug;
		mkdir( $this->theme_dir, 0777, true );
		file_put_contents(
			$this->theme_dir . '/style.css',
			"/*\nTheme Name: MksDdn Fixture Theme\n*/\nbody{}\n"
		);
		file_put_contents( $this->theme_dir . '/index.php', "<?php\n// fixture\n" );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		if ( is_dir( $this->theme_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $this->theme_dir );
		}
		parent::tearDown();
	}

	public function test__theme_exporter__creates_detectable_themes_archive(): void {
		$archive = $this->tmpdir . '/themes.wpbkp';
		$result  = ( new ThemeExporter() )->export_themes( array( $this->theme_slug ), $archive );

		self::assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertFileExists( $archive );
		self::assertSame( 'themes', ( new ImportTypeDetector() )->detect( $archive, 'wpbkp' ) );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $archive ) );
		$manifest = json_decode( (string) $zip->getFromName( 'manifest.json' ), true );
		$zip->close();

		self::assertIsArray( $manifest );
		self::assertSame( 'themes', $manifest['type'] );

		$slugs = array();
		foreach ( (array) ( $manifest['themes'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
				$slugs[] = (string) $entry['slug'];
			}
		}
		self::assertContains( $this->theme_slug, $slugs );
	}

	public function test__theme_importer__writes_marker_file_from_archive(): void {
		$archive = $this->tmpdir . '/theme-merge.wpbkp';
		$export  = ( new ThemeExporter() )->export_themes( array( $this->theme_slug ), $archive );
		self::assertIsString( $export );

		$zip = new ZipArchive();
		self::assertTrue( true === $zip->open( $archive ) );
		$zip->addFromString(
			'wp-content/themes/' . $this->theme_slug . '/mksddn-mc-test-marker.txt',
			'marker-ok'
		);
		$zip->close();

		$marker = $this->theme_dir . '/mksddn-mc-test-marker.txt';
		if ( file_exists( $marker ) ) {
			unlink( $marker );
		}

		$result = ( new ThemeImporter() )->import_themes( $archive );
		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : 'import failed' );
		self::assertFileExists( $marker );
		self::assertSame( 'marker-ok', (string) file_get_contents( $marker ) );
	}
}
