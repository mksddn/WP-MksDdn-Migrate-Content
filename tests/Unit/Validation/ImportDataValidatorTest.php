<?php
/**
 * Unit tests for ImportDataValidator.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Validation;

use MksDdn\MigrateContent\Validation\ImportDataValidator;
use WP_Mock\Tools\TestCase;

/**
 * Array-structure import validation.
 */
final class ImportDataValidatorTest extends TestCase {

	public function test__validate_import_data__rejects_empty(): void {
		$result = ( new ImportDataValidator() )->validate_import_data( array(), 'bundle' );
		self::assertFalse( $result->is_valid() );
	}

	public function test__validate_import_data__accepts_bundle_with_items(): void {
		$result = ( new ImportDataValidator() )->validate_import_data(
			array(
				'items' => array(
					array(
						'post_title' => 'Hello',
						'post_type'  => 'post',
					),
				),
			),
			'bundle'
		);
		self::assertTrue( $result->is_valid() );
	}

	public function test__validate_import_data__rejects_empty_bundle_items(): void {
		$result = ( new ImportDataValidator() )->validate_import_data(
			array( 'items' => array() ),
			'bundle'
		);
		self::assertFalse( $result->is_valid() );
	}

	public function test__validate_import_data__requires_title_or_slug_for_item(): void {
		$result = ( new ImportDataValidator() )->validate_import_data(
			array( 'post_type' => 'page' ),
			'page'
		);
		self::assertFalse( $result->is_valid() );
	}

	public function test__validate_import_data__accepts_item_with_slug(): void {
		$result = ( new ImportDataValidator() )->validate_import_data(
			array(
				'post_name' => 'about',
				'post_type' => 'page',
			),
			'page'
		);
		self::assertTrue( $result->is_valid() );
	}
}
