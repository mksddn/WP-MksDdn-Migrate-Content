<?php
/**
 * Integration: selected content validation, media restore, options overwrite.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Import\ImportHandler;
use MksDdn\MigrateContent\Options\OptionsImporter;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use WP_UnitTestCase;

/**
 * Media sideload via set_media_file_loader and OptionsImporter overwrite flags.
 */
final class SelectedContentMediaTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var string[] */
	private array $option_keys = array();

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-media-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
	}

	public function tearDown(): void {
		foreach ( $this->option_keys as $key ) {
			delete_option( $key );
		}
		$this->option_keys = array();
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		parent::tearDown();
	}

	public function test__import_bundle__creates_page_for_new_slug(): void {
		$handler = new ImportHandler();
		self::assertTrue(
			$handler->import_bundle(
				array(
					'type'  => 'bundle',
					'items' => array(
						array(
							'type'    => 'page',
							'title'   => 'Fresh Page',
							'slug'    => 'fresh-media-page',
							'content' => 'Body',
							'status'  => 'publish',
						),
					),
				)
			),
			$handler->get_last_error()
		);

		$page = get_page_by_path( 'fresh-media-page', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( 'Fresh Page', $page->post_title );
	}

	public function test__import_bundle__rejects_invalid_payloads(): void {
		$handler = new ImportHandler();

		self::assertFalse(
			$handler->import_bundle(
				array(
					'type'  => 'bundle',
					'items' => array(
						array(
							'type'    => 'page',
							'title'   => 'No Content',
							'slug'    => 'no-content',
							'status'  => 'publish',
						),
					),
				)
			)
		);
		self::assertNotSame( '', $handler->get_last_error() );

		self::assertFalse(
			$handler->import_bundle(
				array(
					'type'  => 'bundle',
					'items' => array(
						array(
							'type'    => 'attachment',
							'title'   => 'Bad',
							'slug'    => 'bad-attachment',
							'content' => 'x',
							'status'  => 'publish',
						),
					),
				)
			)
		);
		self::assertNotSame( '', $handler->get_last_error() );

		self::assertFalse(
			$handler->import_bundle(
				array(
					'type'  => 'bundle',
					'items' => array(
						array(
							'type'    => 'mksddn_missing_cpt',
							'title'   => 'Missing CPT',
							'slug'    => 'missing-cpt',
							'content' => 'x',
							'status'  => 'publish',
						),
					),
				)
			)
		);
		self::assertNotSame( '', $handler->get_last_error() );
	}

	public function test__import_single_page__restores_media_checksum_and_featured(): void {
		$png_path = $this->tmpdir . '/probe.png';
		file_put_contents(
			$png_path,
			base64_decode(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
				true
			)
		);
		$checksum   = hash_file( 'sha256', $png_path );
		$source_url = 'https://old.example/wp-content/uploads/probe.png';
		$original   = 99;

		$handler = new ImportHandler();
		$handler->set_media_file_loader(
			static function () use ( $png_path ) {
				$copy = tempnam( sys_get_temp_dir(), 'mksddn-mc-sideload-' );
				self::assertNotFalse( $copy );
				copy( $png_path, $copy );
				return $copy;
			}
		);

		$payload = array(
			'type'           => 'page',
			'title'          => 'Media Page',
			'slug'           => 'media-checksum-page',
			'content'        => '<img class="wp-image-' . $original . '" src="' . $source_url . '" />',
			'status'         => 'publish',
			'featured_media' => $original,
			'_mksddn_media'  => array(
				array(
					'original_id'  => $original,
					'checksum'     => $checksum,
					'archive_path' => 'media/99-probe.png',
					'filename'     => 'probe.png',
					'source_url'   => $source_url,
					'title'        => 'Probe',
					'alt'          => '',
					'caption'      => '',
					'description'  => '',
				),
			),
		);

		$post_id = $handler->import_single_page( $payload );
		self::assertNotFalse( $post_id, $handler->get_last_error() );
		self::assertIsInt( $post_id );

		$post = get_post( $post_id );
		self::assertInstanceOf( \WP_Post::class, $post );
		self::assertStringNotContainsString( $source_url, $post->post_content );
		self::assertStringNotContainsString( 'wp-image-' . $original, $post->post_content );

		$thumb = (int) get_post_thumbnail_id( $post_id );
		self::assertGreaterThan( 0, $thumb );
		self::assertSame( $checksum, (string) get_post_meta( $thumb, '_mksddn_mc_checksum', true ) );
		$attached = get_attached_file( $thumb );
		self::assertIsString( $attached );
		self::assertFileExists( $attached );
		self::assertSame( $checksum, hash_file( 'sha256', $attached ) );
		self::assertStringContainsString( 'wp-image-' . $thumb, $post->post_content );

		$second = $handler->import_single_page(
			array_merge(
				$payload,
				array(
					'slug'  => 'media-checksum-page-2',
					'title' => 'Media Page 2',
				)
			)
		);
		self::assertNotFalse( $second, $handler->get_last_error() );
		$thumb2 = (int) get_post_thumbnail_id( (int) $second );
		self::assertSame( $thumb, $thumb2 );
	}

	public function test__options_importer__respects_overwrite_false(): void {
		$key_new  = 'mksddn_mc_opt_new_' . uniqid();
		$key_keep = 'mksddn_mc_opt_keep_' . uniqid();
		$widget   = 'widget_mksddn_mc_' . uniqid();
		$this->option_keys = array( $key_new, $key_keep, $widget );

		update_option( $key_keep, 'original' );
		update_option( $widget, array( 1 => array( 'text' => 'old' ) ) );

		$importer = new OptionsImporter();
		$importer->import_options(
			array(
				$key_new  => 'created',
				$key_keep => 'replaced',
			),
			false
		);
		$importer->import_widgets(
			array(
				$widget => array( 1 => array( 'text' => 'new' ) ),
			),
			false
		);

		self::assertSame( 'created', get_option( $key_new ) );
		self::assertSame( 'original', get_option( $key_keep ) );
		self::assertSame( array( 1 => array( 'text' => 'old' ) ), get_option( $widget ) );

		$handler = new ImportHandler();
		$bundle_key = 'mksddn_mc_bundle_opt_' . uniqid();
		$this->option_keys[] = $bundle_key;
		self::assertTrue(
			$handler->import_bundle(
				array(
					'type'    => 'bundle',
					'items'   => array(),
					'options' => array(
						'options' => array( $bundle_key => 'from-bundle' ),
						'widgets' => array(
							'widget_text' => array( 99 => array( 'text' => 'w' ) ),
						),
					),
				)
			),
			$handler->get_last_error()
		);
		$this->option_keys[] = 'widget_text';
		self::assertSame( 'from-bundle', get_option( $bundle_key ) );
	}
}
