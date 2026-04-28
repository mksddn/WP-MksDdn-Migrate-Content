<?php
/**
 * @file: DeactivationCleanup.php
 * @description: Removes temporary plugin data on deactivation (chunk jobs, caches, locks)
 * @dependencies: Config\PluginConfig, Support\FilesystemHelper, Themes\ThemePreviewStore
 * @created: 2026-04-03
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Themes\ThemePreviewStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service data cleanup when the plugin is deactivated.
 *
 * Does not remove `uploads/mksddn-mc/imports/` (user-provided backup files).
 *
 * @since 2.1.7
 */
final class DeactivationCleanup {

	/**
	 * Run all cleanup steps.
	 *
	 * @return void
	 */
	public static function run(): void {
		self::delete_path_if_safe( self::jobs_directory_path() );
		self::delete_path_if_safe( self::theme_backups_root() );

		/**
		 * Whether to delete the server-side imports directory on deactivation.
		 *
		 * Default false: large backups are often placed there intentionally.
		 *
		 * @since 2.1.7
		 * @param bool $clear_imports Whether to remove uploads/.../mksddn-mc/imports/.
		 */
		if ( apply_filters( 'mksddn_mc_deactivation_clear_imports', false ) ) {
			$dirs = PluginConfig::get_required_directories();
			if ( ! empty( $dirs['imports'] ) ) {
				self::delete_path_if_safe( $dirs['imports'] );
			}
		}

		delete_transient( 'mksddn_mc_import_lock' );
		delete_transient( 'mksddn_mc_server_backups' );
		FullImportMaintenance::deactivate();
		delete_option( 'mksddn_mc_storage_path' );

		self::purge_user_preview_transients();
		( new ThemePreviewStore() )->purge_all();
		self::purge_theme_preview_transients_orphans();

		/**
		 * Fires after default deactivation cleanup.
		 *
		 * @since 2.1.7
		 */
		do_action( 'mksddn_mc_deactivation_cleanup' );
	}

	/**
	 * Absolute path to chunk jobs directory.
	 *
	 * @return string
	 */
	private static function jobs_directory_path(): string {
		$dirs = PluginConfig::get_required_directories();
		return isset( $dirs['jobs'] ) ? (string) $dirs['jobs'] : '';
	}

	/**
	 * Theme backup directory under wp-content (full-site import).
	 *
	 * @return string
	 */
	private static function theme_backups_root(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'mksddn-mc/theme-backups/';
	}

	/**
	 * Remove a directory tree only if it resolves under an allowed plugin root.
	 *
	 * @param string $path Absolute directory path (trailing slash optional).
	 * @return void
	 */
	private static function delete_path_if_safe( string $path ): void {
		$path = wp_normalize_path( $path );
		if ( '' === $path ) {
			return;
		}

		$path = trailingslashit( $path );
		$real = realpath( untrailingslashit( $path ) );
		if ( false === $real || ! is_dir( $real ) ) {
			return;
		}

		$real = trailingslashit( wp_normalize_path( $real ) );

		$allowed = array(
			trailingslashit( wp_normalize_path( PluginConfig::uploads_base_dir() ) ),
			trailingslashit( wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . 'mksddn-mc' ) ),
		);

		$ok = false;
		foreach ( $allowed as $root ) {
			if ( $root && str_starts_with( $real, $root ) ) {
				$ok = true;
				break;
			}
		}

		if ( ! $ok ) {
			return;
		}

		FilesystemHelper::delete( untrailingslashit( $real ), true );
	}

	/**
	 * Remove user-merge preview transients (no index option; prefix-based).
	 *
	 * @return void
	 */
	private static function purge_user_preview_transients(): void {
		global $wpdb;

		$prefix = '_transient_mksddn_mc_user_preview_';
		$tout   = '_transient_timeout_mksddn_mc_user_preview_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deactivation cleanup; transients are not enumerable via API.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%',
				$wpdb->esc_like( $tout ) . '%'
			)
		);
	}

	/**
	 * Remove any remaining theme preview transients (matches store prefix).
	 *
	 * @return void
	 */
	private static function purge_theme_preview_transients_orphans(): void {
		global $wpdb;

		$prefix = '_transient_mksddn_mc_theme_preview_';
		$tout   = '_transient_timeout_mksddn_mc_theme_preview_';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deactivation cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%',
				$wpdb->esc_like( $tout ) . '%'
			)
		);
	}
}
