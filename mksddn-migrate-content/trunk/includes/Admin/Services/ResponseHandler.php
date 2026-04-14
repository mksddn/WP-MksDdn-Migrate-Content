<?php
/**
 * @file: ResponseHandler.php
 * @description: Service for handling HTTP responses and redirects
 * @dependencies: Config\PluginConfig, Admin\Services\NotificationService
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Config\PluginConfig;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service for handling HTTP responses and redirects.
 *
 * @since 1.0.0
 */
class ResponseHandler {

	/**
	 * Notification service.
	 *
	 * @var NotificationService
	 */
	private NotificationService $notifications;

	/**
	 * Constructor.
	 *
	 * @param NotificationService|null $notifications Notification service.
	 * @since 1.0.0
	 */
	public function __construct( ?NotificationService $notifications = null ) {
		$this->notifications = $notifications ?? new NotificationService();
	}

	/**
	 * Redirect to user preview page.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_to_user_preview( string $preview_id ): void {
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$nonce = wp_create_nonce( 'mksddn_mc_user_preview' );
		$url  = add_query_arg(
			array(
				'mksddn_mc_user_review' => $preview_id,
				'_wpnonce'              => $nonce,
			),
			$base
		);
		$this->notifications->safe_redirect_exit( $url );
	}

	/**
	 * Redirect to theme preview page.
	 *
	 * @param string $preview_id Preview identifier.
	 * @return void
	 * @since 2.1.0
	 */
	public function redirect_to_theme_preview( string $preview_id ): void {
		$base  = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$nonce = wp_create_nonce( 'mksddn_mc_theme_preview' );
		$url   = add_query_arg(
			array(
				'mksddn_mc_theme_review' => $preview_id,
				'_wpnonce'               => $nonce,
			),
			$base
		);

		$this->notifications->safe_redirect_exit( $url );
	}

	/**
	 * Redirect with full import status.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional error message.
	 * @return void
	 * @since 1.0.0
	 */
	public function redirect_with_status( string $status, ?string $message = null ): void {
		$this->notifications->redirect_full_status( $status, $message );
	}

	/**
	 * Redirect with theme import status.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional error message.
	 * @return void
	 * @since 2.1.0
	 */
	public function redirect_with_theme_status( string $status, ?string $message = null ): void {
		$this->notifications->redirect_with_theme_status( $status, $message );
	}

	/**
	 * Redirect to import page with preflight report id.
	 *
	 * @param string $report_id Preflight report id.
	 * @return void
	 * @since 2.2.0
	 */
	public function redirect_to_preflight_report( string $report_id ): void {
		$base = admin_url( 'admin.php?page=' . PluginConfig::text_domain() . '-import' );
		$url  = add_query_arg(
			array(
				'mksddn_mc_preflight' => rawurlencode( $report_id ),
				'_wpnonce'             => wp_create_nonce( 'mksddn_mc_preflight_' . $report_id ),
			),
			$base
		);
		$this->notifications->safe_redirect_exit( $url );
	}
}

