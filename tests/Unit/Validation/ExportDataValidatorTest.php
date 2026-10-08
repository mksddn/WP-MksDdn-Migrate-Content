<?php
/**
 * Unit tests for ExportDataValidator.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Validation;

use MksDdn\MigrateContent\Validation\ExportDataValidator;
use WP_Mock\Tools\TestCase;

/**
 * Export request validation.
 */
final class ExportDataValidatorTest extends TestCase {

	public function test__validate_export_request__requires_selection_for_selected(): void {
		$result = ( new ExportDataValidator() )->validate_export_request( array(), 'selected' );
		self::assertFalse( $result->is_valid() );

		// Empty selected_* arrays and unrelated keys must not count as a selection.
		$empty = ( new ExportDataValidator() )->validate_export_request(
			array(
				'selected_page_ids' => array(),
				'other_ids'         => array( 1, 2 ),
			),
			'selected'
		);
		self::assertFalse( $empty->is_valid() );
	}

	public function test__validate_export_request__accepts_selected_ids(): void {
		$result = ( new ExportDataValidator() )->validate_export_request(
			array(
				'selected_page_ids' => array( 1, 2 ),
				'export_format'     => 'archive',
			),
			'selected'
		);
		self::assertTrue( $result->is_valid() );
	}

	public function test__validate_export_request__rejects_invalid_format(): void {
		$result = ( new ExportDataValidator() )->validate_export_request(
			array(
				'selected_post_ids' => array( 5 ),
				'export_format'     => 'xml',
			),
			'selected'
		);
		self::assertFalse( $result->is_valid() );
	}

	public function test__validate_export_request__rejects_unknown_type(): void {
		$result = ( new ExportDataValidator() )->validate_export_request( array(), 'mystery' );
		self::assertFalse( $result->is_valid() );
	}

	public function test__validate_export_request__accepts_full(): void {
		$result = ( new ExportDataValidator() )->validate_export_request( array(), 'full' );
		self::assertTrue( $result->is_valid() );
	}
}
