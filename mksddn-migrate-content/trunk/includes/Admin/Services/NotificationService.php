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
		
		// Only add inline script if not in admin-post.php context.
		$is_admin_post = defined( 'DOING_ADMIN_POST' ) || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin-post.php' ) !== false );
		if ( ! $is_admin_post ) {
			wp_add_inline_script(
				'mksddn-mc-admin-scripts',
				'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
			);
		}
	}

	/**
	 * Show success notice with action links.
	 *
	 * @param string      $message      Message text.
	 * @param string      $import_type  Import type: 'full' or 'selected'.
	 * @param string|null $content_type Content type (for selected imports).
	 * @param string|null $slug         Content slug (for selected imports).
	 * @param string|null $title        Content title (for selected imports).
	 * @param string|null $post_type    Post type (for selected imports).
	 * @return void
	 * @since 1.0.0
	 */
	public function show_success_with_actions( string $message, string $import_type, ?string $content_type = null, ?string $slug = null, ?string $title = null, ?string $post_type = null ): void {
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong></p></div>',
			esc_html( $message )
		);

		// Only add inline script if not in admin-post.php context.
		$is_admin_post = defined( 'DOING_ADMIN_POST' ) || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin-post.php' ) !== false );
		if ( ! $is_admin_post ) {
			wp_add_inline_script(
				'mksddn-mc-admin-scripts',
				'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
			);
		}
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
		
		// Only add inline script if not in admin-post.php context.
		$is_admin_post = defined( 'DOING_ADMIN_POST' ) || ( isset( $_SERVER['REQUEST_URI'] ) && strpos( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 'admin-post.php' ) !== false );
		if ( ! $is_admin_post ) {
			wp_add_inline_script(
				'mksddn-mc-admin-scripts',
				'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
			);
		}
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
		// Clear any output buffers before redirect.
		// Clear all levels of output buffering.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Prevent any output.
		if ( ! headers_sent() ) {
			$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
			$args = array(
				'mksddn_mc_notice' => $status,
			);

			if ( $message ) {
				$args['mksddn_mc_notice_message'] = $message;
			}

			wp_safe_redirect( add_query_arg( $args, $base ) );
			exit;
		}

		// Fallback if headers already sent - use JavaScript redirect.
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$args = array(
			'mksddn_mc_notice' => $status,
		);

		if ( $message ) {
			$args['mksddn_mc_notice_message'] = $message;
		}

		$url = add_query_arg( $args, $base );
		printf( '<script>window.location.href = %s;</script>', wp_json_encode( esc_url_raw( $url ) ) );
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
		// Clear any output buffers before redirect.
		// Clear all levels of output buffering.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Prevent any output.
		if ( ! headers_sent() ) {
			$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
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

		// Fallback if headers already sent - use JavaScript redirect.
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$url  = add_query_arg(
			array(
				'mksddn_mc_full_status' => $status,
				'mksddn_mc_full_error'  => $message,
			),
			$base
		);

		printf( '<script>window.location.href = %s;</script>', wp_json_encode( esc_url_raw( $url ) ) );
		exit;
	}

	/**
	 * Redirect with selected import success details.
	 *
	 * @param string $type     Import type (page, post, bundle).
	 * @param string $slug     Imported content slug.
	 * @param string $title    Imported content title.
	 * @param string $post_type Post type.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_with_selected_import_success( string $type, string $slug, string $title, string $post_type = 'page' ): void {
		// Clear any output buffers before redirect.
		// Clear all levels of output buffering.
		while ( ob_get_level() > 0 ) {
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		// Prevent any output.
		if ( ! headers_sent() ) {
			$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
			$url  = add_query_arg(
				array(
					'mksddn_mc_notice'           => 'success',
					'mksddn_mc_import_type'      => sanitize_key( $type ),
					'mksddn_mc_import_slug'      => sanitize_text_field( $slug ),
					'mksddn_mc_import_title'     => sanitize_text_field( $title ),
					'mksddn_mc_import_post_type' => sanitize_key( $post_type ),
				),
				$base
			);

			wp_safe_redirect( $url );
			exit;
		}

		// Fallback if headers already sent - use JavaScript redirect.
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$url  = add_query_arg(
			array(
				'mksddn_mc_notice'           => 'success',
				'mksddn_mc_import_type'      => sanitize_key( $type ),
				'mksddn_mc_import_slug'      => sanitize_text_field( $slug ),
				'mksddn_mc_import_title'     => sanitize_text_field( $title ),
				'mksddn_mc_import_post_type' => sanitize_key( $post_type ),
			),
			$base
		);

		printf( '<script>window.location.href = %s;</script>', wp_json_encode( esc_url_raw( $url ) ) );
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
				$this->show_success_with_actions( __( 'Full site import completed successfully!', 'mksddn-migrate-content' ), 'full' );
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
			// Check if we have import details for selected content.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect.
			$import_type = isset( $_GET['mksddn_mc_import_type'] ) ? sanitize_key( wp_unslash( $_GET['mksddn_mc_import_type'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect.
			$import_slug = isset( $_GET['mksddn_mc_import_slug'] ) ? sanitize_text_field( wp_unslash( $_GET['mksddn_mc_import_slug'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect.
			$import_title = isset( $_GET['mksddn_mc_import_title'] ) ? sanitize_text_field( wp_unslash( $_GET['mksddn_mc_import_title'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect.
			$import_post_type = isset( $_GET['mksddn_mc_import_post_type'] ) ? sanitize_key( wp_unslash( $_GET['mksddn_mc_import_post_type'] ) ) : 'page';

			if ( $import_type && $import_slug ) {
				$display_message = $message ?: sprintf(
					// translators: %s is imported item type.
					__( '%s imported successfully!', 'mksddn-migrate-content' ),
					ucfirst( $import_type )
				);
				$this->show_success_with_actions( $display_message, 'selected', $import_type, $import_slug, $import_title, $import_post_type );
			} else {
				$this->show_success( $message ?: __( 'Operation completed successfully.', 'mksddn-migrate-content' ) );
			}
			return;
		}

		if ( 'error' === $notice_status ) {
			$this->show_error( $message ?: __( 'Operation failed. Check logs for details.', 'mksddn-migrate-content' ) );
		}
	}

}

