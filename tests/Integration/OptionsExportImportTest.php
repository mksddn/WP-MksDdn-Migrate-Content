<?php
/**
 * Integration: OptionsExporter ↔ OptionsImporter roundtrips.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Options\OptionsExporter;
use MksDdn\MigrateContent\Options\OptionsImporter;
use WP_UnitTestCase;

/**
 * Export selected options / widgets and restore with overwrite modes.
 */
final class OptionsExportImportTest extends WP_UnitTestCase {

	public function test__export_import__options_roundtrip_and_skip_missing(): void {
		update_option( 'mksddn_mc_opt_a', array( 'nested' => 1 ) );
		update_option( 'mksddn_mc_opt_b', 'keep-me' );

		$exported = ( new OptionsExporter() )->export_options(
			array(
				'mksddn_mc_opt_a',
				'mksddn_mc_opt_missing',
				'mksddn_mc_opt_b',
			)
		);

		self::assertArrayHasKey( 'mksddn_mc_opt_a', $exported );
		self::assertArrayHasKey( 'mksddn_mc_opt_b', $exported );
		self::assertArrayNotHasKey( 'mksddn_mc_opt_missing', $exported );
		self::assertSame( array( 'nested' => 1 ), $exported['mksddn_mc_opt_a'] );

		delete_option( 'mksddn_mc_opt_a' );
		update_option( 'mksddn_mc_opt_b', 'local-changed' );

		( new OptionsImporter() )->import_options( $exported, true );
		self::assertSame( array( 'nested' => 1 ), get_option( 'mksddn_mc_opt_a' ) );
		self::assertSame( 'keep-me', get_option( 'mksddn_mc_opt_b' ) );

		update_option( 'mksddn_mc_opt_b', 'local-again' );
		( new OptionsImporter() )->import_options(
			array(
				'mksddn_mc_opt_b' => 'from-archive',
				'mksddn_mc_opt_c' => 'created',
			),
			false
		);
		self::assertSame( 'local-again', get_option( 'mksddn_mc_opt_b' ) );
		self::assertSame( 'created', get_option( 'mksddn_mc_opt_c' ) );

		delete_option( 'mksddn_mc_opt_a' );
		delete_option( 'mksddn_mc_opt_b' );
		delete_option( 'mksddn_mc_opt_c' );
	}

	public function test__export_import__widgets_include_sidebars(): void {
		update_option( 'widget_text', array( 2 => array( 'text' => 'Hello' ) ) );
		update_option(
			'sidebars_widgets',
			array(
				'wp_inactive_widgets' => array(),
				'sidebar-1'           => array( 'text-2' ),
			)
		);

		$exported = ( new OptionsExporter() )->export_widgets( array( 'widget_text' ) );
		self::assertArrayHasKey( 'widget_text', $exported );
		self::assertArrayHasKey( 'sidebars_widgets', $exported );

		delete_option( 'widget_text' );
		update_option( 'sidebars_widgets', array( 'sidebar-1' => array() ) );

		( new OptionsImporter() )->import_widgets( $exported, true );
		self::assertSame( array( 2 => array( 'text' => 'Hello' ) ), get_option( 'widget_text' ) );
		$sidebars = get_option( 'sidebars_widgets' );
		self::assertSame( array( 'text-2' ), $sidebars['sidebar-1'] );

		delete_option( 'widget_text' );
		delete_option( 'sidebars_widgets' );
	}
}
