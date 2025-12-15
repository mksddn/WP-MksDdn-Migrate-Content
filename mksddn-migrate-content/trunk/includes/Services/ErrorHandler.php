<?php
/**
 * @file: ErrorHandler.php
 * @description: Centralized error handling and logging service
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Services;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Centralized error handling service.
 *
 * @since 1.0.0
 */
class ErrorHandler {

	/**
	 * Log error message.
	 *
	 * @param string|WP_Error $error Error message or WP_Error object.
	 * @param string          $context Context where error occurred.
	 * @return void
	 * @since 1.0.0
	 */
	public function log( string|WP_Error $error, string $context = '' ): void {
		$message = is_wp_error( $error ) ? $error->get_error_message() : $error;

		if ( $context ) {
			$message = sprintf( '[%s] %s', $context, $message );
		}

		if ( function_exists( 'error_log' ) ) {
			error_log( sprintf( 'MksDdn Migrate Content: %s', $message ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			trigger_error( $message, E_USER_WARNING );
		}
	}

	/**
	 * Convert WP_Error to user-friendly message.
	 *
	 * @param WP_Error $error Error object.
	 * @return string User-friendly message.
	 * @since 1.0.0
	 */
	public function get_user_message( WP_Error $error ): string {
		$code    = $error->get_error_code();
		$message = $error->get_error_message();

		$user_messages = array(
			'mksddn_mc_invalid_type'     => __( 'Invalid file type. Please upload a valid export file.', 'mksddn-migrate-content' ),
			'mksddn_mc_file_missing'      => __( 'No file uploaded.', 'mksddn-migrate-content' ),
			'mksddn_mc_invalid_size'      => __( 'Invalid file size.', 'mksddn-migrate-content' ),
			'mksddn_mc_chunk_disabled'    => __( 'Chunked uploads are disabled on this site.', 'mksddn-migrate-content' ),
			'mksddn_mc_chunk_missing'     => __( 'Chunked upload is incomplete. Please retry.', 'mksddn-migrate-content' ),
			'mksddn_mc_temp_unavailable'  => __( 'Unable to allocate a temporary file.', 'mksddn-migrate-content' ),
			'mksddn_mc_move_failed'       => __( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ),
			'mksddn_snapshot_missing'     => __( 'Snapshot archive is missing on disk.', 'mksddn-migrate-content' ),
		);

		if ( isset( $user_messages[ $code ] ) ) {
			return $user_messages[ $code ];
		}

		return $message ?: __( 'An unknown error occurred.', 'mksddn-migrate-content' );
	}

	/**
	 * Handle error and return formatted message.
	 *
	 * @param string|WP_Error $error Error message or WP_Error object.
	 * @param string          $context Context where error occurred.
	 * @return string Formatted error message.
	 * @since 1.0.0
	 */
	public function handle( string|WP_Error $error, string $context = '' ): string {
		$this->log( $error, $context );

		if ( is_wp_error( $error ) ) {
			return $this->get_user_message( $error );
		}

		return (string) $error;
	}
}

