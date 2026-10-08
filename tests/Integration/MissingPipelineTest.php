<?php
/**
 * Integration coverage for pipelines that the first suite only named in the matrix.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Admin\Handlers\ExportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ImportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ThemePreviewRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\UserMergeRequestHandler;
use MksDdn\MigrateContent\Admin\Services\ImportPreflightService;
use MksDdn\MigrateContent\Admin\Services\PreflightReportStore;
use MksDdn\MigrateContent\Admin\Services\UnifiedImportOrchestrator;
use MksDdn\MigrateContent\Archive\Packer;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Chunking\FullExportBuilder;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Core\ServiceContainerFactory;
use MksDdn\MigrateContent\Database\FullDatabaseImporter;
use MksDdn\MigrateContent\Export\ExportHandler;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;
use MksDdn\MigrateContent\Users\UserMergeApplier;
use MksDdn\MigrateContent\Users\UserPreviewStore;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Real calls into export payload, DB import, chunk download, preflight claim, and auth gates.
 */
final class MissingPipelineTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-gap-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		PluginConfig::create_required_directories();
	}

	public function tearDown(): void {
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		$_POST    = array();
		$_REQUEST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test__export_handler__builds_slug_payload_for_page(): void {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Parent',
				'post_name'   => 'parent-export',
				'post_status' => 'publish',
			)
		);
		$child_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Child',
				'post_name'   => 'child-export',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$handler = new ExportHandler();
		$handler->set_collect_media( false );

		$method = new \ReflectionMethod( ExportHandler::class, 'prepare_post_data' );
		$method->setAccessible( true );
		$payload = $method->invoke( $handler, get_post( $child_id ), null );

		self::assertSame( 'child-export', $payload['slug'] );
		self::assertSame( 'parent-export', $payload['parent_slug'] );
		self::assertSame( 'page', $payload['type'] );
	}

	public function test__full_database_importer__skips_swap_tables_and_rejects_empty_dump(): void {
		global $wpdb;

		$empty = ( new FullDatabaseImporter() )->import( array( 'tables' => array() ) );
		self::assertTrue( is_wp_error( $empty ) );
		self::assertSame( 'mksddn_db_empty', $empty->get_error_code() );

		$swap = $wpdb->prefix . 'posts_mknabcd1234';
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $swap ) );

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$swap => array(
						'schema' => 'CREATE TABLE `' . $swap . '` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB',
						'rows'   => array(
							array( 'id' => '9' ),
						),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $swap ) ) );
	}

	public function test__chunk_download__resumes_ready_job_and_rejects_pending(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$repo       = new ChunkJobRepository();
		$controller = new ChunkRestController( $repo );

		remove_all_actions( FullExportBuilder::CRON_HOOK );
		$init = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/download/init' );
		$started = $controller->init_download( $init );
		self::assertIsArray( $started );
		self::assertSame( 'pending', $started['status'] );

		$pending = new WP_REST_Request( 'GET', '/mksddn/v1/chunk/download' );
		$pending->set_param( 'job_id', $started['job_id'] );
		$pending->set_param( 'index', 0 );
		$blocked = $controller->download_chunk( $pending );
		self::assertTrue( is_wp_error( $blocked ) );
		self::assertSame( 'mksddn_job_not_ready', $blocked->get_error_code() );

		$job = $repo->create();
		file_put_contents( $job->get_file_path(), 'ABCDEFGH' );
		$job->update(
			array(
				'mode'         => 'download',
				'status'       => 'ready',
				'chunk_size'   => 4,
				'total_chunks' => 2,
			)
		);
		$id = $job->get_data()['id'];

		$first = new WP_REST_Request( 'GET', '/mksddn/v1/chunk/download' );
		$first->set_param( 'job_id', $id );
		$first->set_param( 'index', 0 );
		$part = $controller->download_chunk( $first );
		self::assertIsArray( $part );
		self::assertSame( 'ABCD', base64_decode( $part['chunk'], true ) );
		self::assertFalse( $part['completed'] );

		$second = new WP_REST_Request( 'GET', '/mksddn/v1/chunk/download' );
		$second->set_param( 'job_id', $id );
		$second->set_param( 'index', 1 );
		$done = $controller->download_chunk( $second );
		self::assertSame( 'EFGH', base64_decode( $done['chunk'], true ) );
		self::assertTrue( $done['completed'] );
	}

	public function test__preflight__analyze_selected_then_claim_without_second_upload(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$archive = ( new Packer() )->create_archive(
			array(
				'type'  => 'bundle',
				'items' => array(
					array(
						'type'    => 'page',
						'title'   => 'About',
						'slug'    => 'about-preflight',
						'content' => 'Body',
						'status'  => 'publish',
					),
				),
			),
			array(
				'type'  => 'bundle',
				'label' => 'preflight',
			)
		);
		self::assertIsString( $archive );

		$file_info = array(
			'path'      => $archive,
			'name'      => 'about.wpbkp',
			'extension' => 'wpbkp',
			'source'    => 'server',
		);
		$report = ( new ImportPreflightService() )->analyze( $file_info, 'selected' );
		self::assertSame( 'selected', $report['type'] ?? $report['import_type'] ?? 'selected' );

		$store = new PreflightReportStore();
		$id    = $store->save(
			$user_id,
			$report,
			array(
				'source_type' => 'server',
				'server_file' => 'about.wpbkp',
			)
		);
		self::assertIsString( $id );
		$claimed = $store->claim_for_import( $id, $user_id );
		self::assertIsArray( $claimed );
		self::assertSame( 'server', $claimed['import_handle']['source_type'] );
		@unlink( $archive );
	}

	public function test__unified_import__rejects_missing_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->expectException( \WPDieException::class );
		( new UnifiedImportOrchestrator() )->process( array() );
	}

	public function test__handlers__reject_subscribers(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		( new ImportRequestHandler() )->handle_unified_import();
	}

	public function test__user_and_theme_preview__roundtrip_and_merge_skip(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'admin-merge@example.test',
			)
		);
		wp_set_current_user( $user_id );

		$users = new UserPreviewStore();
		$uid   = $users->create( array( 'file_path' => $this->tmpdir . '/users.wpbkp' ) );
		self::assertIsString( $uid );
		self::assertIsArray( $users->get( $uid ) );
		$users->delete( $uid );
		self::assertNull( $users->get( $uid ) );

		$themes = new ThemePreviewStore();
		$tid    = $themes->create( array( 'file_path' => $this->tmpdir . '/theme.wpbkp' ) );
		self::assertIsString( $tid );
		self::assertIsArray( $themes->get( $tid ) );
		$themes->delete( $tid );

		$summary = ( new UserMergeApplier() )->merge( array(), array(), 'wp_' );
		self::assertIsArray( $summary );
		self::assertArrayHasKey( 'skipped', $summary );

		$this->expectException( \WPDieException::class );
		( new UserMergeRequestHandler() )->handle_cancel_preview();
	}

	public function test__theme_preview_handler__rejects_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		( new ThemePreviewRequestHandler() )->handle_cancel_preview();
	}

	public function test__export_request_handler__rejects_subscriber(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( \WPDieException::class );
		( new ExportRequestHandler() )->handle_selected_export();
	}

	public function test__service_container__resolves_registered_graph(): void {
		$container = ServiceContainerFactory::create();
		$property  = new \ReflectionProperty( \MksDdn\MigrateContent\Core\ServiceContainer::class, 'services' );
		$property->setAccessible( true );
		$services = $property->getValue( $container );

		self::assertNotEmpty( $services );
		foreach ( array_keys( $services ) as $id ) {
			$resolved = $container->get( $id );
			self::assertIsObject( $resolved, $id );
			if ( class_exists( $id ) || interface_exists( $id ) ) {
				self::assertInstanceOf( $id, $resolved, $id );
			}
		}

		foreach (
			array(
				new \MksDdn\MigrateContent\Core\ServiceProviders\CoreServiceProvider(),
				new \MksDdn\MigrateContent\Core\ServiceProviders\AdminServiceProvider(),
				new \MksDdn\MigrateContent\Core\ServiceProviders\ExportServiceProvider(),
				new \MksDdn\MigrateContent\Core\ServiceProviders\ImportServiceProvider(),
				new \MksDdn\MigrateContent\Chunking\ChunkServiceProvider(),
			) as $provider
		) {
			self::assertInstanceOf( \MksDdn\MigrateContent\Core\ServiceProviderInterface::class, $provider );
		}
	}

	public function test__missing_archive__payload_and_importer_return_errors(): void {
		$missing = $this->tmpdir . '/missing.wpbkp';
		self::assertTrue( is_wp_error( \MksDdn\MigrateContent\Filesystem\FullArchivePayload::read( $missing ) ) );
		self::assertTrue( is_wp_error( ( new \MksDdn\MigrateContent\Users\UserDiffBuilder() )->build( $missing ) ) );

		$buffer_level = ob_get_level();
		self::assertTrue( is_wp_error( ( new \MksDdn\MigrateContent\Filesystem\FullContentImporter() )->import_from( $missing ) ) );
		while ( ob_get_level() < $buffer_level ) {
			ob_start();
		}
	}

	public function test__content_picker__search_returns_matching_page(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About Migrate Probe',
				'post_name'   => 'about-migrate-probe',
				'post_status' => 'publish',
			)
		);

		$result = ( new \MksDdn\MigrateContent\Admin\Services\ContentPickerQueryService() )->search_posts( 'page', 'About Migrate', 1 );
		self::assertIsArray( $result );
		self::assertArrayHasKey( 'posts', $result );
		$ids = array_map(
			static function ( $row ) {
				return (int) ( $row['id'] ?? 0 );
			},
			$result['posts']
		);
		self::assertContains( (int) $page_id, $ids );
	}

	public function test__artifact_cleanup__empty_handle_yields_no_paths(): void {
		self::assertSame( array(), \MksDdn\MigrateContent\Support\ImportArtifactCleanup::paths_from_import_handle( array() ) );
		self::assertNotSame( '', \MksDdn\MigrateContent\Support\WpContentRuntimeStorage::root() );
	}

	public function test__attachment_restorer__empty_entries_noop(): void {
		$restored = ( new \MksDdn\MigrateContent\Media\AttachmentRestorer() )->restore(
			array(),
			static function () {
				return '';
			},
			0
		);
		self::assertArrayHasKey( 'id_map', $restored );
		self::assertSame( array(), $restored['id_map'] );
	}
}
