<?php
/**
 * Integration: SelectedContentDiffBuilder behavioral field diffs.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Import\SelectedContentDiffBuilder;
use WP_UnitTestCase;

/**
 * Preflight diff must reflect real title/content differences (not instanceof smoke).
 */
final class SelectedContentDiffBuilderTest extends WP_UnitTestCase {

	public function test__build_item_changes__detects_title_change(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Local Title',
				'post_name'    => 'diff-title-e2e',
				'post_content' => 'Same body',
				'post_status'  => 'publish',
			)
		);

		$builder = new SelectedContentDiffBuilder();
		$changed = $builder->build_item_changes(
			array(
				'type'    => 'page',
				'title'   => 'Archive Title',
				'slug'    => 'diff-title-e2e',
				'content' => 'Same body',
				'status'  => 'publish',
			),
			$post_id
		);

		self::assertGreaterThan( 0, (int) ( $changed['summary']['changed_count'] ?? 0 ) );
		$fields = array_column( $changed['core']['rows'] ?? array(), 'field' );
		self::assertContains( 'title', $fields );
	}

	public function test__build_item_changes__identical_payload_has_zero_changes(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Identical Title',
				'post_name'    => 'diff-identical-e2e',
				'post_content' => 'Identical body',
				'post_excerpt' => 'Identical excerpt',
				'post_status'  => 'publish',
			)
		);

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );

		$builder = new SelectedContentDiffBuilder();
		$same    = $builder->build_item_changes(
			array(
				'type'    => 'page',
				'title'   => $post->post_title,
				'slug'    => $post->post_name,
				'content' => $post->post_content,
				'excerpt' => $post->post_excerpt,
				'status'  => $post->post_status,
			),
			$post_id
		);

		self::assertSame( 0, (int) ( $same['summary']['changed_count'] ?? -1 ) );
	}
}
