<?php
/**
 * Unit tests for Packer + Extractor roundtrip and checksum guard.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Archive;

use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Archive\Packer;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_Mock\Tools\TestCase;
use ZipArchive;

/**
 * Archive integrity with ZipArchive fixtures.
 */
final class PackerExtractorTest extends TestCase {

	/** @var string */
	private string $tmpdir;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-archive-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__packer_extractor__roundtrip_preserves_payload(): void {
		$temp = $this->tmpdir . '/created.wpbkp';
		touch( $temp ); // wp_tempnam creates an empty file ZipArchive can overwrite.
		\WP_Mock::userFunction( 'wp_tempnam' )->andReturn( $temp );

		$packer = new Packer();
		$path   = $packer->create_archive(
			array(
				'items' => array(
					array(
						'post_title' => 'Hello',
						'post_name'  => 'hello',
						'post_type'  => 'page',
					),
				),
			),
			array(
				'type'  => 'bundle',
				'label' => 'Test',
			)
		);

		self::assertIsString( $path );
		self::assertFileExists( $path );

		$extracted = ( new Extractor() )->extract( $path );

		self::assertIsArray( $extracted );
		self::assertSame( 'bundle', $extracted['type'] );
		self::assertSame( 'hello', $extracted['payload']['items'][0]['post_name'] );
	}

	public function test__extractor__rejects_checksum_mismatch(): void {
		$payload = wp_json_encode( array( 'items' => array() ) );
		$bad     = $this->tmpdir . '/bad.wpbkp';

		$zip = new ZipArchive();
		$zip->open( $bad, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$manifest = wp_json_encode(
			array(
				'type'     => 'bundle',
				'checksum' => 'deadbeef',
			)
		);
		$zip->addFromString( 'manifest.json', (string) $manifest );
		$zip->addFromString( 'payload/content.json', (string) $payload );
		$zip->close();

		$result = ( new Extractor() )->extract( $bad );
		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'mksddn_mc_checksum_mismatch', $result->get_error_code() );
	}
}
