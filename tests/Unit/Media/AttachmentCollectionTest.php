<?php
/**
 * Unit tests for AttachmentCollection.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Media;

use MksDdn\MigrateContent\Media\AttachmentCollection;
use WP_Mock\Tools\TestCase;

/**
 * Media collection value object.
 */
final class AttachmentCollectionTest extends TestCase {

	public function test__add_absorb_and_has_items(): void {
		$a = new AttachmentCollection();
		self::assertFalse( $a->has_items() );

		$a->add(
			array( 'id' => 1, 'file' => 'a.jpg' ),
			array(
				'source' => '/tmp/a.jpg',
				'target' => 'media/a.jpg',
			)
		);
		self::assertTrue( $a->has_items() );
		self::assertCount( 1, $a->get_manifest() );

		$b = new AttachmentCollection();
		$b->add(
			array( 'id' => 2, 'file' => 'b.jpg' ),
			array(
				'source' => '/tmp/b.jpg',
				'target' => 'media/b.jpg',
			)
		);
		$a->absorb( $b );

		self::assertCount( 2, $a->get_manifest() );
		self::assertCount( 2, $a->get_assets() );
	}
}
