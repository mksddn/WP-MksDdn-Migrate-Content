<?php
/**
 * Unit tests for FilenameBuilder.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\FilenameBuilder;
use Unitest_WP_Copy\WP_Options;
use WP_Mock\Tools\TestCase;

/**
 * Deterministic export filenames.
 */
final class FilenameBuilderTest extends TestCase {

	public function test__build__includes_host_type_and_extension(): void {
		WP_Options::set( 'home', 'https://example.test' );
		WP_Options::set( 'siteurl', 'https://example.test' );

		$name = FilenameBuilder::build( 'selected-content', 'wpbkp' );
		self::assertStringContainsString( 'example.test', $name );
		self::assertStringContainsString( 'selected-content', $name );
		self::assertStringEndsWith( '.wpbkp', $name );
	}
}
