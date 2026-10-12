<?php
/**
 * Unit tests for ChunkJob status CAS protocol.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Chunking;

use MksDdn\MigrateContent\Chunking\ChunkJob;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_Mock\Tools\TestCase;

/**
 * update_if_status CAS without full WP filesystem bootstrap.
 *
 * Uses a thin FilesystemHelper polyfill via direct JSON files + reflection
 * when FilesystemHelper cannot load WP_Filesystem_Direct in unit runtime.
 */
final class ChunkJobTest extends TestCase {

	/** @var string */
	private string $tmpdir;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-chunk-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__update_if_status__updates_when_status_matches(): void {
		$job_id = 'jobabc123';
		$path   = $this->tmpdir . '/' . $job_id . '.json';
		file_put_contents(
			$path,
			(string) wp_json_encode(
				array(
					'id'     => $job_id,
					'status' => 'running',
					'size'   => 0,
				)
			)
		);

		$job = $this->make_job_without_filesystem( $job_id, $this->tmpdir . '/' );
		self::assertTrue( $job->update_if_status( 'running', array( 'status' => 'completed', 'size' => 10 ) ) );

		$stored = json_decode( (string) file_get_contents( $path ), true );
		self::assertSame( 'completed', $stored['status'] );
		self::assertSame( 10, $stored['size'] );
	}

	public function test__update_if_status__rejects_when_status_differs(): void {
		$job_id = 'jobxyz789';
		$path   = $this->tmpdir . '/' . $job_id . '.json';
		file_put_contents(
			$path,
			(string) wp_json_encode(
				array(
					'id'     => $job_id,
					'status' => 'cancelled',
				)
			)
		);

		$job = $this->make_job_without_filesystem( $job_id, $this->tmpdir . '/' );
		self::assertFalse( $job->update_if_status( array( 'running', 'pending' ), array( 'status' => 'completed' ) ) );

		$stored = json_decode( (string) file_get_contents( $path ), true );
		self::assertSame( 'cancelled', $stored['status'] );
	}

	/**
	 * Construct ChunkJob without invoking FilesystemHelper::load/save.
	 */
	private function make_job_without_filesystem( string $id, string $dir ): ChunkJob {
		$ref  = new \ReflectionClass( ChunkJob::class );
		$job  = $ref->newInstanceWithoutConstructor();
		$id_p = $ref->getProperty( 'id' );
		$id_p->setAccessible( true );
		$id_p->setValue( $job, $id );
		$dir_p = $ref->getProperty( 'dir' );
		$dir_p->setAccessible( true );
		$dir_p->setValue( $job, $dir );
		$data_p = $ref->getProperty( 'data' );
		$data_p->setAccessible( true );
		$data_p->setValue( $job, array() );
		return $job;
	}
}
