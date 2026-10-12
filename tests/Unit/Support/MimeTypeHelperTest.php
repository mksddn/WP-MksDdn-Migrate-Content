<?php
/**
 * Unit tests for MimeTypeHelper.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\MimeTypeHelper;
use WP_Mock\Tools\TestCase;

/**
 * Extension fallback MIME map.
 */
final class MimeTypeHelperTest extends TestCase {

	public function test__detect__falls_back_to_extension_map(): void {
		self::assertSame( 'application/zip', MimeTypeHelper::detect( '/no/such/file.wpbkp', 'wpbkp' ) );
		self::assertSame( 'application/json', MimeTypeHelper::detect( '/no/such/file.json', 'json' ) );
		self::assertSame( 'application/octet-stream', MimeTypeHelper::detect( '/no/such/file.bin', 'bin' ) );
	}
}
