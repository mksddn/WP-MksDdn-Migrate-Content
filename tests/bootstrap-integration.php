<?php
/**
 * Integration-test bootstrap (full WordPress test suite + MySQL).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = $root . '/tmp/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite(
		STDERR,
		"WordPress test library not found at {$_tests_dir}.\n"
		. "Run: make install-wp-tests\n"
	);
	exit( 1 );
}

require_once $root . '/vendor/autoload.php';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP test suite callback.
function mksddn_mc_tests_manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/mksddn-migrate-content/trunk/mksddn-migrate-content.php';
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', 'mksddn_mc_tests_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
