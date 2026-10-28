<?php
/**
 * @file: DumpTableResolverTest.php
 * @description: Unit tests for core user table resolution inside a dump
 * @dependencies: Database\DumpTableResolver
 * @created: 2026-10-28
 */

namespace MksDdn\MigrateContent\Tests\Unit\Database;

use MksDdn\MigrateContent\Database\DumpTableResolver;
use WP_Mock\Tools\TestCase;

/**
 * @covers \MksDdn\MigrateContent\Database\DumpTableResolver
 */
class DumpTableResolverTest extends TestCase {

	/**
	 * Foreign Plugin Check tables must not shadow the real users table.
	 */
	public function test_foreign_prefix_does_not_shadow_real_users_table(): void {
		$tables = array( 'wp_options', 'wp_posts', 'wp_usermeta', 'wp_users', 'wppc_users', 'wppc_usermeta' );
		// Alphabetical order would put wp_users first, so reverse to prove ordering is irrelevant.
		$tables = array_reverse( $tables );

		self::assertSame( 'wp_users', DumpTableResolver::find( $tables, 'users', 'wp_' ) );
		self::assertSame( 'wp_usermeta', DumpTableResolver::find( $tables, 'usermeta', 'wp_' ) );
		self::assertSame( 'wp_users', DumpTableResolver::find( $tables, 'users' ) );
		self::assertSame( 'wp_usermeta', DumpTableResolver::find( $tables, 'usermeta' ) );
	}

	/**
	 * A stray table ending with "users" must not be picked over core tables.
	 */
	public function test_plugin_table_ending_with_users_is_ignored(): void {
		$tables = array( 'wp_aaa_users', 'wp_options', 'wp_posts', 'wp_terms', 'wp_usermeta', 'wp_users' );

		self::assertSame( 'wp_users', DumpTableResolver::find( $tables, 'users' ) );
		self::assertSame( 'wp_usermeta', DumpTableResolver::find( $tables, 'usermeta' ) );
	}

	/**
	 * Missing user tables resolve to null when the prefix is known.
	 */
	public function test_absent_user_tables_return_null(): void {
		$tables = array( 'wp_options', 'wp_posts', 'wp_terms' );

		self::assertNull( DumpTableResolver::find( $tables, 'users', 'wp_' ) );
	}

	/**
	 * Preferred prefix must not fall back to foreign user tables when real ones are absent.
	 */
	public function test_preferred_prefix_does_not_fall_back_to_foreign_users(): void {
		$tables = array( 'wppc_users', 'wppc_usermeta', 'wp_options', 'wp_posts', 'wp_terms' );

		self::assertNull( DumpTableResolver::find( $tables, 'users', 'wp_' ) );
		self::assertNull( DumpTableResolver::find( $tables, 'usermeta', 'wp_' ) );
		self::assertSame( 'wp_', DumpTableResolver::resolve_prefix( $tables, 'wp_' ) );
	}

	/**
	 * Without preferred prefix, scoring must pick the stronger core set even if a foreign set is listed first.
	 */
	public function test_resolve_prefix_prefers_stronger_core_set_over_listing_order(): void {
		$tables = array(
			'wppc_options',
			'wppc_posts',
			'wppc_terms',
			'wppc_term_taxonomy',
			'wppc_usermeta',
			'wppc_users',
			'wp_options',
			'wp_posts',
			'wp_terms',
			'wp_term_taxonomy',
			'wp_usermeta',
			'wp_users',
		);

		self::assertSame( 'wp_', DumpTableResolver::resolve_prefix( $tables ) );
		self::assertSame( 'wp_users', DumpTableResolver::find( $tables, 'users' ) );
	}

	/**
	 * Prefix can be detected from non-user core tables (legacy dumps without users).
	 */
	public function test_resolve_prefix_from_non_user_core_tables(): void {
		$tables = array( 'wp_options', 'wp_posts', 'wp_terms', 'wp_term_taxonomy' );

		self::assertSame( 'wp_', DumpTableResolver::resolve_prefix( $tables ) );
	}
}
