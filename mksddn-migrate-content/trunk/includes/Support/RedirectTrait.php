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

		// Build redirect URL.
		$redirect_url = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$redirect_url = add_query_arg(
			array(
				'mksddn_mc_import_status' => $history_id,
			),
			$redirect_url
		);

		// Set HTTP 302 Redirect headers.
		if ( ! headers_sent() ) {
			// Send standard HTTP 302 Found redirect.
			http_response_code( 302 );

			// Disable caching.
			nocache_headers();

			// Send redirect location.
			header( 'Location: ' . esc_url_raw( $redirect_url ) );

			// Explicitly set content length to 0 since we have no body.
			header( 'Content-Length: 0' );

			// Suggest browser to close connection.
			header( 'Connection: close' );
		}

		$this->log( 'Sending HTTP 302 redirect to: ' . esc_url_raw( $redirect_url ) );

		// Flush output to send headers immediately.
		if ( function_exists( 'flush' ) ) {
			flush();
		}

		$this->log( 'Redirect headers flushed, now closing connection' );

		// Close connection to browser for different server types.
		$this->close_client_connection();

		$this->log( 'Connection closed, import process continuing in background' );

		// DO NOT call exit() - import must continue after response sent to browser.
		// fastcgi_finish_request() already closed the browser connection.
	}

	/**
	 * Close client connection gracefully across different server types.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	protected function close_client_connection(): void {
		// Disable output buffering and compression.
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Set content length to prevent chunked transfer encoding.
		if ( ! headers_sent() && ob_get_length() === false ) {
			header( 'Content-Length: 0' );
		}

		// Try FastCGI first (PHP-FPM, Nginx).
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			$this->log( 'Closing client connection via fastcgi_finish_request()' );
			fastcgi_finish_request();
			return;
		}

		// For Apache + mod_php: use connection_aborted() check.
		if ( function_exists( 'connection_aborted' ) ) {
			// Send Connection: close header to signal client to close.
			if ( ! headers_sent() ) {
				header( 'Connection: close' );
			}
			// Wait a moment for output to flush.
			$this->log( 'Closing client connection via Connection: close header' );
			usleep( 100000 ); // 0.1 seconds.
		}

		// Last resort: ignore user abort to allow background execution.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$this->log( 'Client connection handling complete - import continues in background' );
	}

	/**
	 * Log message with plugin prefix.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.0.0
	 */
	protected function log( string $message ): void {
		error_log( 'MksDdn Migrate: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
