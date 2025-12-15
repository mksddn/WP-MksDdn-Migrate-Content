<?php
/**
 * @file: ErrorHandler.php
 * @description: Centralized error handling and logging service
 * @dependencies: Exceptions namespace
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Services;

use MksDdn\MigrateContent\Exceptions\DatabaseOperationException;
use MksDdn\MigrateContent\Exceptions\ExportException;
use MksDdn\MigrateContent\Exceptions\FileOperationException;
use MksDdn\MigrateContent\Exceptions\ImportException;
use MksDdn\MigrateContent\Exceptions\ValidationException;
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
	 * @param string|WP_Error|\Throwable $error Error message, WP_Error object, or exception.
	 * @param string                     $context Context where error occurred.
	 * @return void
	 * @since 1.0.0
	 */
	public function log( string|WP_Error|\Throwable $error, string $context = '' ): void {
		$message = $this->extract_message( $error );

		if ( $context ) {
			$message = sprintf( '[%s] %s', $context, $message );
		}

		// Add additional context for exceptions.
		if ( $error instanceof \Throwable ) {
			$additional = $this->get_exception_context( $error );
			if ( $additional ) {
				$message .= ' ' . $additional;
			}
		}

		if ( function_exists( 'error_log' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Error logging is intentional for production debugging.
			error_log( sprintf( 'MksDdn Migrate Content: %s', $message ) );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug output only, message is already sanitized.
			trigger_error( $message, E_USER_WARNING );
		}
	}

	/**
	 * Extract message from error.
	 *
	 * @param string|WP_Error|\Throwable $error Error object.
	 * @return string Error message.
	 * @since 1.0.0
	 */
	private function extract_message( string|WP_Error|\Throwable $error ): string {
		if ( is_wp_error( $error ) ) {
			return $error->get_error_message();
		}

		if ( $error instanceof \Throwable ) {
			return $error->getMessage();
		}

		return (string) $error;
	}

	/**
	 * Get additional context from exception.
	 *
	 * @param \Throwable $exception Exception object.
	 * @return string Additional context.
	 * @since 1.0.0
	 */
	private function get_exception_context( \Throwable $exception ): string {
		$context_parts = array();

		if ( $exception instanceof FileOperationException ) {
			$file_path = $exception->get_file_path();
			if ( $file_path ) {
				$context_parts[] = sprintf( 'File: %s', $file_path );
			}
		}

		if ( $exception instanceof DatabaseOperationException ) {
			$query = $exception->get_query();
			if ( $query ) {
				// Truncate long queries for logging.
				$query_preview = strlen( $query ) > 200 ? substr( $query, 0, 200 ) . '...' : $query;
				$context_parts[] = sprintf( 'Query: %s', $query_preview );
			}
		}

		if ( $exception instanceof ValidationException && $exception->has_errors() ) {
			$errors = $exception->get_errors();
			$context_parts[] = sprintf( 'Validation errors: %s', count( $errors ) );
		}

		return implode( ', ', $context_parts );
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
	 * Convert exception to user-friendly message.
	 *
	 * @param \Throwable $exception Exception object.
	 * @return string User-friendly message.
	 * @since 1.0.0
	 */
	public function get_exception_message( \Throwable $exception ): string {
		$user_messages = array(
			ValidationException::class         => __( 'Validation failed. Please check your input.', 'mksddn-migrate-content' ),
			FileOperationException::class       => __( 'File operation failed. Please check file permissions.', 'mksddn-migrate-content' ),
			DatabaseOperationException::class   => __( 'Database operation failed. Please try again.', 'mksddn-migrate-content' ),
			ImportException::class              => __( 'Import operation failed. Please check the import file.', 'mksddn-migrate-content' ),
			ExportException::class             => __( 'Export operation failed. Please try again.', 'mksddn-migrate-content' ),
		);

		$exception_class = get_class( $exception );
		if ( isset( $user_messages[ $exception_class ] ) ) {
			$base_message = $user_messages[ $exception_class ];
			$detail       = $exception->getMessage();
			if ( $detail && $detail !== $base_message ) {
				return sprintf( '%s %s', $base_message, $detail );
			}
			return $base_message;
		}

		return $exception->getMessage() ?: __( 'An unexpected error occurred.', 'mksddn-migrate-content' );
	}

	/**
	 * Handle error and return formatted message.
	 *
	 * @param string|WP_Error|\Throwable $error Error message, WP_Error object, or exception.
	 * @param string                     $context Context where error occurred.
	 * @return string Formatted error message.
	 * @since 1.0.0
	 */
	public function handle( string|WP_Error|\Throwable $error, string $context = '' ): string {
		$this->log( $error, $context );

		if ( is_wp_error( $error ) ) {
			return $this->get_user_message( $error );
		}

		if ( $error instanceof \Throwable ) {
			return $this->get_exception_message( $error );
		}

		return (string) $error;
	}

	/**
	 * Handle exception and optionally terminate execution.
	 *
	 * @param \Throwable $exception Exception to handle.
	 * @param string     $context   Context where error occurred.
	 * @param bool       $terminate Whether to terminate execution (wp_die).
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_exception( \Throwable $exception, string $context = '', bool $terminate = false ): void {
		$message = $this->handle( $exception, $context );

		if ( $terminate ) {
			wp_die( esc_html( $message ) );
		}
	}
}

