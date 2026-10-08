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

	public function test__assign_taxonomies__child_before_parent_defers_until_parent_exists(): void {
		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'       => 'post',
				'title'      => 'Tax Defer Post',
				'slug'       => 'tax-defer-child-first',
				'content'    => 'Body',
				'status'     => 'publish',
				'taxonomies' => array(
					'category' => array(
						// Child listed first — parent must be inserted on a later deferral pass.
						array(
							'slug'        => 'defer-child',
							'name'        => 'Defer Child',
							'description' => '',
							'parent_slug' => 'defer-parent',
						),
						array(
							'slug'        => 'defer-parent',
							'name'        => 'Defer Parent',
							'description' => '',
							'parent_slug' => '',
						),
					),
				),
			)
		);

		self::assertNotFalse( $result, $handler->get_last_error() );

		$parent = get_term_by( 'slug', 'defer-parent', 'category' );
		$child  = get_term_by( 'slug', 'defer-child', 'category' );
		self::assertInstanceOf( \WP_Term::class, $parent );
		self::assertInstanceOf( \WP_Term::class, $child );
		self::assertSame( (int) $parent->term_id, (int) $child->parent );

		$post = get_page_by_path( 'tax-defer-child-first', OBJECT, 'post' );
		self::assertInstanceOf( \WP_Post::class, $post );
		$slugs = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		sort( $slugs );
		self::assertSame( array( 'defer-child', 'defer-parent' ), array_values( $slugs ) );
	}

	public function test__assign_taxonomies__three_level_reverse_order(): void {
		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'       => 'post',
				'title'      => 'Tax Deep Post',
				'slug'       => 'tax-defer-three-level',
				'content'    => 'Body',
				'status'     => 'publish',
				'taxonomies' => array(
					'category' => array(
						array(
							'slug'        => 'deep-grandchild',
							'name'        => 'Deep Grandchild',
							'parent_slug' => 'deep-child',
						),
						array(
							'slug'        => 'deep-child',
							'name'        => 'Deep Child',
							'parent_slug' => 'deep-root',
						),
						array(
							'slug'        => 'deep-root',
							'name'        => 'Deep Root',
							'parent_slug' => '',
						),
					),
				),
			)
		);

		self::assertNotFalse( $result, $handler->get_last_error() );

		$root  = get_term_by( 'slug', 'deep-root', 'category' );
		$child = get_term_by( 'slug', 'deep-child', 'category' );
		$grand = get_term_by( 'slug', 'deep-grandchild', 'category' );
		self::assertInstanceOf( \WP_Term::class, $root );
		self::assertInstanceOf( \WP_Term::class, $child );
		self::assertInstanceOf( \WP_Term::class, $grand );
		self::assertSame( (int) $root->term_id, (int) $child->parent );
		self::assertSame( (int) $child->term_id, (int) $grand->parent );
	}

	public function test__assign_taxonomies__orphan_parent_slug_does_not_hang(): void {
		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'       => 'post',
				'title'      => 'Tax Orphan Post',
				'slug'       => 'tax-defer-orphan',
				'content'    => 'Body',
				'status'     => 'publish',
				'taxonomies' => array(
					'category' => array(
						array(
							'slug'        => 'orphan-child',
							'name'        => 'Orphan Child',
							'parent_slug' => 'missing-parent-never-exported',
						),
					),
				),
			)
		);

		// Import must finish; orphan with unresolved parent_slug is skipped (not inserted).
		self::assertNotFalse( $result, $handler->get_last_error() );
		self::assertFalse( get_term_by( 'slug', 'orphan-child', 'category' ) );
		self::assertInstanceOf( \WP_Post::class, get_page_by_path( 'tax-defer-orphan', OBJECT, 'post' ) );
	}

	public function test__assign_taxonomies__cycle_does_not_hang(): void {
		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'       => 'post',
				'title'      => 'Tax Cycle Post',
				'slug'       => 'tax-defer-cycle',
				'content'    => 'Body',
				'status'     => 'publish',
				'taxonomies' => array(
					'category' => array(
						array(
							'slug'        => 'cycle-a',
							'name'        => 'Cycle A',
							'parent_slug' => 'cycle-b',
						),
						array(
							'slug'        => 'cycle-b',
							'name'        => 'Cycle B',
							'parent_slug' => 'cycle-a',
						),
					),
				),
			)
		);

		self::assertNotFalse( $result, $handler->get_last_error() );
		self::assertFalse( get_term_by( 'slug', 'cycle-a', 'category' ) );
		self::assertFalse( get_term_by( 'slug', 'cycle-b', 'category' ) );
		self::assertInstanceOf( \WP_Post::class, get_page_by_path( 'tax-defer-cycle', OBJECT, 'post' ) );
	}

	public function test__assign_taxonomies__updates_existing_term_parent(): void {
		$wrong_parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Wrong Parent',
				'slug'     => 'wrong-parent-for-update',
			)
		);
		$correct_parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Correct Parent',
				'slug'     => 'correct-parent-for-update',
			)
		);
		$existing = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Existing Child',
				'slug'     => 'existing-child-update',
				'parent'   => (int) $wrong_parent,
			)
		);

		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'       => 'post',
				'title'      => 'Tax Update Parent Post',
				'slug'       => 'tax-update-parent',
				'content'    => 'Body',
				'status'     => 'publish',
				'taxonomies' => array(
					'category' => array(
						array(
							'slug'        => 'correct-parent-for-update',
							'name'        => 'Correct Parent',
							'parent_slug' => '',
						),
						array(
							'slug'        => 'existing-child-update',
							'name'        => 'Existing Child',
							'parent_slug' => 'correct-parent-for-update',
						),
					),
				),
			)
		);

		self::assertNotFalse( $result, $handler->get_last_error() );

		$child = get_term( (int) $existing, 'category' );
		self::assertInstanceOf( \WP_Term::class, $child );
		self::assertSame( (int) $correct_parent, (int) $child->parent );
		self::assertNotSame( (int) $wrong_parent, (int) $child->parent );
		self::assertFalse( get_term_by( 'slug', 'existing-child-update-correct-parent-for-update', 'category' ) );
	}
}
