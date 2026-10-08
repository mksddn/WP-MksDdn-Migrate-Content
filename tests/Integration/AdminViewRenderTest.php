<?php
/**
 * Integration: admin view rendering and upload resolve entrypoints.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Core\View\ViewRenderer;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;

/**
 * Renders admin templates and resolves staged full-site uploads without HTTP exit.
 */
final class AdminViewRenderTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-admin-view-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		unset( $_POST['preflight_staged_path'], $_POST['preflight_staged_name'], $_POST['server_file'] );
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__admin_page_view__render_export_contains_sections(): void {
		ob_start();
		( new AdminPageView() )->render_export_sections();
		$html = (string) ob_get_clean();

		self::assertStringContainsString( 'mksddn-mc-', $html );
		self::assertNotSame( '', trim( $html ) );
	}

	public function test__view_renderer__plugin_nav_tabs_template(): void {
		$html = ( new ViewRenderer() )->render_string(
			'admin/nav-tabs.php',
			array(
				'mksddn_mc_tabs'       => array(
					'full'     => 'Full Site Export',
					'selected' => 'Selected Content Export',
				),
				'mksddn_mc_active_tab' => 'full',
				'mksddn_mc_tabs_mode'  => 'button',
			)
		);
		self::assertStringContainsString( 'nav-tab', $html );
		self::assertStringContainsString( 'nav-tab-active', $html );
		self::assertStringContainsString( 'Full Site Export', $html );
	}

	public function test__full_site_import_service__resolve_upload_from_preflight_path(): void {
		$imports = PluginConfig::imports_dir();
		if ( ! is_dir( $imports ) ) {
			wp_mkdir_p( $imports );
		}

		$staged = trailingslashit( $imports ) . 'preflight-resolve-' . uniqid( '', true ) . '.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp( $staged, array( 'type' => 'full' ), array( 'probe.txt' => 'x' ) );

		$_POST['preflight_staged_path'] = $staged;
		$_POST['preflight_staged_name'] = basename( $staged );

		$resolved = ( new FullSiteImportService() )->resolve_upload( '' );
		self::assertIsArray( $resolved, is_wp_error( $resolved ) ? $resolved->get_error_message() : '' );
		self::assertSame( realpath( $staged ), realpath( (string) $resolved['temp'] ) );
		self::assertSame( basename( $staged ), $resolved['original_name'] );

		@unlink( $staged ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	public function test__full_site_import_service__resolve_upload_rejects_missing_file(): void {
		$resolved = ( new FullSiteImportService() )->resolve_upload( '' );
		self::assertTrue( is_wp_error( $resolved ) );
		self::assertSame( 'mksddn_mc_file_missing', $resolved->get_error_code() );
	}
}
