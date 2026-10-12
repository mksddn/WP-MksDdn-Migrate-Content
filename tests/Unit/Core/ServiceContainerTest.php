<?php
/**
 * Unit tests for ServiceContainer.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Core;

use MksDdn\MigrateContent\Core\ServiceContainer;
use WP_Mock\Tools\TestCase;

/**
 * DI container register / get / singleton.
 */
final class ServiceContainerTest extends TestCase {

	public function test__get__returns_singleton_instance(): void {
		$container = new ServiceContainer();
		$container->register(
			'counter',
			static function () {
				return new \stdClass();
			},
			true
		);

		$a = $container->get( 'counter' );
		$b = $container->get( 'counter' );

		self::assertSame( $a, $b );
		self::assertTrue( $container->has( 'counter' ) );
	}

	public function test__get__throws_for_unknown_service(): void {
		$container = new ServiceContainer();
		$this->expectException( \RuntimeException::class );
		$container->get( 'missing' );
	}

	public function test__clear__resets_singletons(): void {
		$container = new ServiceContainer();
		$container->register(
			'obj',
			static function () {
				return new \stdClass();
			}
		);
		$first = $container->get( 'obj' );
		$container->clear();
		$second = $container->get( 'obj' );

		self::assertNotSame( $first, $second );
	}
}
