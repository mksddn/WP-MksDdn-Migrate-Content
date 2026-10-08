<?php
/**
 * Unit tests for ExportResult DTO.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\DataTransferObjects;

use MksDdn\MigrateContent\DataTransferObjects\ExportResult;
use WP_Mock\Tools\TestCase;

/**
 * ExportResult construction.
 */
final class ExportResultTest extends TestCase {

	public function test__constructor__stores_success_payload(): void {
		$result = new ExportResult( true, '/tmp/out.wpbkp', 42, array( 'type' => 'full' ), '' );
		self::assertTrue( $result->success );
		self::assertSame( 42, $result->file_size );
		self::assertSame( 'full', $result->metadata['type'] );
	}
}
