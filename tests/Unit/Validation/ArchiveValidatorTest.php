<?php
/**
 * Unit tests for ArchiveValidator.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Validation;

use MksDdn\MigrateContent\Validation\ArchiveValidator;
use WP_Mock\Tools\TestCase;

/**
 * Array/manifest archive validation.
 */
final class ArchiveValidatorTest extends TestCase {

	public function test__validate_archive__requires_payload_and_type(): void {
		$validator = new ArchiveValidator();
		self::assertFalse( $validator->validate_archive( array() )->is_valid() );
		self::assertFalse(
			$validator->validate_archive(
				array(
					'payload' => array( 'x' => 1 ),
				)
			)->is_valid()
		);
		self::assertFalse(
			$validator->validate_archive(
				array(
					'payload' => array( 'x' => 1 ),
					'type'    => '',
				)
			)->is_valid()
		);
		self::assertTrue(
			$validator->validate_archive(
				array(
					'payload' => array( 'x' => 1 ),
					'type'    => 'bundle',
				)
			)->is_valid()
		);
	}

	public function test__validate_manifest__requires_version_and_type(): void {
		$validator = new ArchiveValidator();
		self::assertFalse( $validator->validate_manifest( array() )->is_valid() );
		self::assertTrue(
			$validator->validate_manifest(
				array(
					'version' => 1,
					'type'    => 'full',
				)
			)->is_valid()
		);
	}
}
