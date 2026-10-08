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
		self::assertSame( 'CPT body', $post->post_content );

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
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Probe Category',
				'slug'     => 'probe-cat-e2e',
			)
		);
		$id   = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Tax Post',
				'post_name'    => 'tax-post-e2e',
				'post_content' => 'Tax body',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $id, array( (int) $term ), 'category' );

		$selection = new ContentSelection();
		$selection->add_item( 'post', $id );

		$file = $this->harness->export_file( $selection, 'archive', false );
		self::assertIsString( $file, is_wp_error( $file ) ? $file->get_error_message() : '' );

		wp_delete_post( $id, true );

		$imported = $this->harness->import_file( $file );
		self::assertTrue( true === $imported, is_wp_error( $imported ) ? $imported->get_error_message() : '' );

		$post = get_page_by_path( 'tax-post-e2e', OBJECT, 'post' );
		self::assertInstanceOf( \WP_Post::class, $post );
		$slugs = wp_get_object_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		self::assertContains( 'probe-cat-e2e', $slugs );
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
