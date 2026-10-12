<?php
/**
 * Integration suite smoke test.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use WP_UnitTestCase;

/**
 * Confirms WordPress test suite loads the plugin.
 */
final class SmokeTest extends WP_UnitTestCase {

	/**
	 * Plugin constants and main class are available after bootstrap.
	 */
	public function test__plugin__is_loaded(): void {
		$this->assertTrue( defined( 'MKSDDN_MC_VERSION' ) );
		$this->assertTrue( class_exists( \MksDdn\MigrateContent\Plugin::class ) );
		$this->assertTrue( function_exists( 'mksddn_mc_meets_requirements' ) );
		$this->assertTrue( mksddn_mc_meets_requirements() );
	}
}
