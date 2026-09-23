<?php
/**
 * @file: PostImportMaintenance.php
 * @description: Centralized cache/runtime cleanup after imports (object cache, rewrite, page-cache plugins, deferred WooCommerce maintenance).
 * @dependencies: Services\PluginLogger
 * @created: 2026-04-28
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Services\PluginLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-import maintenance: WordPress object cache, rewrites, third-party cache plugins.
 */
class PostImportMaintenance {

	/**
	 * Cron hook and admin-post action for WooCommerce maintenance in a fresh PHP process.
	 *
	 * The import request has already replaced wp-content/plugins, so WooCommerce
	 * classes loaded earlier in that process must not be called.
	 *
	 * @var string
	 */
	public const WOOCOMMERCE_MAINTENANCE_HOOK = 'mksddn_mc_woocommerce_post_import_maintenance';

	/**
	 * Single-use token that authorizes the deferred maintenance request.
	 *
	 * Stored as a non-autoload option. A transient lives only in the object cache
	 * when an external cache drop-in is loaded, and that drop-in is the one from
	 * process start — the next request loads object-cache.php from disk.
	 *
	 * @var string
	 */
	private const PENDING_TOKEN_OPTION = 'mksddn_mc_wc_maint_token';

	/**
	 * How long a deferred maintenance token stays valid.
	 *
	 * Long enough for a delayed cron hit when the loopback could not start.
	 */
	private const PENDING_TOKEN_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Product transients safe to delete without calling WooCommerce.
	 *
	 * @var string[]
	 */
	private const SAFE_PRODUCT_TRANSIENTS = array(
		'wc_products_onsale',
		'wc_featured_products',
		'wc_outofstock_count',
		'wc_low_stock_count',
	);

	/**
	 * WooCommerce class whose in-memory shape must match wc_delete_product_transients().
	 *
	 * @var string
	 */
	private const PRODUCT_UTIL_CLASS = 'Automattic\\WooCommerce\\Internal\\Utilities\\ProductUtil';

	/**
	 * Context passed to hooks (e.g. full_success, database_mutation_emergency).
	 *
	 * @var string
	 */
	private string $context = '';

