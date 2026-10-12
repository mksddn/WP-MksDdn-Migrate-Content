<?php
/**
 * Integration: chunk REST upload/resume protocol.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Config\PluginConfig;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Init + upload chunks and clamp chunk_size.
 */
final class ChunkRestResumeTest extends WP_UnitTestCase {

	/** @var int */
	private int $admin_id;

	public function setUp(): void {
		parent::setUp();
		PluginConfig::create_required_directories();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test__chunk_upload__assembles_file_and_clamps_size(): void {
		$controller = new ChunkRestController( new ChunkJobRepository() );

		$init = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/init' );
		$init->set_param( 'total_chunks', 2 );
		$init->set_param( 'chunk_size', 99999999 ); // above max → clamp to 5MB default path.
		$init->set_param( 'checksum', 'abc' );

		$init_response = $controller->init_job( $init );
		self::assertIsArray( $init_response );
		self::assertArrayHasKey( 'job_id', $init_response );
		self::assertSame( 5242880, $init_response['chunk_size'] );

		$job_id = $init_response['job_id'];
		$part1  = 'Hello ';
		$part2  = 'World';

		$upload1 = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/upload' );
		$upload1->set_param( 'job_id', $job_id );
		$upload1->set_param( 'index', 0 );
		$upload1->set_param( 'chunk', base64_encode( $part1 ) );
		$r1 = $controller->upload_chunk( $upload1 );
		self::assertIsArray( $r1 );
		self::assertFalse( $r1['completed'] );

		$upload2 = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/upload' );
		$upload2->set_param( 'job_id', $job_id );
		$upload2->set_param( 'index', 1 );
		$upload2->set_param( 'chunk', base64_encode( $part2 ) );
		$r2 = $controller->upload_chunk( $upload2 );
		self::assertIsArray( $r2 );
		self::assertTrue( $r2['completed'] );

		$job  = ( new ChunkJobRepository() )->get( $job_id );
		$file = $job->get_file_path();
		self::assertFileExists( $file );
		self::assertSame( 'Hello World', (string) file_get_contents( $file ) );

		$status_req = new WP_REST_Request( 'GET', '/mksddn/v1/chunk/status' );
		$status_req->set_param( 'job_id', $job_id );
		$status = $controller->get_status( $status_req );
		self::assertIsArray( $status );

		$cancel = new WP_REST_Request( 'POST', '/mksddn/v1/chunk/cancel' );
		$cancel->set_param( 'job_id', $job_id );
		$controller->cancel_job( $cancel );
	}

	public function test__ensure_permission__requires_manage_options(): void {
		$controller = new ChunkRestController( new ChunkJobRepository() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		self::assertFalse( $controller->ensure_permission() );
		wp_set_current_user( $this->admin_id );
		self::assertTrue( $controller->ensure_permission() );
	}
}
