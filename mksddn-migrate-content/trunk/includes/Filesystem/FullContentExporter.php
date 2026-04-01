<?php
/**
 * Exports full wp-content (uploads, plugins, mu-plugins, themes).
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Database\FullDatabaseExporter;
use MksDdn\MigrateContent\Support\ExportMemoryHelper;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects filesystem data for bundling.
 */
class FullContentExporter {

	private FullDatabaseExporter $db_exporter;
	private ContentCollector $collector;

	/**
	 * Setup exporter.
	 *
	 * @param FullDatabaseExporter|null $db_exporter Optional DB exporter.
	 */
	public function __construct( ?FullDatabaseExporter $db_exporter = null, ?ContentCollector $collector = null ) {
		$this->db_exporter = $db_exporter ?? new FullDatabaseExporter();
		$this->collector   = $collector ?? new ContentCollector();
	}

	/**
	 * Build archive with uploads/plugins/themes and DB dump.
	 *
	 * @param string $target_path Absolute temp filepath.
	 * @return string|WP_Error
	 */
	public function export_to( string $target_path ) {
		$original_memory = ExportMemoryHelper::raise_for_export();
		try {
			$runner = new FullContentExportRunner( $this->db_exporter, $this->collector );
			return $runner->export_synchronous( $target_path );
		} finally {
			ExportMemoryHelper::restore( $original_memory );
		}
	}

	/**
	 * Map of archive paths to real directories for full-site file bundle.
	 *
	 * @param string $base_prefix Prefix inside archive (e.g. files).
	 * @return array<string, string>
	 */
	public function get_content_directory_map( string $base_prefix = 'files' ): array {
		return self::build_content_directory_map( $base_prefix );
	}

	/**
	 * Map archive targets to physical directories (shared with incremental export).
	 *
	 * @param string $base_prefix Optional base folder inside archive.
	 * @return array<string, string>
	 */
	public static function build_content_directory_map( string $base_prefix = 'files' ): array {
		$prefix = '' === $base_prefix ? '' : trim( $base_prefix, '/' ) . '/';
		$uploads = wp_upload_dir();

		$mu_plugins_dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		return array(
			$prefix . 'wp-content/uploads'     => $uploads['basedir'],
			$prefix . 'wp-content/plugins'    => dirname( plugin_dir_path( MKSDDN_MC_FILE ) ),
			$prefix . 'wp-content/mu-plugins'  => $mu_plugins_dir,
			$prefix . 'wp-content/themes'      => get_theme_root(),
		);
	}

}

