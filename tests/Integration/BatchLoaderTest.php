<?php
/**
 * Integration: BatchLoader term parent_slug caching.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Core\BatchLoader;
use WP_UnitTestCase;

/**
 * Loads taxonomy terms in batch and exposes parent_slug for export.
 */
final class BatchLoaderTest extends WP_UnitTestCase {

	public function test__get_terms__includes_parent_slug_for_hierarchy(): void {
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Batch Parent',
				'slug'     => 'batch-parent-term',
			)
		);
		$child = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Batch Child',
				'slug'     => 'batch-child-term',
				'parent'   => (int) $parent,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $parent, (int) $child ), 'category' );

		$loader = new BatchLoader();
		$loader->load_terms_batch( array( $post_id ), 'category' );
		$terms = $loader->get_terms( $post_id, 'category' );

		$by_slug = array();
		foreach ( $terms as $row ) {
			$by_slug[ (string) $row['slug'] ] = $row;
		}

		self::assertArrayHasKey( 'batch-child-term', $by_slug );
		self::assertSame( 'batch-parent-term', $by_slug['batch-child-term']['parent_slug'] );
		self::assertArrayHasKey( 'batch-parent-term', $by_slug );
		self::assertSame( '', (string) $by_slug['batch-parent-term']['parent_slug'] );
		self::assertArrayNotHasKey( 'assigned', $by_slug['batch-parent-term'] );
	}

	public function test__get_terms__includes_unassigned_ancestors(): void {
		$root = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Batch Root',
				'slug'     => 'batch-root-only',
			)
		);
		$parent = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Batch Mid',
				'slug'     => 'batch-mid-only',
				'parent'   => (int) $root,
			)
		);
		$child = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Batch Leaf',
				'slug'     => 'batch-leaf-only',
				'parent'   => (int) $parent,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $post_id, array( (int) $child ), 'category' );

		$loader = new BatchLoader();
		$terms  = $loader->get_terms( $post_id, 'category' );
		$by_slug = array();
		foreach ( $terms as $row ) {
			$by_slug[ (string) $row['slug'] ] = $row;
		}

		self::assertArrayNotHasKey( 'assigned', $by_slug['batch-leaf-only'] );
		self::assertSame( 'batch-mid-only', $by_slug['batch-leaf-only']['parent_slug'] );
		self::assertFalse( $by_slug['batch-mid-only']['assigned'] );
		self::assertSame( 'batch-root-only', $by_slug['batch-mid-only']['parent_slug'] );
		self::assertFalse( $by_slug['batch-root-only']['assigned'] );
		self::assertSame( '', (string) $by_slug['batch-root-only']['parent_slug'] );
	}
}
