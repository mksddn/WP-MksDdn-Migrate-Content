<?php
/**
 * Unit tests for ThemeArchivePathHelper.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\ThemeArchivePathHelper;
use WP_Mock\Tools\TestCase;

/**
 * Path normalization and traversal rejection.
 */
final class ThemeArchivePathHelperTest extends TestCase {

	public function test__normalize__accepts_theme_file_path(): void {
		self::assertSame(
			'wp-content/themes/twentytwentyfour/style.css',
			ThemeArchivePathHelper::normalize( 'wp-content/themes/twentytwentyfour/style.css' )
		);
	}

	public function test__normalize__strips_files_prefix(): void {
		self::assertSame(
			'wp-content/themes/my-theme/functions.php',
			ThemeArchivePathHelper::normalize( 'files/wp-content/themes/my-theme/functions.php' )
		);
	}

	public function test__normalize__rejects_path_traversal(): void {
		self::assertNull( ThemeArchivePathHelper::normalize( 'files/../wp-config.php' ) );
		self::assertNull( ThemeArchivePathHelper::normalize( 'wp-content/themes/../../etc/passwd' ) );
	}

	public function test__normalize__skips_manifest_and_payload(): void {
		self::assertNull( ThemeArchivePathHelper::normalize( 'manifest.json' ) );
		self::assertNull( ThemeArchivePathHelper::normalize( 'payload/content.json' ) );
	}

	public function test__normalize__rejects_null_byte(): void {
		self::assertNull( ThemeArchivePathHelper::normalize( "wp-content/themes/x\0evil.css" ) );
	}

	public function test__normalize__converts_backslashes_and_strips_leading_slash(): void {
		self::assertSame(
			'wp-content/themes/my-theme/style.css',
			ThemeArchivePathHelper::normalize( 'wp-content\\themes\\my-theme\\style.css' )
		);
		self::assertSame(
			'wp-content/themes/my-theme/style.css',
			ThemeArchivePathHelper::normalize( '/wp-content/themes/my-theme/style.css' )
		);
	}

	public function test__normalize__rejects_traversal_after_dot_segment(): void {
		// continue (not break) on "." must still evaluate later ".." segments.
		self::assertNull( ThemeArchivePathHelper::normalize( 'wp-content/themes/./../../evil.txt' ) );
	}

	public function test__normalize__files_prefix_only_strips_six_chars(): void {
		self::assertSame(
			'wp-content/themes/a/b.css',
			ThemeArchivePathHelper::normalize( 'files/wp-content/themes/a/b.css' )
		);
		self::assertNull( ThemeArchivePathHelper::normalize( 'files/' ) );
	}
}
