<?php
/**
 * Unit tests for SwapTableNames.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Database;

use MksDdn\MigrateContent\Database\SwapTableNames;
use WP_Mock\Tools\TestCase;

/**
 * Covers swap / legacy backup table name detection.
 */
final class SwapTableNamesTest extends TestCase {

	public function test__is_new_swap__detects_mkn_suffix(): void {
		self::assertTrue( SwapTableNames::is_new_swap( 'wp_posts_mknabcd1234' ) );
		self::assertFalse( SwapTableNames::is_new_swap( 'wp_posts_mkoabcd1234' ) );
		self::assertFalse( SwapTableNames::is_new_swap( 'wp_posts' ) );
	}

	public function test__is_old_copy__detects_mko_and_legacy(): void {
		self::assertTrue( SwapTableNames::is_old_copy( 'wp_posts_mkoabcd1234' ) );
		self::assertTrue( SwapTableNames::is_old_copy( 'wp_posts_mksddn_bak' ) );
		self::assertFalse( SwapTableNames::is_old_copy( 'wp_posts_mknabcd1234' ) );
	}

	public function test__is_internal__matches_all_swap_forms(): void {
		self::assertTrue( SwapTableNames::is_internal( 'wp_options_mkn11223344' ) );
		self::assertTrue( SwapTableNames::is_internal( 'wp_options_mko11223344' ) );
		self::assertTrue( SwapTableNames::is_internal( 'wp_options_mksddn_bak' ) );
		self::assertFalse( SwapTableNames::is_internal( 'wp_options' ) );
	}

	public function test__recoverable_original_name__strips_suffix_when_not_truncated(): void {
		self::assertSame( 'wp_posts', SwapTableNames::recoverable_original_name( 'wp_posts_mknabcd1234' ) );
		self::assertSame( 'wp_posts', SwapTableNames::recoverable_original_name( 'wp_posts_mksddn_bak' ) );
	}

	public function test__recoverable_original_name__returns_empty_when_name_is_64_chars(): void {
		$base = str_repeat( 'a', 52 );
		$name = $base . '_mknabcd1234';
		self::assertSame( 64, strlen( $name ) );
		self::assertSame( '', SwapTableNames::recoverable_original_name( $name ) );
	}
}
