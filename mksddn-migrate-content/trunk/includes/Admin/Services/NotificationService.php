<?php
/**
 * @file: NotificationService.php
 * @description: Service for displaying admin notices and handling redirects
 * @dependencies: PluginConfig
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Contracts\NotificationServiceInterface;
use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for managing admin notifications and redirects.
 *
 * @since 1.0.0
 */
class NotificationService implements NotificationServiceInterface {

	/**
	 * Show success notice.
	 *
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_success( string $message ): void {
		echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
		);
	}

	/**
	 * Show error notice.
	 *
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_error( string $message ): void {
		echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
		);
	}

	/**
	 * Show inline notice.
	 *
	 * @param string $type    Notice type: error|success|warning|info.
	 * @param string $message Message text.
	 * @return void
	 * @since 1.0.0
	 */
	public function show_inline_notice( string $type, string $message ): void {
		$map = array(
			'error'   => 'notice notice-error',
			'success' => 'notice notice-success',
			'warning' => 'notice notice-warning',
			'info'    => 'notice notice-info',
		);

		$class = $map[ $type ] ?? $map['info'];
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * Redirect with status notice.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional message.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_with_notice( string $status, ?string $message = null ): void {
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$args = array(
			'mksddn_mc_notice' => $status,
		);

		if ( $message ) {
			$args['mksddn_mc_notice_message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/**
	 * Redirect with full status.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional error message.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_full_status( string $status, ?string $message = null ): void {
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() );
		$url  = add_query_arg(
			array(
				'mksddn_mc_full_status' => $status,
				'mksddn_mc_full_error'  => $message,
			),
			$base
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render status notices from query parameters.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_status_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag after redirect.
		if ( ! empty( $_GET['mksddn_mc_full_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['mksddn_mc_full_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( 'success' === $status ) {
				$this->show_success( __( 'Full site operation completed successfully.', 'mksddn-migrate-content' ) );
			} elseif ( 'error' === $status && ! empty( $_GET['mksddn_mc_full_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect
				$this->show_error( sanitize_text_field( wp_unslash( $_GET['mksddn_mc_full_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag after redirect.
		if ( empty( $_GET['mksddn_mc_notice'] ) ) {
			return;
		}

		$notice_status = sanitize_key( wp_unslash( $_GET['mksddn_mc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message       = isset( $_GET['mksddn_mc_notice_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mksddn_mc_notice_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $notice_status ) {
			$this->show_success( $message ?: __( 'Operation completed successfully.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( 'error' === $notice_status ) {
			$this->show_error( $message ?: __( 'Operation failed. Check logs for details.', 'mksddn-migrate-content' ) );
		}
	}
}

