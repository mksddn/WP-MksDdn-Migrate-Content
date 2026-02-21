<?php
/**
 * @file: ThemeExporter.php
 * @description: Exports selected themes to archive
 * @dependencies: ContentCollector, FilesystemHelper
 * @created: 2026-02-19
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Support\FilesystemHelper;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports selected themes to archive.
 *
 * @since 2.1.0
 */
class ThemeExporter {

	/**
	 * Theme directory prefix in archive.
	 */
	private const THEME_ARCHIVE_PREFIX = 'wp-content/themes/';

	/**
	 * Content collector.
	 *
	 * @var ContentCollector
	 */
	private ContentCollector $collector;

	/**
	 * Constructor.
	 *
	 * @param ContentCollector|null $collector Optional content collector.
	 */
	public function __construct( ?ContentCollector $collector = null ) {
		$this->collector = $collector ?? new ContentCollector();
	}

	/**
	 * Export selected themes to archive.
	 *
	 * @param array<string> $theme_slugs Array of theme slugs to export.
	 * @param string        $target_path  Absolute path to target archive file.
	 * @return string|WP_Error Archive path on success, WP_Error on failure.
	 */
	public function export_themes( array $theme_slugs, string $target_path ) {
		if ( empty( $theme_slugs ) ) {
			return new WP_Error( 'mksddn_mc_no_themes', __( 'No themes selected for export.', 'mksddn-migrate-content' ) );
		}

		$dir_result = FilesystemHelper::ensure_directory( dirname( $target_path ) );
		if ( is_wp_error( $dir_result ) ) {
			return new WP_Error( 'mksddn_zip_dir', __( 'Unable to create export directory.', 'mksddn-migrate-content' ) );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $target_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return new WP_Error( 'mksddn_zip_open', __( 'Unable to create archive for theme export.', 'mksddn-migrate-content' ) );
		}

		$theme_root = get_theme_root();
		$themes_data = array();

		// Get all themes at once to avoid N+1 queries.
		$all_themes = wp_get_themes();
		$active_stylesheet = get_stylesheet();
		$active_template = get_template();

		foreach ( $theme_slugs as $theme_slug ) {
			$theme_path = trailingslashit( $theme_root ) . $theme_slug;
			if ( ! is_dir( $theme_path ) ) {
				continue;
			}

			$theme = isset( $all_themes[ $theme_slug ] ) ? $all_themes[ $theme_slug ] : wp_get_theme( $theme_slug );
			$themes_data[] = array(
				'slug'        => $theme_slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'is_active'   => $active_stylesheet === $theme_slug,
				'is_parent'   => $active_template === $theme_slug && $active_stylesheet !== $theme_slug,
			);

			$archive_path = self::THEME_ARCHIVE_PREFIX . $theme_slug;
			$this->collector->append_directories( $zip, array( $archive_path => $theme_path ) );
		}

		$manifest = array(
			'format_version' => 1,
			'plugin_version' => MKSDDN_MC_VERSION,
			'type'           => 'themes',
			'created_at_gmt' => gmdate( 'c' ),
			'themes'         => $themes_data,
		);

		$payload = array(
			'type'   => 'themes',
			'themes' => $themes_data,
		);

		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$payload_json  = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		if ( false === $manifest_json || false === $payload_json ) {
			$zip->close();
			return new WP_Error( 'mksddn_mc_theme_export_payload', __( 'Failed to encode theme export payload.', 'mksddn-migrate-content' ) );
		}

		$zip->addFromString( 'manifest.json', $manifest_json );
		$zip->addFromString( 'payload/content.json', $payload_json );

		$zip->close();
		return $target_path;
	}

	/**
	 * Get available themes for export.
	 *
	 * @return array<string, array{name: string, slug: string, is_active: bool, is_parent: bool}>
	 */
	public function get_available_themes(): array {
		$themes = array();
		$theme_root = get_theme_root();
		$active_stylesheet = get_stylesheet();
		$active_template = get_template();

		if ( ! is_dir( $theme_root ) ) {
			return $themes;
		}

		$theme_dirs = glob( trailingslashit( $theme_root ) . '*', GLOB_ONLYDIR );
		if ( ! is_array( $theme_dirs ) ) {
			return $themes;
		}

		// Get all themes at once to avoid N+1 queries.
		$all_themes = wp_get_themes();

		foreach ( $theme_dirs as $theme_dir ) {
			$theme_slug = basename( $theme_dir );
			$theme = isset( $all_themes[ $theme_slug ] ) ? $all_themes[ $theme_slug ] : wp_get_theme( $theme_slug );

			if ( ! $theme->exists() ) {
				continue;
			}

			$themes[ $theme_slug ] = array(
				'slug'        => $theme_slug,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'description' => $theme->get( 'Description' ),
				'is_active'   => $active_stylesheet === $theme_slug,
				'is_parent'   => $active_template === $theme_slug && $active_stylesheet !== $theme_slug,
			);
		}

		return $themes;
	}
}
