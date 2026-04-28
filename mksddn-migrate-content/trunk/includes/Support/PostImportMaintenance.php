<?php
/**
 * @file: PostImportMaintenance.php
 * @description: Centralized cache/runtime cleanup after imports (object cache, rewrite, page-cache plugins).
 * @dependencies: None
 * @created: 2026-04-28
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-import maintenance: WordPress object cache, rewrites, third-party cache plugins.
 */
class PostImportMaintenance {

	/**
	 * Context passed to hooks (e.g. full_success, database_mutation_emergency).
	 *
	 * @var string
	 */
	private string $context = '';

	/**
	 * Run full maintenance after a successful full-site import.
	 *
	 * @return void
	 */
	public function run_after_full_import(): void {
		$this->context = 'full_success';
		$this->purge_object_cache();
		$this->purge_rewrite_and_runtime_state();
		$this->purge_page_cache_plugins();
	}

	/**
	 * Minimal cleanup when the database was mutated but the import did not finish cleanly.
	 *
	 * Avoids heavy plugin/WooCommerce work suitable only after a confirmed success path.
	 *
	 * @param string $reason Short reason code for logs and hooks.
	 * @return void
	 */
	public static function run_after_database_mutation( string $reason = 'unknown' ): void {
		$self            = new self();
		$self->context   = 'database_mutation_emergency';
		$self->purge_object_cache();
		$self->purge_rewrite_and_runtime_state();
		$self->purge_page_cache_plugins();

		/**
		 * Fires after emergency cache purge when DB may be inconsistent (fatal/error mid-import).
		 *
		 * @since 2.2.2
		 *
		 * @param string $reason Reason code.
		 */
		do_action( 'mksddn_mc_import_emergency_cache_purge', sanitize_key( $reason ) );
	}

	/**
	 * Invalidate WordPress object cache (persistent drop-ins included when supported).
	 *
	 * @return void
	 */
	public function purge_object_cache(): void {
		$flush_ok = null;
		if ( function_exists( 'wp_cache_flush' ) ) {
			$flush_ok = wp_cache_flush();
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && false === $flush_ok ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MksDdn Migrate: wp_cache_flush() returned false.' );
			}
		}

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
			wp_cache_set_posts_last_changed();
		}
		if ( function_exists( 'wp_cache_set_terms_last_changed' ) ) {
			wp_cache_set_terms_last_changed();
		}

		/**
		 * Fires after object cache purge during post-import maintenance.
		 *
		 * @since 2.2.2
		 *
		 * @param string $context Maintenance context.
		 */
		do_action( 'mksddn_mc_post_import_object_cache_purged', $this->context );
	}

	/**
	 * Clear rewrite rules and refresh common runtime option cache keys.
	 *
	 * @return void
	 */
	public function purge_rewrite_and_runtime_state(): void {
		delete_option( 'rewrite_rules' );

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Best-effort purge for popular page/HTML cache plugins and hosting integrations.
	 *
	 * @return void
	 */
	public function purge_page_cache_plugins(): void {
		$context = $this->context;

		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		if ( class_exists( '\autoptimizeCache' ) && method_exists( '\autoptimizeCache', 'clearall' ) ) {
			\autoptimizeCache::clearall();
		}

		/**
		 * LiteSpeed Cache purge-all hook.
		 *
		 * @since 2.2.2
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Third-party LiteSpeed Cache hook name is defined by the plugin.
		do_action( 'litespeed_purge_all' );

		/**
		 * Allow hosts/CDN/custom plugins to purge external caches after import.
		 *
		 * @since 2.2.2
		 *
		 * @param string $context Maintenance context: full_success, database_mutation_emergency, siteurl_restore, etc.
		 */
		do_action( 'mksddn_mc_post_import_cache_purge', $context );
	}

	/**
	 * WooCommerce tables and product cache after full replace (success path only).
	 *
	 * @return void
	 */
	public function run_woocommerce_maintenance(): void {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients();
		}
		if ( class_exists( '\WC_Install' ) ) {
			\WC_Install::check_version();
			\WC_Install::update_db_version();
		}
		if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
			wc_update_product_lookup_tables();
		}
	}
}
