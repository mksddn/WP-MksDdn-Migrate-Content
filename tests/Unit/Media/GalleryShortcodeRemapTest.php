<?php
/**
 * Unit tests for gallery shortcode ID remapping.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Media;

use MksDdn\MigrateContent\Media\AttachmentRestorer;
use WP_Mock\Tools\TestCase;

/**
 * Gallery ids attributes keep their quoting when attachment IDs change.
 */
final class GalleryShortcodeRemapTest extends TestCase {

	public function test__remap__double_and_single_quotes_and_spaced_equals(): void {
		$content = '[gallery ids="12, 34"] [gallery ids=\'56\'] [gallery columns="2" ids = "12"]';
		$mapped  = AttachmentRestorer::remap_gallery_shortcode_ids(
			$content,
			array(
				12 => 120,
				34 => 340,
				56 => 560,
			)
		);

		self::assertSame(
			'[gallery ids="120,340"] [gallery ids=\'560\'] [gallery columns="2" ids="120"]',
			$mapped
		);
	}

	public function test__remap__leaves_unknown_ids(): void {
		self::assertSame(
			'[gallery ids="9"]',
			AttachmentRestorer::remap_gallery_shortcode_ids( '[gallery ids="9"]', array( 1 => 2 ) )
		);
	}
}
