<?php
/**
 * @file: RedirectTrait.php
 * @description: Trait providing redirect and connection closing functionality for background imports
 * @dependencies: Config\PluginConfig
 * @created: 2026-01-24
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait providing redirect and connection closing functionality.
 *
 * @since 1.0.0
 */
trait RedirectTrait {

	/**
	 * Redirect to admin page with import progress indicator.
	 *
	 * Sends redirect to browser then continues execution in background.
	 * Uses fastcgi_finish_request() if available to close connection while
	 * allowing PHP to continue processing the import.
	 *
	 * @param string $history_id History entry ID for status polling.
	 * @return void
	 * @since 1.0.0
	 */
	protected function redirect_to_import_progress( string $history_id ): void {
		// Ensure all output buffers are cleared.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$redirect_url = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$redirect_url = add_query_arg(
			array(
				'mksddn_mc_import_status' => $history_id,
			),
			$redirect_url
		);

		if ( ! headers_sent() ) {
			nocache_headers();
			wp_safe_redirect( $redirect_url );
		}

		if ( function_exists( 'flush' ) ) {
			flush();
		}

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		// DO NOT call exit() - import must continue after response sent to browser.
	}

	/**
	 * Log message with plugin prefix.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.0.0
	 */
	protected function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging.
			error_log( 'MksDdn Migrate: ' . $message );
		}
	}
}
