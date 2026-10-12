#!/usr/bin/env php
<?php
/**
 * Infection wrapper: define plugin ABSPATH guards before ReflectionVisitor autoloads includes.
 *
 * Plugin PHP files call `exit` when ABSPATH is undefined. Infection's ReflectionVisitor
 * autoloads those classes during mutation generation, which otherwise aborts the process.
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

$root = dirname( __DIR__ );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/tmp/unit-abspath/' );
}

if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}

$argv_for_infection = array_values(
	array_filter(
		$argv,
		static function ( string $arg, int $index ): bool {
			// Drop this wrapper path (argv0) so Infection sees its own argv0.
			return 0 !== $index;
		},
		ARRAY_FILTER_USE_BOTH
	)
);

$GLOBALS['argv'] = array_merge(
	array( $root . '/vendor/bin/infection' ),
	$argv_for_infection
);
$_SERVER['argv'] = $GLOBALS['argv'];

require $root . '/vendor/bin/infection';
