<?php
/**
 * Integration: preflight analyze → claim → import on the same staged path.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Services\ImportPreflightService;
use MksDdn\MigrateContent\Admin\Services\ImportTypeDetector;
use MksDdn\MigrateContent\Admin\Services\PreflightReportStore;
use MksDdn\MigrateContent\Admin\Services\UnifiedImportOrchestrator;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Filesystem\ThemeExporter;
use MksDdn\MigrateContent\Selection\ContentSelection;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Tests\Support\FullSiteProbeHarness;
use MksDdn\MigrateContent\Tests\Support\SelectedExportImportHarness;
use WP_UnitTestCase;

/**
 * Behavioral unified-import steps without HTTP redirects.
 */
final class UnifiedImportFlowTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var string */
	private string $theme_slug = 'mksddn-mc-unified-theme';

	/** @var string */
	private string $theme_dir = '';

	public function setUp(): void {
		parent::setUp();
		PluginConfig::create_required_directories();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-unified-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		$this->theme_dir = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $this->theme_slug;
		mkdir( $this->theme_dir, 0777, true );
		file_put_contents(
			$this->theme_dir . '/style.css',
			"/*\nTheme Name: Unified Fixture\n*/\n"
		);
		file_put_contents( $this->theme_dir . '/index.php', "<?php\n" );
	}

	public function tearDown(): void {
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		if ( '' !== $this->theme_dir && is_dir( $this->theme_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $this->theme_dir );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test__preflight_claim_import__same_staged_path_creates_page(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Preflight Page',
				'post_name'    => 'preflight-flow-e2e',
				'post_content' => 'From staged path',
				'post_status'  => 'publish',
			)
		);

		$harness   = new SelectedExportImportHarness( $this->tmpdir );
		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );
		$archive = $harness->export_file( $selection, 'archive', false );
		self::assertIsString( $archive, is_wp_error( $archive ) ? $archive->get_error_message() : '' );

		wp_delete_post( $page_id, true );

		$file_info = array(
			'path'      => $archive,
			'name'      => basename( $archive ),
			'extension' => 'wpbkp',
			'source'    => 'upload',
		);

		$report = ( new ImportPreflightService() )->analyze( $file_info, 'selected' );
		self::assertSame( 'ok', $report['status'] ?? '' );
		self::assertSame( array(), $report['errors'] ?? array( 'sentinel' ) );

		$store = new PreflightReportStore();
		$id    = $store->save(
			$user_id,
			$report,
			array(
				'source_type' => 'staged',
				'staged_path' => $archive,
				'staged_name' => basename( $archive ),
				'staged_ext'  => 'wpbkp',
			)
		);
		self::assertIsString( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );

		$claimed = $store->claim_for_import( $id, $user_id );
		self::assertIsArray( $claimed, is_wp_error( $claimed ) ? $claimed->get_error_message() : '' );
		self::assertSame( 'importing', $claimed['phase'] ?? '' );
		self::assertSame( $archive, $claimed['import_handle']['staged_path'] ?? '' );

		// Same path as preflight — no second upload.
		$imported = $harness->import_file( $claimed['import_handle']['staged_path'] );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'preflight-flow-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( 'From staged path', $page->post_content );
	}

	public function test__claim_for_import__busy_and_invalid_leave_posts_unchanged(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$before = (int) wp_count_posts( 'page' )->publish;

		$store  = new PreflightReportStore();
		$invalid = $store->claim_for_import( 'not-a-real-report-id', $user_id );
		self::assertTrue( is_wp_error( $invalid ) );
		self::assertSame( 'mksddn_mc_preflight_invalid', $invalid->get_error_code() );

		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Busy Claim',
				'post_name'   => 'busy-claim-e2e',
				'post_status' => 'publish',
				'post_content'=> 'x',
			)
		);
		$harness   = new SelectedExportImportHarness( $this->tmpdir );
		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );
		$archive = $harness->export_file( $selection, 'archive', false );
		self::assertIsString( $archive );

		$file_info = array(
			'path'      => $archive,
			'name'      => basename( $archive ),
			'extension' => 'wpbkp',
			'source'    => 'upload',
		);
		$report = ( new ImportPreflightService() )->analyze( $file_info, 'selected' );
		$id     = $store->save(
			$user_id,
			$report,
			array(
				'source_type' => 'staged',
				'staged_path' => $archive,
			)
		);
		self::assertIsString( $id );

		$first = $store->claim_for_import( $id, $user_id );
		self::assertIsArray( $first );

		$busy = $store->claim_for_import( $id, $user_id );
		self::assertTrue( is_wp_error( $busy ) );
		self::assertSame( 'mksddn_mc_preflight_busy', $busy->get_error_code() );

		self::assertGreaterThanOrEqual( $before + 1, (int) wp_count_posts( 'page' )->publish );
		self::assertInstanceOf( \WP_Post::class, get_page_by_path( 'busy-claim-e2e', OBJECT, 'page' ) );
	}

	public function test__import_type_detector__on_real_selected_and_theme_archives(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Detect Page',
				'post_name'    => 'detect-selected-e2e',
				'post_content' => 'd',
				'post_status'  => 'publish',
			)
		);
		$harness   = new SelectedExportImportHarness( $this->tmpdir );
		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );
		$selected = $harness->export_file( $selection, 'archive', false );
		self::assertIsString( $selected );

		$detector = new ImportTypeDetector();
		self::assertSame( 'selected', $detector->detect( $selected, 'wpbkp' ) );

		$theme_archive = $this->tmpdir . '/themes-detect.wpbkp';
		$result        = ( new ThemeExporter() )->export_themes( array( $this->theme_slug ), $theme_archive );
		self::assertIsString( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( 'themes', $detector->detect( $theme_archive, 'wpbkp' ) );

		$probe = new FullSiteProbeHarness( $this->tmpdir );
		$probe->plant_upload_probe( "detect-full\n" );
		$full_archive = $this->tmpdir . '/full-detect.wpbkp';
		$full_export  = ( new FullContentExporter() )->export_to( $full_archive );
		self::assertIsString( $full_export, is_wp_error( $full_export ) ? $full_export->get_error_message() : '' );
		$full_type = $detector->detect( $full_archive, 'wpbkp' );
		self::assertSame( 'full', $full_type );

		$orchestrator = new UnifiedImportOrchestrator();
		self::assertSame( 'full', $orchestrator->service_for_detected_type( (string) $full_type ) );
		self::assertSame( 'selected', $orchestrator->service_for_detected_type( 'selected' ) );
		self::assertSame( 'themes', $orchestrator->service_for_detected_type( 'themes' ) );
		self::assertNotSame( 'selected', $orchestrator->service_for_detected_type( (string) $full_type ) );
		$probe->cleanup_dirs();
	}

	public function test__preflight_analyze__corrupt_archive_returns_errors_without_import(): void {
		$corrupt = $this->tmpdir . '/corrupt.wpbkp';
		file_put_contents( $corrupt, 'not-a-zip' );

		$before = (int) wp_count_posts( 'page' )->publish;
		$report = ( new ImportPreflightService() )->analyze(
			array(
				'path'      => $corrupt,
				'name'      => 'corrupt.wpbkp',
				'extension' => 'wpbkp',
				'source'    => 'upload',
			),
			'selected'
		);

		self::assertSame( 'error', $report['status'] ?? '' );
		self::assertNotEmpty( $report['errors'] ?? array() );
		self::assertSame( $before, (int) wp_count_posts( 'page' )->publish );
		self::assertNull( get_page_by_path( 'checksum-e2e', OBJECT, 'page' ) );
	}
}
