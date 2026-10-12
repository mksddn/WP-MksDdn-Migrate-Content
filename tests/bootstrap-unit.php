<?php
/**
 * Unit-test bootstrap (unitest-wp-copy + WP_Mock, no WordPress DB).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin_dir = $root . '/mksddn-migrate-content/trunk/';

require_once $root . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/tmp/unit-abspath/' );
}

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
}

if ( ! defined( 'WP_CONTENT_URL' ) ) {
	define( 'WP_CONTENT_URL', 'https://wp.test/wp-content' );
}

if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'development' );
}

if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
}

if ( ! defined( 'MKSDDN_MC_VERSION' ) ) {
	$plugin_data = file_get_contents( $plugin_dir . 'mksddn-migrate-content.php', false, null, 0, 2048 );
	preg_match( '/^\s*\*?\s*Version:\s*(\S+)/mi', (string) $plugin_data, $version_match );
	define( 'MKSDDN_MC_VERSION', $version_match[1] ?? '0.0.0' );
}

if ( ! defined( 'MKSDDN_MC_FILE' ) ) {
	define( 'MKSDDN_MC_FILE', $plugin_dir . 'mksddn-migrate-content.php' );
}

if ( ! defined( 'MKSDDN_MC_DIR' ) ) {
	define( 'MKSDDN_MC_DIR', $plugin_dir );
}

if ( ! defined( 'MKSDDN_MC_URL' ) ) {
	define( 'MKSDDN_MC_URL', 'https://wp.test/wp-content/plugins/mksddn-migrate-content/' );
}

if ( ! defined( 'MKSDDN_MC_TEXT_DOMAIN' ) ) {
	define( 'MKSDDN_MC_TEXT_DOMAIN', 'mksddn-migrate-content' );
}

if ( ! defined( 'MKSDDN_MC_BASENAME' ) ) {
	define( 'MKSDDN_MC_BASENAME', 'mksddn-migrate-content/mksddn-migrate-content.php' );
}

if ( ! is_dir( ABSPATH ) ) {
	mkdir( ABSPATH, 0777, true );
}

if ( ! is_dir( WP_CONTENT_DIR ) ) {
	mkdir( WP_CONTENT_DIR, 0777, true );
}

\Unitest_WP_Copy\WP_Runtime::boot();
\WP_Mock::bootstrap();
