<?php
/**
 * Unit tests for DomainReplacer (serialized-safe URL/path replace).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Support;

use MksDdn\MigrateContent\Support\DomainReplacer;
use WP_Mock\Tools\TestCase;

/**
 * Critical regression coverage for dump environment rewrite.
 */
final class DomainReplacerTest extends TestCase {

	public function test__replace_dump_environment__rewrites_plain_urls(): void {
		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'option_value' => 'https://old.example/about',
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		self::assertSame( 'https://new.example/about', $dump['tables'][0]['rows'][0]['option_value'] );
	}

	public function test__replace_dump_environment__rewrites_serialized_urls(): void {
		$payload = serialize(
			array(
				'url' => 'https://old.example/page',
			)
		);

		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'meta_value' => $payload,
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		$updated = unserialize( $dump['tables'][0]['rows'][0]['meta_value'] );
		self::assertIsArray( $updated );
		self::assertSame( 'https://new.example/page', $updated['url'] );
	}

	public function test__replace_dump_environment__rewrites_filesystem_paths(): void {
		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'paths'    => array(
				'ABSPATH' => '/var/www/old',
			),
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'option_value' => '/var/www/old/wp-content/uploads/a.jpg',
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array(
				'ABSPATH' => '/var/www/new',
			)
		);

		self::assertSame(
			'/var/www/new/wp-content/uploads/a.jpg',
			$dump['tables'][0]['rows'][0]['option_value']
		);
	}

	public function test__replace_dump_environment__noop_without_tables(): void {
		$dump = array(
			'site_url' => 'https://old.example',
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		self::assertArrayNotHasKey( 'tables', $dump );
	}

	public function test__replace_dump_environment__rewrites_nested_serialized_string(): void {
		// Same host length so str_replace on nested blob keeps PHP serialize lengths valid.
		$inner = serialize(
			array(
				'url' => 'https://old.example/page',
			)
		);
		$outer = serialize(
			array(
				'inner' => $inner,
			)
		);

		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'meta_value' => $outer,
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		$updated_outer = unserialize( $dump['tables'][0]['rows'][0]['meta_value'] );
		self::assertIsArray( $updated_outer );
		self::assertArrayHasKey( 'inner', $updated_outer );
		$updated_inner = unserialize( $updated_outer['inner'] );
		self::assertIsArray( $updated_inner );
		self::assertSame( 'https://new.example/page', $updated_inner['url'] );
	}

	public function test__replace_dump_environment__rewrites_object_property_urls(): void {
		$object      = new \stdClass();
		$object->url = 'https://old.example/obj';
		$payload     = serialize( $object );

		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'meta_value' => $payload,
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		$updated = unserialize( $dump['tables'][0]['rows'][0]['meta_value'] );
		self::assertInstanceOf( \stdClass::class, $updated );
		self::assertSame( 'https://new.example/obj', $updated->url );
	}

	public function test__replace_dump_environment__broken_serialized_is_str_replaced(): void {
		// is_serialized() true, unserialize() fails — current policy is str_replace + normalize.
		$broken = 'a:1:{s:3:"url";s:999:"https://old.example/x";}';

		$dump = array(
			'site_url' => 'https://old.example',
			'home_url' => 'https://old.example',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'option_value' => $broken,
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		$result = $dump['tables'][0]['rows'][0]['option_value'];
		self::assertIsString( $result );
		self::assertStringContainsString( 'new.example', $result );
		self::assertStringNotContainsString( 'old.example', $result );
	}

	public function test__replace_dump_environment__port_and_subdirectory_without_port_glue(): void {
		$dump = array(
			'site_url' => 'http://old.example:8080/blog',
			'home_url' => 'http://old.example:8080/blog',
			'tables'   => array(
				array(
					'rows' => array(
						array(
							'option_value' => 'http://old.example:8080/blog/about',
						),
					),
				),
			),
		);

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example/wp',
			array()
		);

		$value = $dump['tables'][0]['rows'][0]['option_value'];
		self::assertSame( 'https://new.example/wp/about', $value );
		self::assertStringNotContainsString( ':8080:8080', $value );
	}
}
