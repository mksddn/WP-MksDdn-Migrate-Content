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
}
