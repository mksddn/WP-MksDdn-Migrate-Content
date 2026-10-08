<?php
/**
 * Unit tests for SelectionBuilder.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Selection;

use MksDdn\MigrateContent\Selection\SelectionBuilder;
use WP_Mock\Tools\TestCase;

/**
 * Request parsing into ContentSelection.
 */
final class SelectionBuilderTest extends TestCase {

	public function test__from_request__parses_selected_ids_and_options_pages(): void {
		$selection = ( new SelectionBuilder() )->from_request(
			array(
				'selected_page_ids'             => array( '1', '2', '0' ),
				'selected_post_ids'             => array( 9 ),
				'selected_options_page_slugs'   => array( 'site-settings' ),
				'options_keys'                  => array( 'blogdescription' ),
				'widget_groups'                 => array( 'widget_custom_html' ),
			)
		);

		self::assertSame(
			array(
				'page' => array( 1, 2 ),
				'post' => array( 9 ),
			),
			$selection->get_items()
		);
		self::assertSame( array( 'site-settings' ), $selection->get_options_pages() );
		self::assertSame( array( 'blogdescription' ), $selection->get_options() );
		self::assertSame( array( 'widget_custom_html' ), $selection->get_widgets() );
	}
}
