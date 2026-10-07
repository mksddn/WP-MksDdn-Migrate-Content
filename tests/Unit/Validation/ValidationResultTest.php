<?php
/**
 * Unit tests for ValidationResult.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Validation;

use MksDdn\MigrateContent\Validation\ValidationResult;
use WP_Mock\Tools\TestCase;

/**
 * Success / failure DTO behaviour.
 */
final class ValidationResultTest extends TestCase {

	public function test__success__is_valid_without_errors(): void {
		$result = ValidationResult::success();
		self::assertTrue( $result->is_valid() );
		self::assertSame( array(), $result->get_errors() );
		self::assertSame( '', $result->get_first_error() );
	}

	public function test__failure__accepts_string_or_array(): void {
		$one = ValidationResult::failure( 'Broken' );
		self::assertFalse( $one->is_valid() );
		self::assertSame( 'Broken', $one->get_first_error() );

		$many = ValidationResult::failure( array( 'a', 'b' ) );
		self::assertSame( array( 'a', 'b' ), $many->get_errors() );
	}
}
