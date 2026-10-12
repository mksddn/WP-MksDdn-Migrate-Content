<?php
/**
 * Unit suite smoke test.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit;

use MksDdn\MigrateContent\Validation\ValidationResult;
use WP_Mock\Tools\TestCase;

/**
 * Confirms unit bootstrap + plugin PSR-4 autoload work.
 */
final class SmokeTest extends TestCase {

	/**
	 * Plugin classes load under unitest runtime.
	 */
	public function test__autoload__loads_plugin_class(): void {
		$result = ValidationResult::success();
		self::assertTrue( $result->is_valid() );
		self::assertTrue( defined( 'ABSPATH' ) );
		self::assertTrue( defined( 'MKSDDN_MC_DIR' ) );
	}
}
