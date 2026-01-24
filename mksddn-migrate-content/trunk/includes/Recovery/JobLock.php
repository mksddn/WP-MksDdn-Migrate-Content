<?php
/**
 * Simple option-based job lock to avoid parallel runs.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Recovery;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides lock acquisition/release helpers.
 */
class JobLock {

	private const OPTION = 'mksddn_mc_job_lock';

	/**
	 * Acquire lock or return error if busy.
	 *
	 * @param string $context Context string.
	 * @param int    $ttl     Lock timeout seconds.
	 * @return string|WP_Error Lock ID or error.
	 */
	public function acquire( string $context, int $ttl = 300 ) {
		$this->release_if_expired();

		$current = get_option( self::OPTION );
		$now     = time();

		if ( isset( $current['expires_at'] ) && $current['expires_at'] > $now ) {
			// Check if lock appears to be stuck (no recent activity).
			$created_at = isset( $current['created_at'] ) ? ( is_numeric( $current['created_at'] ) ? (int) $current['created_at'] : strtotime( $current['created_at'] ) ) : $now;
			$last_update = isset( $current['last_update'] ) ? ( is_numeric( $current['last_update'] ) ? (int) $current['last_update'] : strtotime( $current['last_update'] ) ) : $created_at;
			$lock_age = $now - $created_at;
			$time_since_update = $now - $last_update;
			
			// If lock is older than 5 minutes and no updates in last 2 minutes, force release it.
			if ( $lock_age > 300 && $time_since_update > 120 ) {
				error_log( sprintf( 'MksDdn Migrate: Detected stuck lock (age: %d sec, last update: %d sec ago). Force releasing...', $lock_age, $time_since_update ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				delete_option( self::OPTION );
				$current = false; // Reset to allow acquisition.
			} else {
				$message = ! empty( $current['context'] )
					? sprintf(
						/* translators: %s is job context */
						__( 'Another %s job is running. Please wait until it finishes.', 'mksddn-migrate-content' ),
						sanitize_text_field( $current['context'] )
					)
					: __( 'Another migration job is running. Please wait.', 'mksddn-migrate-content' );
				return new WP_Error( 'mksddn_mc_lock_active', $message );
			}
		}

		$lock_id = wp_generate_uuid4();
		update_option(
			self::OPTION,
			array(
				'id'         => $lock_id,
				'context'    => sanitize_text_field( $context ),
				'created_at' => $now,
				'last_update' => $now,
				'expires_at' => $now + max( 60, $ttl ),
				'user_id'    => get_current_user_id(),
			),
			false
		);

		return $lock_id;
	}

	/**
	 * Release lock if caller still owns it.
	 *
	 * @param string $lock_id Lock identifier.
	 */
	public function release( string $lock_id ): void {
		$current = get_option( self::OPTION );
		if ( ! $current || ! is_array( $current ) ) {
			return;
		}

		if ( isset( $current['id'] ) && $current['id'] === $lock_id ) {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Update lock timestamp to prevent expiration during long operations.
	 *
	 * @param string $lock_id Lock identifier.
	 * @param int    $ttl     Additional TTL seconds.
	 * @return bool True if lock was updated, false otherwise.
	 */
	public function touch( string $lock_id, int $ttl = 300 ): bool {
		$current = get_option( self::OPTION );
		if ( ! $current || ! is_array( $current ) || ! isset( $current['id'] ) || $current['id'] !== $lock_id ) {
			return false;
		}

		$now = time();
		$current['last_update'] = $now;
		$current['expires_at'] = $now + max( 60, $ttl );
		update_option( self::OPTION, $current, false );
		return true;
	}

	/**
	 * Force unlock when TTL expired or lock appears stuck.
	 */
	private function release_if_expired(): void {
		$current = get_option( self::OPTION );
		if ( ! $current || ! is_array( $current ) ) {
			return;
		}

		$now = time();
		$should_release = false;
		$reason = '';

		// Release lock if expires_at is missing or has expired.
		if ( empty( $current['expires_at'] ) || $current['expires_at'] <= $now ) {
			$should_release = true;
			$reason = sprintf( 'expired (expires_at: %s, now: %s)', isset( $current['expires_at'] ) ? $current['expires_at'] : 'missing', $now );
		} else {
			// Also check if lock appears stuck (no updates for too long).
			$created_at = isset( $current['created_at'] ) ? ( is_numeric( $current['created_at'] ) ? (int) $current['created_at'] : strtotime( $current['created_at'] ) ) : $now;
			$last_update = isset( $current['last_update'] ) ? ( is_numeric( $current['last_update'] ) ? (int) $current['last_update'] : strtotime( $current['last_update'] ) ) : $created_at;
			$lock_age = $now - $created_at;
			$time_since_update = $now - $last_update;

			// If lock is older than 10 minutes and no updates in last 5 minutes, consider it stuck.
			if ( $lock_age > 600 && $time_since_update > 300 ) {
				$should_release = true;
				$reason = sprintf( 'stuck (age: %d sec, last update: %d sec ago)', $lock_age, $time_since_update );
			}
		}

		if ( $should_release ) {
			error_log( sprintf( 'MksDdn Migrate: Releasing lock - %s', $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			delete_option( self::OPTION );
		}
	}

	/**
	 * Force release lock (admin utility).
	 *
	 * @return bool True if lock was released, false if no lock existed.
	 */
	public function force_release(): bool {
		$current = get_option( self::OPTION );
		if ( ! $current || ! is_array( $current ) ) {
			return false;
		}

		delete_option( self::OPTION );
		return true;
	}
}


