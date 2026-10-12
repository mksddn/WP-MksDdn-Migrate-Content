<?php
/**
 * Unit tests for ThemeFileDiffBuilder::slim_for_preview_store.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Unit\Filesystem;

use MksDdn\MigrateContent\Filesystem\ThemeFileDiffBuilder;
use WP_Mock\Tools\TestCase;

/**
 * Preview-store slimming drops path samples.
 */
final class ThemeFileDiffBuilderTest extends TestCase {

	public function test__slim_for_preview_store__keeps_counts_drops_samples(): void {
		$slim = ThemeFileDiffBuilder::slim_for_preview_store(
			array(
				array(
					'slug'                         => 'my-theme',
					'exists'                       => true,
					'file_count'                   => 10,
					'added_count'                  => 2,
					'overwrite_count'              => 3,
					'identical_count'              => 4,
					'unverified_count'             => 1,
					'will_delete_on_replace_count' => 5,
					'sample_added'                 => array( 'style.css' ),
					'sample_overwrite'             => array( 'functions.php' ),
					'samples_truncated_added'      => true,
				),
			)
		);

		self::assertCount( 1, $slim );
		self::assertSame( 'my-theme', $slim[0]['slug'] );
		self::assertSame( 2, $slim[0]['added_count'] );
		self::assertSame( array(), $slim[0]['sample_added'] );
		self::assertSame( array(), $slim[0]['sample_overwrite'] );
		self::assertTrue( $slim[0]['counts_only'] );
		self::assertTrue( $slim[0]['samples_truncated_added'] );
	}
}
