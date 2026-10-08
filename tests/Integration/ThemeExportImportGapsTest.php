<?php
/**
 * Integration: multi-theme export/import, preview-store apply, child theme path.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Filesystem\ThemeExporter;
use MksDdn\MigrateContent\Filesystem\ThemeImporter;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;
use WP_UnitTestCase;

/**
 * Theme gaps beyond ThemeArchiveRoundtripTest.
 */
final class ThemeExportImportGapsTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var list<string> */
	private array $theme_dirs = array();

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-theme-gaps-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		foreach ( $this->theme_dirs as $dir ) {
			if ( is_dir( $dir ) ) {
				ArchiveFixtureBuilder::rrmdir( $dir );
			}
		}
		$this->theme_dirs = array();
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * @param string $slug Theme slug.
	 * @param string $name Theme Name header.
	 * @param string $extra Extra style.css lines (e.g. Template).
	 */
	private function plant_theme( string $slug, string $name, string $extra = '' ): string {
		$dir = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $slug;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$this->theme_dirs[] = $dir;
		$header             = "/*\nTheme Name: {$name}\n{$extra}*/\nbody{}\n";
		file_put_contents( $dir . '/style.css', $header );
		file_put_contents( $dir . '/index.php', "<?php\n// {$slug}\n" );
		file_put_contents( $dir . '/marker.txt', $slug . "-marker\n" );
		return $dir;
	}

	public function test__theme_exporter_importer__two_themes_both_restored(): void {
		$a = 'mksddn-mc-gap-a';
		$b = 'mksddn-mc-gap-b';
		$this->plant_theme( $a, 'Gap Theme A' );
		$this->plant_theme( $b, 'Gap Theme B' );

		$archive = $this->tmpdir . '/two-themes.wpbkp';
		$result  = ( new ThemeExporter() )->export_themes( array( $a, $b ), $archive );
		self::assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		ArchiveFixtureBuilder::rrmdir( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $a );
		ArchiveFixtureBuilder::rrmdir( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $b );

		$imported = ( new ThemeImporter() )->import_themes( $archive );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		self::assertFileExists( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $a . '/marker.txt' );
		self::assertFileExists( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $b . '/marker.txt' );
		self::assertSame( $a . "-marker\n", (string) file_get_contents( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $a . '/marker.txt' ) );
		self::assertSame( $b . "-marker\n", (string) file_get_contents( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $b . '/marker.txt' ) );
	}

	public function test__theme_preview_store__then_importer_replace_and_merge(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$slug = 'mksddn-mc-gap-preview';
		$dir  = $this->plant_theme( $slug, 'Preview Theme' );

		$archive = $this->tmpdir . '/preview-theme.wpbkp';
		$export  = ( new ThemeExporter() )->export_themes( array( $slug ), $archive );
		self::assertIsString( $export );

		// Local-only file appears after export so replace must delete it.
		file_put_contents( $dir . '/local-only.txt', "local\n" );

		$store = new ThemePreviewStore();
		$id    = $store->create(
			array(
				'file_path'   => $archive,
				'import_mode' => 'replace',
				'themes'      => array( $slug ),
			)
		);
		self::assertIsString( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$preview = $store->get( $id );
		self::assertIsArray( $preview );
		self::assertSame( $archive, $preview['file_path'] ?? '' );
		self::assertSame( 'replace', $preview['import_mode'] ?? '' );

		$replaced = ( new ThemeImporter( 'replace' ) )->import_themes( $preview['file_path'] );
		self::assertTrue( true === $replaced, is_wp_error( $replaced ) ? $replaced->get_error_message() : '' );
		self::assertFileDoesNotExist( $dir . '/local-only.txt' );
		self::assertFileExists( $dir . '/marker.txt' );

		file_put_contents( $dir . '/local-only.txt', "local-again\n" );
		$id_merge = $store->create(
			array(
				'file_path'   => $archive,
				'import_mode' => 'merge',
				'themes'      => array( $slug ),
			)
		);
		self::assertIsString( $id_merge );
		$preview_merge = $store->get( $id_merge );
		$merged        = ( new ThemeImporter( 'merge' ) )->import_themes( $preview_merge['file_path'] );
		self::assertTrue( true === $merged, is_wp_error( $merged ) ? $merged->get_error_message() : '' );
		self::assertFileExists( $dir . '/local-only.txt' );
		self::assertSame( "local-again\n", (string) file_get_contents( $dir . '/local-only.txt' ) );

		$store->delete( $id );
		$store->delete( $id_merge );
	}

	public function test__theme_importer__child_theme_path_layout(): void {
		$parent = 'mksddn-mc-gap-parent';
		$child  = 'mksddn-mc-gap-child';
		$this->plant_theme( $parent, 'Gap Parent' );
		$this->plant_theme( $child, 'Gap Child', "Template: {$parent}\n" );
		file_put_contents(
			trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $child . '/child-only.php',
			"<?php\n// child\n"
		);

		$archive = $this->tmpdir . '/child-theme.wpbkp';
		$export  = ( new ThemeExporter() )->export_themes( array( $parent, $child ), $archive );
		self::assertIsString( $export, is_wp_error( $export ) ? $export->get_error_message() : '' );

		ArchiveFixtureBuilder::rrmdir( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $parent );
		ArchiveFixtureBuilder::rrmdir( trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $child );

		$imported = ( new ThemeImporter() )->import_themes( $archive );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$child_style = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $child . '/style.css';
		$child_php   = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $child . '/child-only.php';
		self::assertFileExists( $child_style );
		self::assertFileExists( $child_php );
		self::assertStringContainsString( 'Template: ' . $parent, (string) file_get_contents( $child_style ) );
	}
}
