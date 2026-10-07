<?php
/**
 * Unit tests for ContentSelection.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Selection;

use MksDdn\MigrateContent\Selection\ContentSelection;
use WP_Mock\Tools\TestCase;

/**
 * Selection value object behaviour.
 */
final class ContentSelectionTest extends TestCase {

	public function test__add_item__ignores_non_positive_and_duplicates(): void {
		$selection = new ContentSelection();
		$selection->add_item( 'page', 0 );
		$selection->add_item( 'page', 3 );
		$selection->add_item( 'page', 3 );

		self::assertSame( array( 'page' => array( 3 ) ), $selection->get_items() );
		self::assertSame( 1, $selection->count_items() );
		self::assertTrue( $selection->has_items() );
	}

	public function test__options_pages_and_first_item(): void {
		$selection = new ContentSelection();
		$selection->add_options_page( 'theme-settings' );
		$selection->add_options_page( 'theme-settings' );
		$selection->add_item( 'post', 10 );

		self::assertTrue( $selection->has_options_pages() );
		self::assertSame( array( 'theme-settings' ), $selection->get_options_pages() );
		self::assertSame(
			array(
				'type' => 'post',
				'id'   => 10,
			),
			$selection->first_item()
		);
	}

	public function test__add_option_and_widget(): void {
		$selection = new ContentSelection();
		$selection->add_option( 'blogname' );
		$selection->add_widget_group( 'widget_text' );

		self::assertTrue( $selection->has_options() );
		self::assertSame( array( 'blogname' ), $selection->get_options() );
		self::assertSame( array( 'widget_text' ), $selection->get_widgets() );
	}
}
