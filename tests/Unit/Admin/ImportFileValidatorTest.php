<?php
/**
 * Unit tests for ImportFileValidator.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Admin;

use MksDdn\MigrateContent\Admin\Services\ImportFileValidator;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_Error;
use WP_Mock\Tools\TestCase;

/**
 * Extension / size / upload-error validation for import uploads.
 */
final class ImportFileValidatorTest extends TestCase {

	/** @var string */
	private string $tmpdir;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-ifv-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__validate__rejects_upload_error(): void {
		$result = ( new ImportFileValidator() )->validate(
			array(
				'error'    => UPLOAD_ERR_INI_SIZE,
				'tmp_name' => '',
				'name'     => 'x.wpbkp',
				'size'     => 10,
			)
		);
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'mksddn_mc_upload_failed', $result->get_error_code() );
	}

	public function test__validate__rejects_zero_size(): void {
		$path = $this->tmpdir . '/empty.wpbkp';
		file_put_contents( $path, '' );
		$result = ( new ImportFileValidator() )->validate(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => $path,
				'name'     => 'empty.wpbkp',
				'size'     => 0,
			)
		);
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'mksddn_mc_invalid_size', $result->get_error_code() );
	}

	public function test__validate__rejects_unsupported_extension(): void {
		$path = $this->tmpdir . '/payload.txt';
		file_put_contents( $path, 'hello' );
		$result = ( new ImportFileValidator() )->validate(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => $path,
				'name'     => 'payload.txt',
				'size'     => 5,
			)
		);
		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'mksddn_mc_invalid_type', $result->get_error_code() );
	}

	public function test__validate__accepts_wpbkp_and_json(): void {
		$zip = $this->tmpdir . '/ok.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp( $zip, array( 'type' => 'selected' ), array() );
		$json = $this->tmpdir . '/ok.json';
		file_put_contents( $json, '{"items":[]}' );

		$validator = new ImportFileValidator();

		$zip_ok = $validator->validate(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => $zip,
				'name'     => 'ok.wpbkp',
				'size'     => filesize( $zip ),
			)
		);
		self::assertIsArray( $zip_ok );
		self::assertSame( 'wpbkp', $zip_ok['extension'] );

		$json_ok = $validator->validate(
			array(
				'error'    => UPLOAD_ERR_OK,
				'tmp_name' => $json,
				'name'     => 'ok.json',
				'size'     => filesize( $json ),
			)
		);
		self::assertIsArray( $json_ok );
		self::assertSame( 'json', $json_ok['extension'] );
	}
}
