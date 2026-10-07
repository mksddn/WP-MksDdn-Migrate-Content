<?php
/**
 * Unit tests for ImportRequest DTO.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\DataTransferObjects;

use MksDdn\MigrateContent\DataTransferObjects\ImportRequest;
use WP_Mock\Tools\TestCase;

/**
 * ImportRequest factories and flags.
 */
final class ImportRequestTest extends TestCase {

	public function test__from_array__defaults_and_is_full(): void {
		$request = ImportRequest::from_array(
			array(
				'file_path' => '/tmp/a.wpbkp',
			)
		);

		self::assertTrue( $request->is_full() );
		self::assertSame( '/tmp/a.wpbkp', $request->file_path );
		self::assertTrue( $request->replace_urls );
	}
}
