<?php
/**
 * @file: ImportLock.php
 * @description: Simple transient-based lock for import operations
 * @dependencies: None
 * @created: 2026-01-25
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simple transient-based lock for import operations.
 *
 * @since 1.0.0
 */
class ImportLock {

	/**
	 * Lock transient key.
	 *
	 * @var string
	 */
	private const LOCK_KEY = 'mksddn_mc_import_lock';

	/**
	 * Maximum lock age before considering it stale (in seconds).
	 *
	 * @var int
	 */
	private const MAX_LOCK_AGE = 3600; // 1 hour

	/**
	 * Default lock time-to-live (in seconds).
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = 1800; // 30 minutes

	/**
	 * Acquire lock.
	 *
	 * @param int $ttl Lock time-to-live in seconds.
	 * @return string|false Lock token or false if already locked.
	 * @since 1.0.0
	 */
	public function acquire( int $ttl = self::DEFAULT_TTL ): string|false {
		$current = get_transient( self::LOCK_KEY );
		
		// Check if lock exists and is still valid.
		if ( is_array( $current ) && ! empty( $current['token'] ) ) {
			// Check if lock is stale (older than max_lock_age).
			$lock_time = isset( $current['created_at'] ) ? (int) $current['created_at'] : 0;
			$age = time() - $lock_time;
			
			if ( $lock_time > 0 && $age > self::MAX_LOCK_AGE ) {
				// Lock is stale, log and clear it.
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( 'MksDdn Migrate: Clearing stale import lock (age: %d seconds)', $age ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				delete_transient( self::LOCK_KEY );
			} else {
				// Lock is still valid.
				return false;
			}
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mksddn_mc_', true );
		set_transient( 
			self::LOCK_KEY, 
			array( 
				'token'     => $token,
				'created_at' => time(),
			), 
			$ttl 
		);

		return $token;
	}

	/**
	 * Release lock.
	 *
	 * @param string $token Lock token.
	 * @return void
	 * @since 1.0.0
	 */
	public function release( string $token ): void {
		$current = get_transient( self::LOCK_KEY );
		if ( ! is_array( $current ) ) {
			return;
		}

		if ( (string) ( $current['token'] ?? '' ) !== $token ) {
			return;
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Force release lock (admin only).
	 *
	 * Note: This method checks capabilities, but it's expected to be called
	 * from contexts where capabilities are already verified (e.g., AdminPageController).
	 * This serves as an additional safety check.
	 *
	 * @return bool True if lock was released, false otherwise.
	 * @since 1.0.0
	 */
	public function force_release(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		$current = get_transient( self::LOCK_KEY );
		if ( ! is_array( $current ) || empty( $current['token'] ) ) {
			return false;
		}

		delete_transient( self::LOCK_KEY );
		return true;
	}

	/**
	 * Check if lock exists.
	 *
	 * @return bool True if lock exists, false otherwise.
	 * @since 1.0.0
	 */
	public function is_locked(): bool {
		$current = get_transient( self::LOCK_KEY );
		if ( ! is_array( $current ) || empty( $current['token'] ) ) {
			return false;
		}

		// Check if lock is stale.
		$lock_time = isset( $current['created_at'] ) ? (int) $current['created_at'] : 0;
		$age = time() - $lock_time;
		
		if ( $lock_time > 0 && $age > self::MAX_LOCK_AGE ) {
			// Lock is stale, clear it.
			delete_transient( self::LOCK_KEY );
			return false;
		}

		return true;
	}

	/**
	 * Get lock information.
	 *
	 * @return array|null Lock data or null if not locked.
	 * @since 1.0.0
	 */
	public function get_info(): ?array {
		$current = get_transient( self::LOCK_KEY );
		if ( ! is_array( $current ) || empty( $current['token'] ) ) {
			return null;
		}

		$lock_time = isset( $current['created_at'] ) ? (int) $current['created_at'] : 0;
		$age = $lock_time > 0 ? time() - $lock_time : 0;

		return array(
			'token'     => $current['token'] ?? '',
			'created_at' => $lock_time,
			'age'       => $age,
			'is_stale'  => $age > self::MAX_LOCK_AGE,
		);
	}
}
