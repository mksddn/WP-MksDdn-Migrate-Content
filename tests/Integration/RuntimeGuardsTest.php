<?php
/**
 * Integration: export preflight filter, chunk cancel, lock, site URL, maintenance.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\ExportPreflight;
use MksDdn\MigrateContent\Support\FullImportMaintenance;
use MksDdn\MigrateContent\Support\ImportLock;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Runtime guards isolated from import-directory cleanup.
 */
final class RuntimeGuardsTest extends WP_UnitTestCase {

	/** @var int */
	private int $admin_id = 0;

	/** @var string */
	private string $saved_siteurl = '';

	/** @var string */
	private string $saved_home = '';

	public function setUp(): void {
		parent::setUp();
		PluginConfig::create_required_directories();
		$this->admin_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->saved_siteurl = (string) get_option( 'siteurl' );
		$this->saved_home    = (string) get_option( 'home' );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		remove_all_filters( 'mksddn_mc_export_preflight' );
		FullImportMaintenance::deactivate();
		update_option( 'siteurl', $this->saved_siteurl );
		update_option( 'home', $this->saved_home );
		delete_transient( 'mksddn_mc_import_lock' );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test__export_preflight__filter_can_abort(): void {
		add_filter(
			'mksddn_mc_export_preflight',
			static function () {
				return new WP_Error( 'mksddn_mc_export_filtered', 'blocked by filter' );
			}
		);

		$result = ( new ExportPreflight() )->validate_full_export();
		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'mksddn_mc_export_filtered', $result->get_error_code() );
	}

	public function test__chunk_cancel__pending_vs_ready(): void {
		$repo       = new ChunkJobRepository();
		$controller = new ChunkRestController( $repo );

		$pending = $repo->create();
		$pending->update(
			array(
				'mode'   => 'download',
				'status' => 'pending',
			)
		);
		$pending_id = $pending->get_data()['id'];

		$req = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/cancel' );
		$req->set_param( 'job_id', $pending_id );
		$response = $controller->cancel_job( $req );
		self::assertIsArray( $response );
		self::assertTrue( ! empty( $response['cancelled'] ) );

		$still = $repo->get( $pending_id );
		self::assertSame( 'cancelled', $still->get_data()['status'] ?? '' );

		$ready = $repo->create();
		$file  = $ready->get_file_path();
		file_put_contents( $file, 'download-bytes' );
		$ready->update(
			array(
				'mode'   => 'download',
				'status' => 'ready',
				'size'   => 14,
			)
		);
		$ready_id = $ready->get_data()['id'];

		$req2 = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/cancel' );
		$req2->set_param( 'job_id', $ready_id );
		$response2 = $controller->cancel_job( $req2 );
		self::assertIsArray( $response2 );
		self::assertTrue( ! empty( $response2['deleted'] ) );
		self::assertFileDoesNotExist( $file );
	}

	public function test__import_lock__clears_stale_transient(): void {
		delete_transient( 'mksddn_mc_import_lock' );
		set_transient(
			'mksddn_mc_import_lock',
			array(
				'token'      => 'stale-token',
				'created_at' => time() - 700,
			),
			900
		);

		$lock  = new ImportLock();
		$token = $lock->acquire( 60 );
		self::assertNotFalse( $token );
		$lock->release( (string) $token );
	}

	public function test__site_url_guard__restore_roundtrip(): void {
		$guard = new SiteUrlGuard( $this->saved_siteurl, $this->saved_home );
		update_option( 'siteurl', 'https://mutated.example' );
		update_option( 'home', 'https://mutated.example' );
		$guard->restore();
		// tearDown restores originals; assert restore changed something back or applied request host.
		self::assertNotSame( 'https://mutated.example', get_option( 'siteurl' ) );
	}

	public function test__full_import_maintenance__activate_deactivate(): void {
		$maintenance = ABSPATH . '.maintenance';
		$existed     = is_file( $maintenance );
		$backup      = $existed ? (string) file_get_contents( $maintenance ) : null;

		try {
			FullImportMaintenance::activate();
			self::assertTrue( FullImportMaintenance::is_active() );
			self::assertFileExists( $maintenance );
		} finally {
			FullImportMaintenance::deactivate();
		}

		self::assertFalse( FullImportMaintenance::is_active() );
		if ( $existed && null !== $backup ) {
			file_put_contents( $maintenance, $backup );
		} else {
			self::assertFileDoesNotExist( $maintenance );
		}
	}
}
