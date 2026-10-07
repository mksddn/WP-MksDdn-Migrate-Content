<?php
/**
 * Integration: FullDatabaseImporter/Exporter on disposable custom tables.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Database\FullDatabaseImporter;
use MksDdn\MigrateContent\Database\SwapTableNames;
use WP_UnitTestCase;

/**
 * Exercises prefix rewrite, schema swap, leftover cleanup, and insert failure.
 *
 * Never imports live WordPress core tables.
 */
final class FullDatabaseRoundtripTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $tracked_tables = array();

	public function setUp(): void {
		parent::setUp();
		global $wpdb;

		// WP_UnitTestCase rewrites CREATE/DROP TABLE into TEMPORARY variants and
		// wraps each test in a transaction. FullDatabaseImporter relies on RENAME
		// between real tables, which fails for temporary tables.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'COMMIT;' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET autocommit = 1;' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function tearDown(): void {
		global $wpdb;
		foreach ( $this->tracked_tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- disposable test tables
		}
		$this->tracked_tables = array();

		// Drop any leftover swap tables for our probe prefixes.
		$like = $wpdb->esc_like( $wpdb->prefix . 'mksddn_t_' ) . '%';
		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( (array) $found as $name ) {
			$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', (string) $name ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		parent::tearDown();
	}

	public function test__import__rejects_empty_tables(): void {
		$importer = new FullDatabaseImporter();
		$result   = $importer->import( array( 'tables' => array() ) );

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'mksddn_db_empty', $result->get_error_code() );
		self::assertFalse( $importer->was_database_mutated() );
	}

	public function test__import__rewrites_foreign_prefix(): void {
		global $wpdb;

		$target = $wpdb->prefix . 'mksddn_t_probe';
		$this->track( $target );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $target . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$source = 'zzz_mksddn_t_probe';
		$schema = 'CREATE TABLE `' . $source . '` (id bigint(20) unsigned NOT NULL, label varchar(50) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB';

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => 'zzz_',
				'tables'       => array(
					$source => array(
						'schema' => $schema,
						'rows'   => array(
							array(
								'id'    => '1',
								'label' => 'hello',
							),
						),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( $target, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $target ) ) );
		self::assertSame( 'hello', $wpdb->get_var( "SELECT label FROM `{$target}` WHERE id = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$target}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::assertIsArray( $create );
		self::assertStringContainsString( '`' . $target . '`', (string) $create[1] );
		self::assertStringNotContainsString( '`zzz_mksddn_t_probe`', (string) $create[1] );
	}

	public function test__import__schema_replaces_existing_rows(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mksddn_t_swapdata';
		$this->track( $table );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			"CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL, label varchar(50) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert( $table, array( 'id' => 1, 'label' => 'old' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$table => array(
						'schema' => "CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL, label varchar(50) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
						'rows'   => array(
							array(
								'id'    => '1',
								'label' => 'new',
							),
						),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame( 'new', $wpdb->get_var( "SELECT label FROM `{$table}` WHERE id = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::assertSame( '1', $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test__import__skips_internal_swap_table_from_dump(): void {
		global $wpdb;

		$swap = $wpdb->prefix . 'mksddn_t_skip_mknabcd1234';
		$this->track( $swap );
		self::assertTrue( SwapTableNames::is_internal( $swap ) );

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$swap => array(
						'schema' => "CREATE TABLE `{$swap}` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
						'rows'   => array( array( 'id' => '9' ) ),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $swap ) ) );
	}

	public function test__import__drops_leftover_mkn_and_recovers_mko(): void {
		global $wpdb;

		$mkn = $wpdb->prefix . 'mksddn_t_left_mknabcd1234';
		$orig = $wpdb->prefix . 'mksddn_t_recover';
		$mko  = $orig . '_mkoabcd1234';
		$other = $wpdb->prefix . 'mksddn_t_other';

		$this->track( $mkn );
		$this->track( $orig );
		$this->track( $mko );
		$this->track( $other );

		$wpdb->query( 'DROP TABLE IF EXISTS `' . $mkn . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $orig . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $mko . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $other . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$wpdb->query( "CREATE TABLE `{$mkn}` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE `{$mko}` (id bigint(20) unsigned NOT NULL, label varchar(20) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->insert( $mko, array( 'id' => 1, 'label' => 'kept' ), array( '%d', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$other => array(
						'schema' => "CREATE TABLE `{$other}` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
						'rows'   => array( array( 'id' => '1' ) ),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mkn ) ) );
		self::assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mko ) ) );
		self::assertSame( $orig, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orig ) ) );
		self::assertSame( 'kept', $wpdb->get_var( "SELECT label FROM `{$orig}` WHERE id = 1" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public function test__import__without_user_tables_preserves_live_users(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mksddn_t_plain';
		$this->track( $table );

		$users_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$usermeta_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = ( new FullDatabaseImporter() )->import(
			array(
				'table_prefix' => $wpdb->prefix,
				'tables'       => array(
					$table => array(
						'schema' => "CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
						'rows'   => array( array( 'id' => '1' ) ),
					),
				),
			)
		);

		self::assertTrue( true === $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		self::assertSame(
			$users_before,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
		self::assertSame(
			$usermeta_before,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		);
	}

	public function test__export__excludes_internal_swap_tables(): void {
		global $wpdb;

		$swap = $wpdb->prefix . 'mksddn_t_exp_mknabcd1234';
		$this->track( $swap );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $swap . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE `{$swap}` (id bigint(20) unsigned NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		$dump = ( new FullDatabaseExporter() )->export();
		self::assertIsArray( $dump, is_wp_error( $dump ) ? $dump->get_error_message() : '' );
		self::assertArrayHasKey( 'tables', $dump );
		self::assertArrayNotHasKey( $swap, $dump['tables'] );
	}

	public function test__import__insert_failure_returns_error(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'mksddn_t_badrow';
		$this->track( $table );
		$wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Without STRICT, MySQL fills missing NOT NULL varchar columns with ''.
		$previous_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.sql_mode' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		try {
			$importer = new FullDatabaseImporter();
			$result   = $importer->import(
				array(
					'table_prefix' => $wpdb->prefix,
					'tables'       => array(
						$table => array(
							'schema' => "CREATE TABLE `{$table}` (id bigint(20) unsigned NOT NULL, required_col varchar(50) NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB",
							'rows'   => array(
								array( 'id' => '1' ), // missing required_col → DEFAULT under STRICT fails
							),
						),
					),
				)
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $previous_mode ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		self::assertTrue( is_wp_error( $result ) );
		self::assertSame( 'mksddn_db_insert_failed', $result->get_error_code() );
	}

	/**
	 * Track a table for tearDown cleanup.
	 *
	 * @param string $table Table name.
	 */
	private function track( string $table ): void {
		$this->tracked_tables[] = $table;
	}
}
