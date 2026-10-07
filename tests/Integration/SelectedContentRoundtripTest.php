<?php
/**
 * Integration: selected content import by slug with parent ordering.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Import\ImportHandler;
use WP_UnitTestCase;

/**
 * Slug identity + parent_slug topological import.
 */
final class SelectedContentRoundtripTest extends WP_UnitTestCase {

	public function test__import_bundle__creates_parent_before_child_by_slug(): void {
		$bundle = array(
			'type'  => 'bundle',
			'items' => array(
				// Child listed first on purpose — importer must reorder.
				array(
					'type'        => 'page',
					'title'       => 'Child Page',
					'slug'        => 'child-page',
					'content'     => 'Child body',
					'status'      => 'publish',
					'parent_slug' => 'parent-page',
				),
				array(
					'type'    => 'page',
					'title'   => 'Parent Page',
					'slug'    => 'parent-page',
					'content' => 'Parent body',
					'status'  => 'publish',
				),
			),
		);

		$handler = new ImportHandler();
		self::assertTrue( $handler->import_bundle( $bundle ), $handler->get_last_error() );

		$parent = get_page_by_path( 'parent-page', OBJECT, 'page' );
		// Hierarchical pages resolve by full path (parent/child).
		$child = get_page_by_path( 'parent-page/child-page', OBJECT, 'page' );

		self::assertInstanceOf( \WP_Post::class, $parent );
		self::assertInstanceOf( \WP_Post::class, $child );
		self::assertSame( (int) $parent->ID, (int) $child->post_parent );
		self::assertSame( 'Parent body', $parent->post_content );
		self::assertSame( 'Child body', $child->post_content );
	}

	public function test__import_bundle__updates_existing_page_by_slug(): void {
		$existing_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'About',
				'post_name'    => 'about-us',
				'post_content' => 'Old',
				'post_status'  => 'publish',
			)
		);

		$handler = new ImportHandler();
		self::assertTrue(
			$handler->import_bundle(
				array(
					'type'  => 'bundle',
					'items' => array(
						array(
							'type'    => 'page',
							'title'   => 'About Updated',
							'slug'    => 'about-us',
							'content' => 'New content',
							'status'  => 'publish',
						),
					),
				)
			),
			$handler->get_last_error()
		);

		$updated = get_post( $existing_id );
		self::assertSame( 'About Updated', $updated->post_title );
		self::assertSame( 'New content', $updated->post_content );
		self::assertSame( 'about-us', $updated->post_name );
	}
}
