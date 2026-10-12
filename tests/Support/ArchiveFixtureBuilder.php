<?php
/**
 * Builds temporary .wpbkp archives for tests (no binary fixtures in git).
 *
 * @package MksDdn_Migrate_Content
 */

declare(strict_types=1);

namespace MksDdn\MigrateContent\Tests\Support;

use ZipArchive;

/**
 * Runtime ZipArchive helper for unit and integration fixtures.
 */
final class ArchiveFixtureBuilder {

	/**
	 * Create a .wpbkp zip with manifest.json, checksum, and optional payload files.
	 *
	 * @param string               $destination Absolute path for the .wpbkp file.
	 * @param array<string, mixed> $manifest    Manifest payload (encoded as JSON).
	 * @param array<string, string> $files      Map of archive-relative path => file contents.
	 * @return string Destination path.
	 */
	public static function create_wpbkp( string $destination, array $manifest, array $files = array() ): string {
		$dir = dirname( $destination );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new \RuntimeException( 'Unable to create zip: ' . $destination );
		}

		$manifest_json = wp_json_encode( $manifest );
		if ( false === $manifest_json ) {
			$zip->close();
			throw new \RuntimeException( 'Unable to encode manifest JSON.' );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );

		$hashes = array(
			'manifest.json' => hash( 'sha256', $manifest_json ),
		);

		foreach ( $files as $path => $contents ) {
			$path = ltrim( str_replace( '\\', '/', (string) $path ), '/' );
			$zip->addFromString( $path, $contents );
			$hashes[ $path ] = hash( 'sha256', $contents );
		}

		$checksum = wp_json_encode(
			array(
				'algo'   => 'sha256',
				'files'  => $hashes,
				'bundle' => hash( 'sha256', implode( '', $hashes ) ),
			)
		);
		if ( false === $checksum ) {
			$zip->close();
			throw new \RuntimeException( 'Unable to encode checksum JSON.' );
		}

		$zip->addFromString( 'checksum', $checksum );
		$zip->close();

		return $destination;
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	public static function rrmdir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if ( is_dir( $path ) ) {
				self::rrmdir( $path );
			} else {
				unlink( $path );
			}
		}

		rmdir( $dir );
	}
}
