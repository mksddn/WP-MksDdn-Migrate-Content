<?php
/**
 * Unit tests for EnvironmentVersionComparator.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\EnvironmentVersionComparator;
use WP_Mock\Tools\TestCase;

/**
 * major.minor mismatch warnings.
 */
final class EnvironmentVersionComparatorTest extends TestCase {

	public function test__build_warnings__empty_when_versions_match_major_minor(): void {
		$GLOBALS['wp_version'] = '6.8.2';

		$warnings = ( new EnvironmentVersionComparator() )->build_warnings(
			array(
				'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.99',
				'wp_version'  => '6.8.0',
			)
		);

		self::assertSame( array(), $warnings );
	}

	public function test__build_warnings__warns_when_archive_wp_is_newer(): void {
		$GLOBALS['wp_version'] = '6.7.1';

		$warnings = ( new EnvironmentVersionComparator() )->build_warnings(
			array(
				'wp_version' => '7.1.0',
			)
		);

		self::assertCount( 1, $warnings );
		self::assertStringContainsString( 'WordPress', $warnings[0] );
		self::assertStringContainsString( 'newer', $warnings[0] );
	}

	public function test__build_warnings__skips_missing_versions(): void {
		$GLOBALS['wp_version'] = '6.8.0';

		$warnings = ( new EnvironmentVersionComparator() )->build_warnings( array() );

		self::assertSame( array(), $warnings );
	}

	public function test__summarize__returns_source_and_target_strings(): void {
		$GLOBALS['wp_version'] = '6.8.0';

		$summary = ( new EnvironmentVersionComparator() )->summarize(
			array(
				'php_version' => '8.1.0',
				'wp_version'  => '6.7.1',
			)
		);

		self::assertSame( '8.1.0', $summary['source_php_version'] );
		self::assertSame( '6.7.1', $summary['source_wp_version'] );
		self::assertSame( PHP_VERSION, $summary['target_php_version'] );
		self::assertSame( '6.8.0', $summary['target_wp_version'] );
	}
}
