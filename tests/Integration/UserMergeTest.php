<?php
/**
 * Integration: user merge, strip tables, diff, preview cleanup.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Tests\Support\ArchiveFixtureBuilder;
use MksDdn\MigrateContent\Users\UserDiffBuilder;
use MksDdn\MigrateContent\Users\UserMergeApplier;
use MksDdn\MigrateContent\Users\UserPreviewStore;
use WP_UnitTestCase;

/**
 * UserMergeApplier and UserDiffBuilder against real WP users.
 */
final class UserMergeTest extends WP_UnitTestCase {

	/** @var string */
	private string $tmpdir = '';

	/** @var int */
	private int $admin_id = 0;

	public function setUp(): void {
		parent::setUp();
		$this->tmpdir   = sys_get_temp_dir() . '/mksddn-mc-users-' . uniqid( '', true );
		mkdir( $this->tmpdir, 0777, true );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		if ( '' !== $this->tmpdir ) {
			ArchiveFixtureBuilder::rrmdir( $this->tmpdir );
		}
		parent::tearDown();
	}

	public function test__merge__creates_updates_keeps_and_preserves_admin(): void {
		$applier = new UserMergeApplier();

		$empty = $applier->merge( array(), array(), 'zzz_' );
		self::assertSame( 1, (int) $empty['preserved'] );

		$new_email = 'new-merge-' . uniqid() . '@example.com';
		$summary   = $applier->merge(
			array(
				strtolower( $new_email ) => array(
					'email' => $new_email,
					'row'   => array(
						'ID'              => 501,
						'user_login'      => 'newmergeuser',
						'user_email'      => $new_email,
						'user_pass'       => wp_hash_password( 'secret' ),
						'display_name'    => 'New Merge',
						'user_nicename'   => 'newmergeuser',
						'user_url'        => '',
						'user_registered' => current_time( 'mysql' ),
						'user_status'     => 0,
					),
					'meta'  => array(
						array(
							'meta_key'   => 'zzz_capabilities',
							'meta_value' => serialize( array( 'subscriber' => true ) ),
						),
						array(
							'meta_key'   => 'session_tokens',
							'meta_value' => serialize( array( 'tok' => array() ) ),
						),
						array(
							'meta_key'   => 'nickname',
							'meta_value' => 'nick',
						),
					),
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

		self::assertIsArray( $summary );
		self::assertSame( 1, (int) $summary['created'] );
		$created = get_user_by( 'email', $new_email );
		self::assertInstanceOf( \WP_User::class, $created );
		global $wpdb;
		self::assertNotEmpty( get_user_meta( $created->ID, $wpdb->prefix . 'capabilities', true ) );
		self::assertSame( '', (string) get_user_meta( $created->ID, 'session_tokens', true ) );
		self::assertSame( 'nick', (string) get_user_meta( $created->ID, 'nickname', true ) );

		$local_id = self::factory()->user->create(
			array(
				'user_login' => 'keeporreplace',
				'user_email' => 'keep-replace-' . uniqid() . '@example.com',
				'role'       => 'subscriber',
			)
		);
		$local = get_user_by( 'id', $local_id );
		$email = strtolower( $local->user_email );

		$keep = $applier->merge(
			array(
				$email => array(
					'email' => $local->user_email,
					'row'   => array(
						'ID'            => 502,
						'user_login'    => 'remote-keep-login',
						'user_email'    => $local->user_email,
						'user_pass'     => wp_hash_password( 'x' ),
						'display_name'  => 'Remote',
						'user_nicename' => 'remote-keep-login',
						'user_url'      => '',
					),
					'meta'  => array(),
				),
			),
			array(
				$email => array(
					'import' => true,
					'mode'   => 'keep',
				),
			),
			'zzz_'
		);
		self::assertSame( 1, (int) $keep['skipped'] );
		self::assertSame( 'keeporreplace', get_userdata( $local_id )->user_login );

		$replace = $applier->merge(
			array(
				$email => array(
					'email' => $local->user_email,
					'row'   => array(
						'ID'            => 502,
						'user_login'    => 'remote-replaced',
						'user_email'    => $local->user_email,
						'user_pass'     => wp_hash_password( 'y' ),
						'display_name'  => 'Replaced',
						'user_nicename' => 'remote-replaced',
						'user_url'      => '',
					),
					'meta'  => array(),
				),
			),
			array(
				$email => array(
					'import' => true,
					'mode'   => 'replace',
				),
			),
			'zzz_'
		);
		self::assertSame( 1, (int) $replace['updated'] );
		self::assertSame( 'remote-replaced', get_userdata( $local_id )->user_login );
	}

	public function test__extract_remote_users__skips_empty_email(): void {
		$applier = new UserMergeApplier();
		$extracted = $applier->extract_remote_users(
			array(
				'tables' => array(
					'zzz_users' => array(
						'rows' => array(
							array(
								'ID'         => 1,
								'user_login' => 'noemail',
								'user_email' => '',
							),
							array(
								'ID'         => 2,
								'user_login' => 'ok',
								'user_email' => 'ok@example.com',
							),
						),
					),
					'zzz_usermeta' => array(
						'rows' => array(),
					),
				),
			)
		);

		self::assertArrayHasKey( 'ok@example.com', $extracted['users'] );
		self::assertCount( 1, $extracted['users'] );
	}

	public function test__strip_user_tables__removes_users_keeps_other(): void {
		$database = array(
			'tables' => array(
				'zzz_users'    => array( 'rows' => array() ),
				'zzz_usermeta' => array( 'rows' => array() ),
				'zzz_posts'    => array( 'rows' => array( array( 'ID' => 1 ) ) ),
			),
		);

		( new UserMergeApplier() )->strip_user_tables( $database );
		self::assertArrayNotHasKey( 'zzz_users', $database['tables'] );
		self::assertArrayNotHasKey( 'zzz_usermeta', $database['tables'] );
		self::assertArrayHasKey( 'zzz_posts', $database['tables'] );
	}

	public function test__user_diff_builder__conflict_and_new(): void {
		$local = get_userdata( $this->admin_id );
		$conflict_email = $local->user_email;
		$new_email      = 'diff-new-' . uniqid() . '@example.com';

		$archive = $this->tmpdir . '/users-diff.wpbkp';
		$payload = array(
			'type'     => 'full-site',
			'database' => array(
				'table_prefix' => 'zzz_',
				'tables'       => array(
					'zzz_users' => array(
						'schema' => '',
						'rows'   => array(
							array(
								'ID'              => 10,
								'user_login'      => 'conflictuser',
								'user_email'      => $conflict_email,
								'display_name'    => 'Conflict',
								'user_nicename'   => 'conflictuser',
								'user_registered' => '2020-01-01 00:00:00',
							),
							array(
								'ID'              => 11,
								'user_login'      => 'brandnew',
								'user_email'      => $new_email,
								'display_name'    => 'Brand New',
								'user_nicename'   => 'brandnew',
								'user_registered' => '2020-01-02 00:00:00',
							),
						),
					),
					'zzz_usermeta' => array(
						'schema' => '',
						'rows'   => array(
							array(
								'umeta_id'   => 1,
								'user_id'    => 10,
								'meta_key'   => 'zzz_capabilities',
								'meta_value' => serialize( array( 'editor' => true ) ),
							),
							array(
								'umeta_id'   => 2,
								'user_id'    => 11,
								'meta_key'   => 'zzz_capabilities',
								'meta_value' => serialize( array( 'author' => true ) ),
							),
						),
					),
				),
			),
		);

		ArchiveFixtureBuilder::create_wpbkp(
			$archive,
			array(
				'type'    => 'full-site',
				'version' => '1.0',
			),
			array(
				'payload/content.json' => wp_json_encode( $payload ),
			)
		);

		$diff = ( new UserDiffBuilder() )->build( $archive );
		self::assertIsArray( $diff, is_wp_error( $diff ) ? $diff->get_error_message() : '' );
		self::assertSame( 2, (int) $diff['counts']['incoming'] );
		self::assertSame( 1, (int) $diff['counts']['conflicts'] );

		$by_email = array();
		foreach ( $diff['incoming'] as $row ) {
			$by_email[ strtolower( (string) $row['email'] ) ] = $row['status'];
		}
		self::assertSame( 'conflict', $by_email[ strtolower( $conflict_email ) ] );
		self::assertSame( 'new', $by_email[ strtolower( $new_email ) ] );
	}

	public function test__user_preview_store__delete_related_to_preflight(): void {
		$store     = new UserPreviewStore();
		$report_id = 'pf_' . uniqid();

		$mine = $store->create(
			array(
				'preflight_report_id' => $report_id,
				'file_path'           => '/tmp/mine.wpbkp',
				'plan'                => array(),
			)
		);
		self::assertIsString( $mine );

		$other_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_id );
		$theirs = $store->create(
			array(
				'preflight_report_id' => $report_id,
				'file_path'           => '/tmp/theirs.wpbkp',
				'plan'                => array(),
			)
		);
		self::assertIsString( $theirs );

		wp_set_current_user( $this->admin_id );
		$deleted = $store->delete_related_to_preflight( $report_id, array(), $this->admin_id );
		self::assertSame( 1, $deleted );
		self::assertNull( $store->get( $mine ) );
		self::assertIsArray( $store->get( $theirs ) );

		$store->delete( $theirs );
	}
}
