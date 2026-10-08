<?php
/**
 * Unit tests for FileUpload DTO.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\DataTransferObjects;

use MksDdn\MigrateContent\DataTransferObjects\FileUpload;
use WP_Mock\Tools\TestCase;

/**
 * FileUpload construction.
 */
final class FileUploadTest extends TestCase {

	public function test__constructor__stores_fields(): void {
		$upload = new FileUpload( '/tmp/a.wpbkp', 'a.wpbkp', 10, 'application/zip', 'wpbkp' );
		self::assertSame( '/tmp/a.wpbkp', $upload->file_path );
		self::assertSame( 'a.wpbkp', $upload->original_name );
		self::assertSame( 10, $upload->file_size );
		self::assertSame( 'wpbkp', $upload->extension );
	}
}
