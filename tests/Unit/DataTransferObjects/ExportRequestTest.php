<?php
/**
 * Unit tests for ExportRequest DTO.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\DataTransferObjects;

use MksDdn\MigrateContent\DataTransferObjects\ExportRequest;
use WP_Mock\Tools\TestCase;

/**
 * ExportRequest factories and flags.
 */
final class ExportRequestTest extends TestCase {

	public function test__from_array__and_mode_helpers(): void {
		$request = ExportRequest::from_array(
			array(
				'mode'          => 'selected',
				'selected_ids'  => array( 'page' => array( 1 ) ),
				'format'        => 'json',
				'include_media' => false,
			)
		);

		self::assertTrue( $request->is_selected() );
		self::assertFalse( $request->is_full() );
		self::assertSame( 'json', $request->format );
		self::assertFalse( $request->include_media );
	}
}
