<?php
/**
 * Integration: full-site subset (DB export, domain replace, swap names, locks).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Integration;

use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Database\SwapTableNames;
use MksDdn\MigrateContent\Support\DomainReplacer;
use MksDdn\MigrateContent\Support\ExportPreflight;
use MksDdn\MigrateContent\Support\ImportLock;
use WP_UnitTestCase;

/**
 * Database export + DomainReplacer + ImportLock smoke.
 */
final class FullSiteSubsetTest extends WP_UnitTestCase {

	public function test__full_database_exporter__includes_options_table(): void {
		update_option( 'mksddn_mc_test_option', 'https://old.example/path' );

		$dump = ( new FullDatabaseExporter() )->export();
		self::assertIsArray( $dump, is_wp_error( $dump ) ? $dump->get_error_message() : '' );
		self::assertArrayHasKey( 'tables', $dump );
		self::assertNotEmpty( $dump['tables'] );

		$found = false;
		foreach ( $dump['tables'] as $table_name => $table ) {
			if ( is_string( $table_name ) && false !== strpos( $table_name, 'options' ) ) {
				$found = true;
				break;
			}
			if ( is_array( $table ) && isset( $table['name'] ) && false !== strpos( (string) $table['name'], 'options' ) ) {
				$found = true;
				break;
			}
		}
		// Dump may key tables by name or use numeric list with name field.
		if ( ! $found && isset( $dump['tables'] ) && is_array( $dump['tables'] ) ) {
			$json = wp_json_encode( $dump['tables'] );
			$found = is_string( $json ) && false !== strpos( $json, 'options' );
		}
		self::assertTrue( $found, 'options table missing from dump' );

		( new DomainReplacer() )->replace_dump_environment(
			$dump,
			'https://new.example',
			array()
		);

		$encoded = wp_json_encode( $dump );
		self::assertIsString( $encoded );
		self::assertStringContainsString( 'new.example', $encoded );
	}

	public function test__swap_table_names__recognizes_live_leftovers(): void {
		global $wpdb;
		$name = $wpdb->prefix . 'posts_mknabcd1234';
		self::assertTrue( SwapTableNames::is_internal( $name ) );
		self::assertTrue( SwapTableNames::is_new_swap( $name ) );
	}

	public function test__import_lock__acquire_release_cycle(): void {
		delete_transient( 'mksddn_mc_import_lock' );
		$lock  = new ImportLock();
		$token = $lock->acquire( 60 );
		self::assertNotFalse( $token );
		self::assertFalse( $lock->acquire( 60 ), 'second acquire must fail while locked' );
		$lock->release( (string) $token );
		$again = $lock->acquire( 60 );
		self::assertNotFalse( $again );
		$lock->release( (string) $again );
		delete_transient( 'mksddn_mc_import_lock' );
	}

	public function test__export_preflight__runs_without_fatal(): void {
		$result = ( new ExportPreflight() )->validate_full_export();
		self::assertTrue( true === $result || is_wp_error( $result ) || is_array( $result ) );
	}
}