	/**
	 * Register cron and loopback handlers.
	 *
	 * Must run on every request, including wp-cron.php, before the event fires.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	public static function register_hooks(): void {
		add_action( self::WOOCOMMERCE_MAINTENANCE_HOOK, array( self::class, 'handle_deferred_woocommerce_maintenance' ), 10, 1 );
		add_action( 'admin_post_' . self::WOOCOMMERCE_MAINTENANCE_HOOK, array( self::class, 'handle_admin_post_woocommerce_maintenance' ) );
		add_action( 'admin_post_nopriv_' . self::WOOCOMMERCE_MAINTENANCE_HOOK, array( self::class, 'handle_admin_post_woocommerce_maintenance' ) );
	}

	/**
	 * Drop a scheduled WooCommerce maintenance event and its token.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	public static function clear_scheduled_woocommerce_maintenance(): void {
		wp_clear_scheduled_hook( self::WOOCOMMERCE_MAINTENANCE_HOOK );
		delete_option( self::PENDING_TOKEN_OPTION );
		delete_transient( self::PENDING_TOKEN_OPTION );
	}

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
	 * Calls `wp_cache_flush()` first, then flushes critical cache groups when the drop-in
	 * supports `flush_group` (WordPress 6.1+). Some persistent backends omit invalidation on
	 * direct TRUNCATE/INSERT; bumping posts/terms last_changed and clearing `post-queries`
	 * reduces stale WP_Query caches in admin screens.
	 *
	 * @return void
	 */
	public function purge_object_cache(): void {
		if ( function_exists( 'wp_cache_flush' ) ) {
			$flush_ok = wp_cache_flush();
			if ( false === $flush_ok ) {
				PluginLogger::log(
					'wp_cache_flush() returned false after full import maintenance.',
					'object cache'
				);
			}
		}

		$this->flush_critical_object_cache_groups();

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

		if ( function_exists( 'wp_cache_set_comments_last_changed' ) ) {
			wp_cache_set_comments_last_changed();
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
	 * Best-effort per-group flush when supported (fallback if global flush is ineffective).
	 *
	 * @return void
	 */
	private function flush_critical_object_cache_groups(): void {
		if ( ! function_exists( 'wp_cache_flush_group' ) ) {
			$this->log_object_cache_diagnostic(
				'wp_cache_flush_group() not available; using global wp_cache_flush() and last_changed only.'
			);
			return;
		}

		if ( function_exists( 'wp_cache_supports' ) && ! wp_cache_supports( 'flush_group' ) ) {
			$this->log_object_cache_diagnostic(
				'Object cache does not support flush_group; ensure drop-in clears cache after DB restore or flush Redis/Memcached manually.'
			);
			return;
		}

		$groups = array(
			'posts',
			'post-queries',
			'post_meta',
			'terms',
			'term-queries',
			'term_meta',
			'options',
			'users',
			'user_meta',
			'comment-queries',
			'comment',
			'counts',
		);

		/**
		 * Filter cache groups flushed after full import object cache purge.
		 *
		 * Use to add multisite/global groups or hosting-specific keys.
		 *
		 * @since 2.3.1
		 *
		 * @param string[] $groups  Group names passed to wp_cache_flush_group().
		 * @param string   $context Maintenance context (e.g. full_success).
		 */
		$groups = apply_filters( 'mksddn_mc_post_import_object_cache_flush_groups', $groups, $this->context );

		if ( ! is_array( $groups ) ) {
			return;
		}

		foreach ( array_unique( array_filter( array_map( 'strval', $groups ) ) ) as $group ) {
			$group = sanitize_key( $group );
			if ( '' === $group ) {
				continue;
			}

			$ok = wp_cache_flush_group( $group );
			if ( false === $ok ) {
				$this->log_object_cache_diagnostic( sprintf( 'wp_cache_flush_group( %s ) returned false.', $group ) );
			}
		}
	}

	/**
	 * Emit an object-cache diagnostic log entry.
	 *
	 * @param string $message Human-readable explanation.
	 * @return void
	 */
	private function log_object_cache_diagnostic( string $message ): void {
		PluginLogger::log( $message, 'object cache' );
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
	 * Clear known product transients and schedule WooCommerce maintenance for a new process.
	 *
	 * Call this from the request that replaced wp-content/plugins. That process may still
	 * have WooCommerce 10.9.x classes in memory while 11.x files are already on disk.
	 * Calling wc_delete_product_transients() there fatals on ProductUtil::delete_product_transients_for_products().
	 *
	 * @return void
	 * @since 2.7.2
	 */
	public function defer_woocommerce_maintenance(): void {
		try {
			$this->clear_woocommerce_product_transients_safely();
		} catch ( \Throwable $error ) {
			PluginLogger::log(
				'Safe WooCommerce transient cleanup failed: ' . $error->getMessage(),
				'PostImportMaintenance'
			);
		}

		try {
			$this->schedule_deferred_woocommerce_maintenance();
		} catch ( \Throwable $error ) {
			PluginLogger::log(
				'Failed to schedule WooCommerce post-import maintenance: ' . $error->getMessage(),
				'PostImportMaintenance'
			);
		}
	}

	/**
	 * WooCommerce tables and product cache after full replace.
	 *
	 * Run only from a fresh request (cron or admin-post loopback) so ProductUtil
	 * is loaded from disk. Failures are logged and do not propagate.
	 *
	 * @return void
	 */
	public function run_woocommerce_maintenance(): void {
		$this->run_woocommerce_step(
			function (): void {
				if ( ! function_exists( 'wc_delete_product_transients' ) ) {
					return;
				}

				if ( ! $this->loaded_product_util_can_delete_transients() ) {
					PluginLogger::log(
						'Skipped wc_delete_product_transients(): loaded ProductUtil has no delete_product_transients_for_products().',
						'PostImportMaintenance'
					);
					return;
				}

				wc_delete_product_transients();
			},
			'wc_delete_product_transients'
		);

		$this->run_woocommerce_step(
			function (): void {
				if ( ! class_exists( '\WC_Install' ) ) {
					return;
				}

				\WC_Install::check_version();
				\WC_Install::update_db_version();
			},
			'WC_Install'
		);

		$this->run_woocommerce_step(
			function (): void {
				if ( function_exists( 'wc_update_product_lookup_tables' ) ) {
					wc_update_product_lookup_tables();
				}
			},
			'wc_update_product_lookup_tables'
		);
	}

	/**
	 * Cron callback. The argument is the single-use token stored at schedule time.
	 *
	 * @param string $token Maintenance token.
	 * @return bool True when maintenance ran.
	 * @since 2.7.2
	 */
	public static function handle_deferred_woocommerce_maintenance( string $token = '' ): bool {
		if ( ! self::consume_pending_token( $token ) ) {
			return false;
		}

		try {
			( new self() )->run_woocommerce_maintenance();
			PluginLogger::log( 'Deferred WooCommerce post-import maintenance finished.', 'PostImportMaintenance' );
			return true;
		} catch ( \Throwable $error ) {
			PluginLogger::log(
				'Deferred WooCommerce post-import maintenance failed: ' . $error->getMessage(),
				'PostImportMaintenance'
			);
			return true;
		}
	}

	/**
	 * Admin-post loopback callback used when WP-Cron cannot start a fresh process.
	 *
	 * Loopback requests have no logged-in cookie, so the nopriv hook is required.
	 * Authorization is the single-use transient token from the import request.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	public static function handle_admin_post_woocommerce_maintenance(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Single-use transient token; non-strings are rejected and strings are sanitized next.
		$raw   = isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '';
		$token = is_string( $raw ) ? sanitize_text_field( $raw ) : '';

		if ( self::handle_deferred_woocommerce_maintenance( $token ) ) {
			return;
		}

		wp_die(
			esc_html__( 'Forbidden.', 'mksddn-migrate-content' ),
			esc_html__( 'Forbidden.', 'mksddn-migrate-content' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Delete fixed WooCommerce product transients without loading plugin APIs.
	 *
	 * Bumps the product transient version only when that helper is already loaded
	 * and exposes the method. Does not autoload WooCommerce classes.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	private function clear_woocommerce_product_transients_safely(): void {
		foreach ( self::SAFE_PRODUCT_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}

		if ( ! class_exists( '\WC_Cache_Helper', false ) ) {
			return;
		}

		if ( ! method_exists( '\WC_Cache_Helper', 'get_transient_version' ) ) {
			return;
		}

		\WC_Cache_Helper::get_transient_version( 'product', true );
	}

	/**
	 * Queue WooCommerce maintenance on WP-Cron, or ping admin-post when cron cannot run.
	 *
	 * @return void
	 * @since 2.7.2
	 */
	private function schedule_deferred_woocommerce_maintenance(): void {
		if ( ! function_exists( 'wp_generate_password' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$token = wp_generate_password( 32, false, false );
		if ( ! self::store_pending_token( $token ) ) {
			PluginLogger::log(
				'Could not store WooCommerce maintenance token; deferred maintenance was not scheduled.',
				'PostImportMaintenance'
			);
			return;
		}

		$scheduled     = wp_schedule_single_event( time(), self::WOOCOMMERCE_MAINTENANCE_HOOK, array( $token ), true );
		$schedule_ok   = true === $scheduled || ( is_wp_error( $scheduled ) && 'duplicate_event' === $scheduled->get_error_code() );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		// The follow-up request must get past wp_maintenance() before it can load plugins.
		FullImportMaintenance::lift_core_maintenance();

		if ( $schedule_ok && ! $cron_disabled && function_exists( 'spawn_cron' ) && spawn_cron() ) {
			PluginLogger::log( 'Scheduled WooCommerce post-import maintenance on WP-Cron.', 'PostImportMaintenance' );
			return;
		}

		if ( ! $schedule_ok ) {
			$message = is_wp_error( $scheduled ) ? $scheduled->get_error_message() : 'unknown error';
			PluginLogger::log(
				'Could not schedule WooCommerce maintenance cron (' . $message . '). Dispatching admin-post loopback.',
				'PostImportMaintenance'
			);
		} elseif ( $cron_disabled ) {
			PluginLogger::log(
				'WP-Cron is disabled. Dispatching WooCommerce maintenance via admin-post loopback.',
				'PostImportMaintenance'
			);
		} else {
			PluginLogger::log(
				'WP-Cron event is scheduled but could not be spawned. Dispatching admin-post loopback.',
				'PostImportMaintenance'
			);
		}

		$this->dispatch_woocommerce_maintenance_loopback( $token );
	}

	/**
	 * Start a non-blocking admin-post request so maintenance runs in a new PHP process.
	 *
	 * @param string $token Single-use maintenance token.
	 * @return void
	 * @since 2.7.2
	 */
	private function dispatch_woocommerce_maintenance_loopback( string $token ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter used by spawn_cron() for local loopback.
		$sslverify = apply_filters( 'https_local_ssl_verify', false );

		$response = wp_remote_post(
			admin_url( 'admin-post.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => $sslverify,
				'body'      => array(
					'action' => self::WOOCOMMERCE_MAINTENANCE_HOOK,
					'token'  => $token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			PluginLogger::log(
				'WooCommerce maintenance loopback failed to dispatch: ' . $response->get_error_message(),
				'PostImportMaintenance'
			);
		}
	}

	/**
	 * Persist the token in the options table for the next PHP process.
	 *
	 * @param string $token Single-use maintenance token.
	 * @return bool
	 * @since 2.7.2
	 */
	private static function store_pending_token( string $token ): bool {
		$payload = array(
			'token'   => $token,
			'expires' => time() + self::PENDING_TOKEN_TTL,
		);

		update_option( self::PENDING_TOKEN_OPTION, $payload, false );

		$stored = get_option( self::PENDING_TOKEN_OPTION );
		$saved  = is_array( $stored ) && isset( $stored['token'] ) ? (string) $stored['token'] : '';

		return '' !== $saved && hash_equals( $token, $saved );
	}

	/**
	 * Accept the token once. A second import overwrites it, so stale events no-op.
	 *
	 * @param string $token Token from cron args or the loopback body.
	 * @return bool
	 * @since 2.7.2
	 */
	private static function consume_pending_token( string $token ): bool {
		$pending = get_option( self::PENDING_TOKEN_OPTION );
		$stored  = is_array( $pending ) && isset( $pending['token'] ) ? (string) $pending['token'] : '';
		$expires = is_array( $pending ) && isset( $pending['expires'] ) ? (int) $pending['expires'] : 0;

		if ( '' === $stored || '' === $token || ! hash_equals( $stored, $token ) ) {
			return false;
		}

		delete_option( self::PENDING_TOKEN_OPTION );

		if ( $expires > 0 && time() > $expires ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether the ProductUtil already loaded in this process matches WooCommerce 11+.
	 *
	 * Autoload is intentional: a fresh request must pull the class from disk.
	 * The import request must not call this method.
	 *
	 * @return bool
	 * @since 2.7.2
	 */
	private function loaded_product_util_can_delete_transients(): bool {
		if ( ! class_exists( self::PRODUCT_UTIL_CLASS, true ) ) {
			return false;
		}

		return method_exists( self::PRODUCT_UTIL_CLASS, 'delete_product_transients_for_products' );
	}

	/**
	 * Run one WooCommerce maintenance step without letting it fail the caller.
	 *
	 * @param callable $callback Step callback.
	 * @param string   $label    Log label.
	 * @return void
	 * @since 2.7.2
	 */
	private function run_woocommerce_step( callable $callback, string $label ): void {
		try {
			$callback();
		} catch ( \Throwable $error ) {
			PluginLogger::log(
				sprintf( 'WooCommerce post-import step %s failed: %s', $label, $error->getMessage() ),
				'PostImportMaintenance'
			);
		}
	}
}
