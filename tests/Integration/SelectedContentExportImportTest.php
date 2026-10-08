<?php
/**
 * Integration: real selected-content export → archive → import roundtrips.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Selection\ContentSelection;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Tests\Support\SelectedExportImportHarness;
use WP_UnitTestCase;

/**
 * Behavioral export/import for pages, CPT, taxonomies, meta, media, JSON, checksum.
 */
final class SelectedContentExportImportTest extends WP_UnitTestCase {

	private const CPT = 'mksddn_probe';

	/** @var string */
	private string $tmpdir = '';

	/** @var SelectedExportImportHarness|null */
	private ?SelectedExportImportHarness $harness = null;

	/** @var int[] */
	private array $attachment_ids = array();

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir  = sys_get_temp_dir() . '/mksddn-mc-sel-e2e-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		$this->harness = new SelectedExportImportHarness( $this->tmpdir );

		register_post_type(
			self::CPT,
			array(
				'public'       => true,
				'label'        => 'Probe CPT',
				'supports'     => array( 'title', 'editor', 'custom-fields', 'thumbnail' ),
				'taxonomies'   => array( 'category' ),
				'show_in_rest' => true,
			)
		);
	}

	public function tearDown(): void {
		foreach ( $this->attachment_ids as $id ) {
			wp_delete_attachment( $id, true );
		}
		$this->attachment_ids = array();
		unregister_post_type( self::CPT );
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		$this->harness = null;
		parent::tearDown();
	}

	public function test__export_import__parent_child_pages_by_slug(): void {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Parent Export',
				'post_name'    => 'parent-e2e',
				'post_content' => 'Parent body',
				'post_status'  => 'publish',
			)
		);
		$child_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Child Export',
				'post_name'    => 'child-e2e',
				'post_parent'  => $parent_id,
				'post_content' => 'Child body',
				'post_status'  => 'publish',
			)
		);

		$selection = new ContentSelection();
		// Child first on purpose — export order must not break import hierarchy.
		$selection->add_item( 'page', $child_id );
		$selection->add_item( 'page', $parent_id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_delete_post( $child_id, true );
		wp_delete_post( $parent_id, true );
		self::assertNull( get_page_by_path( 'parent-e2e', OBJECT, 'page' ) );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$parent = get_page_by_path( 'parent-e2e', OBJECT, 'page' );
		$child  = get_page_by_path( 'parent-e2e/child-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $parent );
		self::assertInstanceOf( \WP_Post::class, $child );
		self::assertSame( (int) $parent->ID, (int) $child->post_parent );
		self::assertSame( 'Parent body', $parent->post_content );
		self::assertSame( 'Child body', $child->post_content );
	}

	public function test__export_import__upsert_keeps_id_updates_content(): void {
		$existing_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Exported Title',
				'post_name'    => 'upsert-e2e',
				'post_content' => 'Exported content',
				'post_status'  => 'publish',
			)
		);

		$selection = new ContentSelection();
		$selection->add_item( 'page', $existing_id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_update_post(
			array(
				'ID'           => $existing_id,
				'post_title'   => 'Local Drift',
				'post_content' => 'Should be overwritten',
			)
		);

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'upsert-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( $existing_id, (int) $page->ID );
		self::assertSame( 'Exported Title', $page->post_title );
		self::assertSame( 'Exported content', $page->post_content );
	}

	public function test__export_import__core_fields_roundtrip(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$html      = '<figure class="wp-block-image"><a href="https://example.test/page">Link</a></figure>';

		$id = self::factory()->post->create(
			array(
				'post_type'      => 'page',
				'post_title'     => 'Core Fields Page',
				'post_name'      => 'core-fields-e2e',
				'post_content'   => $html,
				'post_excerpt'   => 'Exact excerpt',
				'post_status'    => 'draft',
				'post_author'    => $author_id,
				'menu_order'     => 7,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			)
		);

		$selection = new ContentSelection();
		$selection->add_item( 'page', $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_delete_post( $id, true );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'core-fields-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( 'Core Fields Page', $page->post_title );
		self::assertSame( $html, $page->post_content );
		self::assertSame( 'Exact excerpt', $page->post_excerpt );
		self::assertSame( 'draft', $page->post_status );
		self::assertSame( (string) $author_id, (string) $page->post_author );
		self::assertSame( 7, (int) $page->menu_order );
		self::assertSame( 'closed', $page->comment_status );
		self::assertSame( 'closed', $page->ping_status );
	}

	public function test__export_import__cpt_by_slug(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => self::CPT,
				'post_title'   => 'Probe Item',
				'post_name'    => 'probe-cpt-e2e',
				'post_content' => 'CPT body',
				'post_status'  => 'publish',
			)
		);

		$selection = new ContentSelection();
		$selection->add_item( self::CPT, $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_delete_post( $id, true );
		self::assertNull( get_page_by_path( 'probe-cpt-e2e', OBJECT, self::CPT ) );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$post = get_page_by_path( 'probe-cpt-e2e', OBJECT, self::CPT );
		self::assertInstanceOf( \WP_Post::class, $post );
		self::assertSame( 'Probe Item', $post->post_title );
		self::assertSame( 'CPT body', $post->post_content );
		self::assertSame( 'publish', $post->post_status );
		self::assertSame( 'probe-cpt-e2e', $post->post_name );

		// Upsert second pass.
		wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => 'should be replaced',
			)
		);
		$imported2 = $this->harness->import_file( $file );
		self::assertTrue( true === $imported2, is_wp_error( $imported2 ) ? $imported2->get_error_message() : '' );
		$again = get_page_by_path( 'probe-cpt-e2e', OBJECT, self::CPT );
		self::assertSame( (int) $post->ID, (int) $again->ID );
		self::assertSame( 'CPT body', $again->post_content );
	}

	public function test__export_import__taxonomies_roundtrip(): void {
		$parent_term = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Probe Parent Cat',
				'slug'     => 'probe-parent-cat-e2e',
			)
		);
		$child_term = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Probe Category',
				'slug'     => 'probe-cat-e2e',
				'parent'   => (int) $parent_term,
			)
		);
		$tag = self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => 'Probe Tag',
				'slug'     => 'probe-tag-e2e',
			)
		);
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Tax Post',
				'post_name'    => 'tax-post-e2e',
				'post_content' => 'Tax body',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $id, array( (int) $parent_term, (int) $child_term ), 'category' );
		wp_set_object_terms( $id, array( (int) $tag ), 'post_tag' );

		$selection = new ContentSelection();
		$selection->add_item( 'post', $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		// Delete source post and terms so import recreates them by slug (no WP auto -2 suffix).
		wp_delete_post( $id, true );
		wp_delete_term( (int) $child_term, 'category' );
		wp_delete_term( (int) $parent_term, 'category' );
		wp_delete_term( (int) $tag, 'post_tag' );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$post = get_page_by_path( 'tax-post-e2e', OBJECT, 'post' );
		self::assertInstanceOf( \WP_Post::class, $post );

		$cat_slugs = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		sort( $cat_slugs );
		self::assertSame( array( 'probe-cat-e2e', 'probe-parent-cat-e2e' ), array_values( $cat_slugs ) );

		$cat_term = get_term_by( 'slug', 'probe-cat-e2e', 'category' );
		self::assertInstanceOf( \WP_Term::class, $cat_term );
		self::assertSame( 'Probe Category', $cat_term->name );

		$tag_slugs = wp_get_object_terms( $post->ID, 'post_tag', array( 'fields' => 'slugs' ) );
		self::assertSame( array( 'probe-tag-e2e' ), array_values( $tag_slugs ) );

		// Upsert must replace local extras with the archive term set.
		$extra = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Local Extra',
				'slug'     => 'local-extra-cat',
			)
		);
		wp_set_object_terms( $post->ID, array( (int) $extra ), 'category', true );

		$imported2 = $this->harness->import_file( $file );
		self::assertTrue( true === $imported2, is_wp_error( $imported2 ) ? $imported2->get_error_message() : '' );

		$again = get_page_by_path( 'tax-post-e2e', OBJECT, 'post' );
		$after = wp_get_object_terms( $again->ID, 'category', array( 'fields' => 'slugs' ) );
		sort( $after );
		self::assertSame( array( 'probe-cat-e2e', 'probe-parent-cat-e2e' ), array_values( $after ) );
		self::assertNotContains( 'local-extra-cat', $after );
	}

	public function test__export_import__serialized_post_meta(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Meta Page',
				'post_name'    => 'meta-page-e2e',
				'post_content' => 'Meta body',
				'post_status'  => 'publish',
			)
		);
		$payload = array(
			'nested' => array(
				'a' => 1,
				'b' => 'two',
			),
			'list'   => array( 'x', 'y' ),
		);
		update_post_meta( $id, '_mksddn_probe_blob', $payload );

		$selection = new ContentSelection();
		$selection->add_item( 'page', $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_delete_post( $id, true );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'meta-page-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( $payload, get_post_meta( $page->ID, '_mksddn_probe_blob', true ) );
	}

	public function test__export_import__media_from_packer_archive(): void {
		$png_path = $this->tmpdir . '/featured.png';
		file_put_contents(
			$png_path,
			base64_decode(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
				true
			)
		);

		$attachment_id = self::factory()->attachment->create_upload_object( $png_path );
		self::assertIsInt( $attachment_id );
		$this->attachment_ids[] = $attachment_id;

		$url = wp_get_attachment_url( $attachment_id );
		self::assertIsString( $url );

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Media Export Page',
				'post_name'    => 'media-export-e2e',
				'post_content' => '<img class="wp-image-' . $attachment_id . '" src="' . esc_url( $url ) . '" />',
				'post_status'  => 'publish',
			)
		);
		set_post_thumbnail( $page_id, $attachment_id );

		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );

		$file = $this->harness->export_file( $selection, 'archive', true );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		$old_checksum = (string) get_post_meta( $attachment_id, '_mksddn_mc_checksum', true );
		if ( '' === $old_checksum ) {
			$old_checksum = hash_file( 'sha256', get_attached_file( $attachment_id ) );
		}

		wp_delete_post( $page_id, true );
		wp_delete_attachment( $attachment_id, true );
		$this->attachment_ids = array();

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'media-export-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		$thumb = (int) get_post_thumbnail_id( $page->ID );
		self::assertGreaterThan( 0, $thumb );
		$this->attachment_ids[] = $thumb;

		self::assertStringContainsString( 'wp-image-' . $thumb, $page->post_content );
		self::assertStringNotContainsString( 'wp-image-' . $attachment_id, $page->post_content );
		$stored = (string) get_post_meta( $thumb, '_mksddn_mc_checksum', true );
		self::assertNotSame( '', $stored );
		self::assertSame( $old_checksum, $stored );
		$restored_file = get_attached_file( $thumb );
		self::assertIsString( $restored_file );
		self::assertFileExists( $restored_file );
		self::assertSame( $old_checksum, hash_file( 'sha256', $restored_file ) );
	}

	public function test__export_import__gallery_and_meta_attachment_ids(): void {
		$png_a = $this->tmpdir . '/gallery-a.png';
		$png_b = $this->tmpdir . '/meta-only.png';
		$this->write_solid_png( $png_a, 255, 0, 0 );
		$this->write_solid_png( $png_b, 0, 0, 255 );

		$gallery_id = self::factory()->attachment->create_upload_object( $png_a );
		$meta_id    = self::factory()->attachment->create_upload_object( $png_b );
		self::assertIsInt( $gallery_id );
		self::assertIsInt( $meta_id );
		$this->attachment_ids[] = $gallery_id;
		$this->attachment_ids[] = $meta_id;

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Gallery Meta Page',
				'post_name'    => 'gallery-meta-e2e',
				'post_content' => '[gallery ids="' . $gallery_id . '"]',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $page_id, '_mksddn_related_image', $meta_id );

		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );

		$file = $this->harness->export_file( $selection, 'archive', true );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		$old_gallery_checksum = hash_file( 'sha256', get_attached_file( $gallery_id ) );
		$old_meta_checksum    = hash_file( 'sha256', get_attached_file( $meta_id ) );
		self::assertNotSame( $old_gallery_checksum, $old_meta_checksum );

		$prepared = $this->harness->prepare_only( $file );
		self::assertIsArray( $prepared, is_wp_error( $prepared ) ? $prepared->get_error_message() : '' );
		$media = $prepared['media'] ?? array();
		self::assertCount( 2, $media, 'Export must embed both gallery and meta-only attachments' );
		$exported_ids = array_map(
			static function ( $entry ) {
				return (int) ( $entry['original_id'] ?? 0 );
			},
			$media
		);
		sort( $exported_ids );
		$expected_ids = array( (int) $gallery_id, (int) $meta_id );
		sort( $expected_ids );
		self::assertSame( $expected_ids, $exported_ids );

		wp_delete_post( $page_id, true );
		wp_delete_attachment( $gallery_id, true );
		wp_delete_attachment( $meta_id, true );
		$this->attachment_ids = array();

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'gallery-meta-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );

		self::assertMatchesRegularExpression( '/\[gallery[^\]]*ids="(\d+)"/', $page->post_content );
		preg_match( '/\[gallery[^\]]*ids="(\d+)"/', $page->post_content, $matches );
		$new_gallery = (int) ( $matches[1] ?? 0 );
		$new_meta    = (int) get_post_meta( $page->ID, '_mksddn_related_image', true );

		self::assertGreaterThan( 0, $new_gallery );
		self::assertGreaterThan( 0, $new_meta );
		self::assertNotSame( $new_gallery, $new_meta );
		$this->attachment_ids[] = $new_gallery;
		$this->attachment_ids[] = $new_meta;

		// Contract: shortcode ID and meta ID resolve to the exported binaries (via checksum meta).
		self::assertSame( $old_gallery_checksum, (string) get_post_meta( $new_gallery, '_mksddn_mc_checksum', true ) );
		self::assertSame( $old_meta_checksum, (string) get_post_meta( $new_meta, '_mksddn_mc_checksum', true ) );
		$gallery_file = get_attached_file( $new_gallery );
		$meta_file    = get_attached_file( $new_meta );
		self::assertIsString( $gallery_file );
		self::assertIsString( $meta_file );
		self::assertFileExists( $gallery_file );
		self::assertFileExists( $meta_file );
		self::assertSame( $old_gallery_checksum, hash_file( 'sha256', $gallery_file ) );
		self::assertSame( $old_meta_checksum, hash_file( 'sha256', $meta_file ) );
	}

	/**
	 * Write a small solid-color PNG for distinct media fixtures.
	 *
	 * @param string $path Absolute path.
	 * @param int    $r    Red 0-255.
	 * @param int    $g    Green 0-255.
	 * @param int    $b    Blue 0-255.
	 */
	private function write_solid_png( string $path, int $r, int $g, int $b ): void {
		$image = imagecreatetruecolor( 8, 8 );
		self::assertNotFalse( $image );
		$color = imagecolorallocate( $image, $r, $g, $b );
		self::assertNotFalse( $color );
		imagefilledrectangle( $image, 0, 0, 7, 7, $color );
		self::assertTrue( imagepng( $image, $path ) );
		imagedestroy( $image );
	}

	public function test__export_import__featured_image_updates_on_upsert(): void {
		$png_a = $this->tmpdir . '/feat-a.png';
		$png_b = $this->tmpdir . '/feat-b.png';
		$bytes = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
			true
		);
		$bytes_b = base64_decode(
			'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==',
			true
		);
		file_put_contents( $png_a, $bytes );
		file_put_contents( $png_b, $bytes_b );

		$att_a = self::factory()->attachment->create_upload_object( $png_a );
		$att_b = self::factory()->attachment->create_upload_object( $png_b );
		self::assertIsInt( $att_a );
		self::assertIsInt( $att_b );
		$this->attachment_ids[] = $att_a;
		$this->attachment_ids[] = $att_b;

		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Featured Upsert',
				'post_name'    => 'featured-upsert-e2e',
				'post_content' => '<img class="wp-image-' . $att_b . '" src="' . esc_url( wp_get_attachment_url( $att_b ) ) . '" />',
				'post_status'  => 'publish',
			)
		);
		set_post_thumbnail( $page_id, $att_b );

		$selection = new ContentSelection();
		$selection->add_item( 'page', $page_id );

		$file = $this->harness->export_file( $selection, 'archive', true );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		$expected_checksum = hash_file( 'sha256', get_attached_file( $att_b ) );

		// Local drift: different featured image already set.
		set_post_thumbnail( $page_id, $att_a );
		self::assertSame( $att_a, (int) get_post_thumbnail_id( $page_id ) );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'featured-upsert-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		$thumb = (int) get_post_thumbnail_id( $page->ID );
		self::assertGreaterThan( 0, $thumb );
		self::assertNotSame( $att_a, $thumb );
		$this->attachment_ids[] = $thumb;
		self::assertSame( $expected_checksum, hash_file( 'sha256', get_attached_file( $thumb ) ) );
		self::assertSame( '', (string) get_post_meta( $page->ID, '_mksddn_original_thumbnail', true ) );
	}

	public function test__export_import__json_format_without_media_binaries(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'JSON Page',
				'post_name'    => 'json-page-e2e',
				'post_content' => 'JSON body',
				'post_status'  => 'publish',
			)
		);

		$selection = new ContentSelection();
		$selection->add_item( 'page', $id );

		$file = $this->harness->export_file( $selection, 'json', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );
		self::assertSame( 'json', strtolower( pathinfo( $file, PATHINFO_EXTENSION ) ) );

		wp_delete_post( $id, true );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$page = get_page_by_path( 'json-page-e2e', OBJECT, 'page' );
		self::assertInstanceOf( \WP_Post::class, $page );
		self::assertSame( 'JSON body', $page->post_content );
	}

	public function test__export_import__checksum_mismatch_rejects_and_creates_no_posts(): void {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Checksum Page',
				'post_name'    => 'checksum-e2e',
				'post_content' => 'Checksum body',
				'post_status'  => 'publish',
			)
		);

		$selection = new ContentSelection();
		$selection->add_item( 'page', $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		$tampered = $this->harness->tamper_payload_checksum( $file );
		self::assertTrue( true === $tampered, is_wp_error( $tampered ) ? $tampered->get_error_message() : '' );

		wp_delete_post( $id, true );
		$before = (int) wp_count_posts( 'page' )->publish;

		$prepared = $this->harness->prepare_only( $file );
		self::assertTrue( is_wp_error( $prepared ) );
		self::assertSame( 'mksddn_mc_checksum_mismatch', $prepared->get_error_code() );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( is_wp_error( $imported ) );
		self::assertSame( 'mksddn_mc_checksum_mismatch', $imported->get_error_code() );
		self::assertNull( get_page_by_path( 'checksum-e2e', OBJECT, 'page' ) );
		self::assertSame( $before, (int) wp_count_posts( 'page' )->publish );
	}
}
