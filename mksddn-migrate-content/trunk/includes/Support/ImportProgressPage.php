<?php
/**
 * @file: ImportProgressPage.php
 * @description: Sends progress page to browser for long-running import operations
 * @dependencies: Config/PluginConfig
 * @created: 2024-12-17
 */

namespace MksDdn\MigrateContent\Support;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and sends an import progress page to the browser.
 * Allows the server to continue processing in the background.
 *
 * @since 1.0.0
 */
class ImportProgressPage {

	/**
	 * Send HTML response to browser immediately to prevent timeout.
	 * Browser will poll for completion status via REST API.
	 *
	 * @param string $history_id History entry ID for status polling.
	 * @return void
	 * @since 1.0.0
	 */
	public static function send( string $history_id ): void {
		self::log( sprintf( 'Sending response to browser (history_id: %s)', $history_id ) );

		// Clear any existing output buffers.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		// Send headers first.
		if ( ! headers_sent() ) {
			nocache_headers();
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Content-Length: 0' ); // Will be updated after content.
		}

		$redirect_url = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$redirect_url = add_query_arg(
			array(
				'mksddn_mc_import_status' => $history_id,
			),
			$redirect_url
		);

		$html = self::build_html( $history_id, $redirect_url );

		// Update Content-Length header.
		if ( ! headers_sent() ) {
			header( 'Content-Length: ' . strlen( $html ) );
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML is safe, contains only escaped strings.

		// Flush all output buffers.
		while ( ob_get_level() > 0 ) {
			ob_end_flush();
		}

		// Force flush to send data immediately.
		if ( function_exists( 'flush' ) ) {
			flush();
		}

		// Finish request if FastCGI is available (allows script to continue).
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
			self::log( 'fastcgi_finish_request() called, connection closed to browser' );
		} else {
			self::log( 'fastcgi_finish_request() not available, using flush()' );
		}
	}

	/**
	 * Build progress page HTML.
	 *
	 * @param string $history_id   History entry ID.
	 * @param string $redirect_url URL to redirect after completion.
	 * @return string HTML content.
	 * @since 1.0.0
	 */
	private static function build_html( string $history_id, string $redirect_url ): string {
		$rest_url = rest_url( 'mksddn/v1/import/status' );
		$nonce    = wp_create_nonce( 'wp_rest' );

		// Inline CSS required for standalone progress page.
		// This is NOT a WordPress admin page - it bypasses WP template system.
		// External CSS cannot be used: server is busy with import in same PHP process.
		// wp_enqueue_style is not applicable here as this is a direct HTTP response.
		$css = 'body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:40px;max-width:500px;margin:0 auto}';
		$css .= '.progress-bar{width:100%;height:16px;background:#f0f0f0;border-radius:999px;overflow:hidden;margin:20px 0}';
		$css .= '.progress-bar span{display:block;height:100%;width:0%;background:#2c7be5;transition:width .3s ease}';
		$css .= '.progress-label{color:#444;font-size:14px}h2{margin:0 0 10px}';

		$html  = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>';
		$html .= esc_html__( 'Import in progress', 'mksddn-migrate-content' );
		$html .= '</title>';
		$html .= '<style>' . $css . '</style>';
		$html .= '</head><body>';
		$html .= '<h2>' . esc_html__( 'Import in progress', 'mksddn-migrate-content' ) . '</h2>';
		$html .= '<div class="progress-bar"><span id="bar"></span></div>';
		$html .= '<p class="progress-label" id="label">' . esc_html__( 'Starting...', 'mksddn-migrate-content' ) . '</p>';
		$html .= '<script>';
		$html .= '(function(){';
		$html .= 'var bar=document.getElementById("bar"),label=document.getElementById("label");';
		$html .= 'var url=' . wp_json_encode( $rest_url . '?history_id=' . $history_id ) . ';';
		$html .= 'var redirect=' . wp_json_encode( $redirect_url ) . ';';
		$html .= 'var nonce=' . wp_json_encode( $nonce ) . ';';
		$html .= 'var errors=0,maxErrors=30;';
		$html .= 'function poll(){';
		$html .= 'fetch(url,{headers:{"X-WP-Nonce":nonce}}).then(function(r){';
		$html .= 'if(!r.ok){errors++;if(errors>maxErrors){bar.style.width="100%";label.textContent="Redirecting...";setTimeout(function(){location.href=redirect},500);return}setTimeout(poll,2000);return Promise.reject();}';
		$html .= 'return r.json();';
		$html .= '}).then(function(d){';
		$html .= 'if(!d)return;errors=0;';
		$html .= 'var p=d.progress||{};bar.style.width=(p.percent||0)+"%";label.textContent=p.message||"Processing...";';
		$html .= 'if(d.status==="running"){setTimeout(poll,1000)}else{bar.style.width="100%";label.textContent="Complete!";setTimeout(function(){location.href=redirect},1000)}';
		$html .= '}).catch(function(){if(errors<maxErrors){errors++;setTimeout(poll,2000)}else{bar.style.width="100%";label.textContent="Redirecting...";setTimeout(function(){location.href=redirect},500)}});';
		$html .= '}poll();';
		$html .= '})();';
		$html .= '</script>';
		$html .= '</body></html>';

		return $html;
	}

	/**
	 * Log message if WP_DEBUG is enabled.
	 *
	 * @param string $message Message to log.
	 * @return void
	 * @since 1.0.0
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug logging.
			error_log( 'MksDdn Migrate: ' . $message );
		}
	}
}

