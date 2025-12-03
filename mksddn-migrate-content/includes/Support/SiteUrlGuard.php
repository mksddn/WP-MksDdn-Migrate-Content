<?php
/**
 * Guards site/home URL options during full imports.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures current site/home URLs and can restore them later.
 */
class SiteUrlGuard {

	private string $siteurl;

	private string $home;

	private string $request_url;

	public function __construct( ?string $siteurl = null, ?string $home = null ) {
		$this->siteurl     = $siteurl ?? (string) get_option( 'siteurl' );
		$this->home        = $home ?? (string) get_option( 'home' );
		$this->request_url = $this->current_host_url();
	}

	/**
	 * Restore captured URLs, preferring current host.
	 */
	public function restore(): void {
		$this->apply_value( 'siteurl', $this->siteurl );
		$this->apply_value( 'home', $this->home );
		$this->maybe_cleanup_cache();

		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
	}

	private function apply_value( string $option, string $original ): void {
		$target = $this->request_url;

		if ( '' === $target ) {
			$target = $original;
		} elseif ( '' !== $original ) {
			$path = parse_url( $original, PHP_URL_PATH );
			if ( $path ) {
				$target = rtrim( $this->request_url, '/' ) . '/' . ltrim( $path, '/' );
			}
		}

		/**
		 * Filter the value stored by the site URL guard.
		 *
		 * @param string $target   Computed target URL.
		 * @param string $option   Option name.
		 * @param string $original Original option value.
		 */
		$target = apply_filters( 'mksddn_mc_siteurl_guard_value', $target, $option, $original );

		if ( '' === $target ) {
			return;
		}

		update_option( $option, esc_url_raw( $target ) );
	}

	private function current_host_url(): string {
		if ( empty( $_SERVER['HTTP_HOST'] ) ) {
			return '';
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$host   = wp_unslash( $_SERVER['HTTP_HOST'] );

		$reference = $this->home ?: site_url();
		$path      = parse_url( $reference, PHP_URL_PATH );
		$path      = $path ? '/' . trim( $path, '/' ) : '';

		return rtrim( sprintf( '%s://%s%s', $scheme, $host, $path ), '/' );
	}

	private function maybe_cleanup_cache(): void {
		delete_option( 'rewrite_rules' );
	}
}


