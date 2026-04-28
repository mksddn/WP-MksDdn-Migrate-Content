<?php
/**
 * @file: FullImportMaintenance.php
 * @description: Short-lived full-site import flag and public maintenance (503) gate.
 * @dependencies: None
 * @created: 2026-04-28
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks an in-progress full database import to avoid serving a half-updated site on the front end.
 */
class FullImportMaintenance {

	/**
	 * Runtime lock filename while full import is running.
	 */
	private const LOCK_FILENAME = 'full-import.lock';

	/**
	 * Long TTL so large imports do not drop the flag mid-run (cleared explicitly on completion).
	 */
	private const TTL_SECONDS = 14400;

	/**
	 * Mark full import as active.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$path = self::lock_path();
		$dir  = dirname( $path );

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( is_dir( $dir ) && wp_is_writable( $dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Small runtime lock outside the database.
			file_put_contents( $path, (string) time(), LOCK_EX );
		}

		self::activate_core_maintenance();
	}

	/**
	 * Clear full import flag.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$path = self::lock_path();
		if ( is_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Small runtime lock outside the database.
			unlink( $path );
		}

		self::deactivate_core_maintenance();
	}

	/**
	 * Whether a full import is marked active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		$path = self::lock_path();
		if ( ! is_file( $path ) ) {
			return false;
		}

		$modified = filemtime( $path );
		if ( false !== $modified && time() - $modified > self::TTL_SECONDS ) {
			self::deactivate();
			return false;
		}

		return true;
	}

	/**
	 * Send 503 for unauthenticated public requests while import runs.
	 *
	 * @return void
	 */
	public static function maybe_block_public_requests(): void {
		if ( ! self::is_active() ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return;
		}

		if ( function_exists( 'wp_installing' ) && wp_installing() ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		/**
		 * Allow bypassing maintenance gate (monitoring, custom routes).
		 *
		 * @since 2.2.2
		 *
		 * @param bool $allow Whether to allow the request through.
		 */
		if ( apply_filters( 'mksddn_mc_full_import_allow_request', self::is_allowed_rest_request() ) ) {
			return;
		}

		nocache_headers();

		wp_die(
			esc_html__( 'Site maintenance: database import in progress. Please try again shortly.', 'mksddn-migrate-content' ),
			esc_html__( 'Service Unavailable', 'mksddn-migrate-content' ),
			array( 'response' => 503 )
		);
	}

	/**
	 * Runtime lock path outside wp_options so DB replacement cannot remove it.
	 *
	 * @return string
	 */
	private static function lock_path(): string {
		$base = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
		$path = trailingslashit( $base ) . 'mksddn-mc/runtime/' . self::LOCK_FILENAME;

		/**
		 * Filters the full import runtime lock path.
		 *
		 * @since 2.2.2
		 *
		 * @param string $path Absolute lock path.
		 */
		return (string) apply_filters( 'mksddn_mc_full_import_lock_path', $path );
	}

	/**
	 * Create WordPress core maintenance file so parallel requests are blocked before plugins load.
	 *
	 * @return void
	 */
	private static function activate_core_maintenance(): void {
		$path = self::core_maintenance_path();
		$ttl  = time() + self::TTL_SECONDS;
		$code = "<?php\n/* mksddn-mc-full-import */\n\$upgrading = " . $ttl . ';';

		if ( is_file( $path ) && ! self::is_own_core_maintenance_file( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WordPress core maintenance file.
		file_put_contents( $path, $code, LOCK_EX );
	}

	/**
	 * Remove WordPress core maintenance file if this importer created it.
	 *
	 * @return void
	 */
	private static function deactivate_core_maintenance(): void {
		$path = self::core_maintenance_path();
		if ( is_file( $path ) && self::is_own_core_maintenance_file( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- WordPress core maintenance file.
			unlink( $path );
		}
	}

	/**
	 * Absolute path to WordPress core maintenance file.
	 *
	 * @return string
	 */
	private static function core_maintenance_path(): string {
		$base = defined( 'ABSPATH' ) ? ABSPATH : dirname( WP_CONTENT_DIR ) . '/';
		return trailingslashit( $base ) . '.maintenance';
	}

	/**
	 * Whether the core maintenance file belongs to this importer.
	 *
	 * @param string $path Maintenance file path.
	 * @return bool
	 */
	private static function is_own_core_maintenance_file( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Tiny marker file.
		$contents = (string) file_get_contents( $path );
		return false !== strpos( $contents, 'mksddn-mc-full-import' );
	}

	/**
	 * Allow only explicit health-check REST requests while full import is running.
	 *
	 * @return bool
	 */
	private static function is_allowed_rest_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$allowed     = array(
			'/wp-json/',
			'/wp-json',
		);

		foreach ( $allowed as $path ) {
			if ( $path === $request_uri ) {
				return true;
			}
		}

		return false;
	}
}
