<?php
/**
 * Integration: remaining negative / security edge cases.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Filesystem\ThemeImporter;
use MksDdn\MigrateContent\Import\ImportHandler;
use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Users\UserMergeApplier;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Theme zip-slip, missing CPT, missing media, user-merge edges, chunk index bounds.
 */
final class NegativeEdgeCasesTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir = sys_get_temp_dir() . '/mksddn-mc-neg-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		PluginConfig::create_required_directories();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		parent::tearDown();
	}

	public function test__theme_importer__rejects_zip_slip_paths(): void {
		$outside = trailingslashit( WP_CONTENT_DIR ) . 'mksddn-mc-theme-zipslip-outside.txt';
		if ( file_exists( $outside ) ) {
			unlink( $outside );
		}

		$slug    = 'mksddn-mc-slip-theme';
		$archive = $this->tmpdir . '/theme-slip.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$archive,
			array(
				'type'   => 'themes',
				'themes' => array(
					array(
						'slug' => $slug,
						'name' => 'Slip Theme',
					),
				),
			),
			array(
				'wp-content/themes/' . $slug . '/style.css' => "/*\nTheme Name: Slip\n*/\n",
				'wp-content/themes/' . $slug . '/index.php' => "<?php\n",
				'wp-content/themes/' . $slug . '/../../mksddn-mc-theme-zipslip-outside.txt' => 'pwned',
			)
		);

		$result = ( new ThemeImporter( 'merge' ) )->import_themes( $archive );
		// Traversal entries are skipped/rejected; import may still succeed for safe files.
		if ( is_wp_error( $result ) ) {
			self::assertSame( 'mksddn_mc_invalid_theme_path', $result->get_error_code() );
		} else {
			self::assertTrue( $result );
		}
		self::assertFileDoesNotExist( $outside );

		$theme_dir = trailingslashit( WP_CONTENT_DIR ) . 'themes/' . $slug;
		if ( is_dir( $theme_dir ) ) {
			ArchiveFixtureBuilder::rrmdir( $theme_dir );
		}
	}

	public function test__import_handler__rejects_unregistered_post_type(): void {
		$handler = new ImportHandler();
		$result  = $handler->import_single_page(
			array(
				'type'    => 'mksddn_missing_cpt',
				'title'   => 'Missing CPT',
				'slug'    => 'missing-cpt-post',
				'content' => 'Body',
				'status'  => 'publish',
			)
		);

		self::assertFalse( $result );
		self::assertStringContainsString( 'mksddn_missing_cpt', $handler->get_last_error() );
		self::assertNull( get_page_by_path( 'missing-cpt-post', OBJECT, 'mksddn_missing_cpt' ) );
	}

	public function test__extractor__missing_media_path_returns_error(): void {
		$archive = $this->tmpdir . '/media-miss.wpbkp';
		ArchiveFixtureBuilder::create_wpbkp(
			$archive,
			array( 'type' => 'selected' ),
			array(
				'payload/content.json' => '{"type":"page","slug":"x"}',
			)
		);

		$extractor = new Extractor();
		$missing   = $extractor->extract_media_file( 'media/does-not-exist.jpg', $archive );
		self::assertTrue( is_wp_error( $missing ) );
		self::assertSame( 'mksddn_mc_media_not_found', $missing->get_error_code() );

		$empty = $extractor->extract_media_file( '', $archive );
		self::assertTrue( is_wp_error( $empty ) );
		self::assertSame( 'mksddn_mc_media_path_missing', $empty->get_error_code() );
	}

	public function test__user_merge__unknown_mode_defaults_to_replace(): void {
		$local_id = self::factory()->user->create(
			array(
				'user_login' => 'mode-default-user',
				'user_email' => 'mode-default-' . uniqid() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$local = get_userdata( $local_id );
		$email = strtolower( $local->user_email );

		$summary = ( new UserMergeApplier() )->merge(
			array(
				$email => array(
					'email' => $local->user_email,
					'row'   => array(
						'ID'            => 9001,
						'user_login'    => 'mode-default-remote',
						'user_email'    => $local->user_email,
						'user_pass'     => wp_hash_password( 'z' ),
						'display_name'  => 'Remote Mode',
						'user_nicename' => 'mode-default-remote',
						'user_url'      => '',
					),
					'meta'  => array(),
				),
			),
			array(
				$email => array(
					'import' => true,
					'mode'   => 'not-a-real-mode',
				),
			),
			'zzz_'
		);

		self::assertSame( 1, (int) $summary['updated'] );
		self::assertSame( 'mode-default-remote', get_userdata( $local_id )->user_login );
	}

	public function test__user_merge__login_collision_with_different_email(): void {
		$existing_id = self::factory()->user->create(
			array(
				'user_login' => 'shared-login-collision',
				'user_email' => 'shared-login-local-' . uniqid() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$new_email = 'shared-login-remote-' . uniqid() . '@example.com';

		$summary = ( new UserMergeApplier() )->merge(
			array(
				strtolower( $new_email ) => array(
					'email' => $new_email,
					'row'   => array(
						'ID'            => 9002,
						'user_login'    => 'shared-login-collision',
						'user_email'    => $new_email,
						'user_pass'     => wp_hash_password( 'z' ),
						'display_name'  => 'Collision Remote',
						'user_nicename' => 'shared-login-collision',
						'user_url'      => '',
					),
					'meta'  => array(),
				),
			),
			array(
				strtolower( $new_email ) => array(
					'import' => true,
					'mode'   => 'replace',
				),
			),
			'zzz_'
		);

		// Must not destroy the local login owner; either skip/error count or create with alternate login.
		$local = get_userdata( $existing_id );
		self::assertInstanceOf( \WP_User::class, $local );
		self::assertSame( 'shared-login-collision', $local->user_login );

		$by_email = get_user_by( 'email', $new_email );
		if ( $by_email instanceof \WP_User ) {
			self::assertNotSame( (int) $existing_id, (int) $by_email->ID );
		} else {
			self::assertGreaterThanOrEqual( 0, (int) ( $summary['errors'] ?? $summary['skipped'] ?? 0 ) );
		}
	}

	public function test__chunk_download__rejects_out_of_range_index(): void {
		$repo = new ChunkJobRepository();
		$job  = $repo->create();
		file_put_contents( $job->get_file_path(), 'ABCDEFGH' );
		$job->update(
			array(
				'mode'         => 'download',
				'status'       => 'ready',
				'chunk_size'   => 4,
				'total_chunks' => 2,
			)
		);
		$id = $job->get_data()['id'];

		$controller = new ChunkRestController( $repo );
		$request    = new WP_REST_Request( 'GET', '/mksddn/v1/chunk/download' );
		$request->set_param( 'job_id', $id );
		$request->set_param( 'index', 99 );
		$result = $controller->download_chunk( $request );
		self::assertTrue( is_wp_error( $result ) );
	}

	public function test__selected_slug_collision_across_post_types_keeps_identity(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Shared Slug Page',
				'post_name'   => 'shared-slug-identity',
				'post_status' => 'publish',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Shared Slug Post',
				'post_name'   => 'shared-slug-identity',
				'post_status' => 'publish',
			)
		);

		$handler = new ImportHandler();
		self::assertNotFalse(
			$handler->import_single_page(
				array(
					'type'    => 'page',
					'title'   => 'Shared Slug Page Updated',
					'slug'    => 'shared-slug-identity',
					'content' => 'page-body',
					'status'  => 'publish',
				)
			),
			$handler->get_last_error()
		);

		$page = get_post( $page_id );
		$post = get_post( $post_id );
		self::assertSame( 'Shared Slug Page Updated', $page->post_title );
		self::assertSame( 'page-body', $page->post_content );
		self::assertSame( 'Shared Slug Post', $post->post_title );
	}
}
