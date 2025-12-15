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
	public function acquire( string $context, int $ttl = 900 ) {
		$this->release_if_expired();

		$current = get_option( self::OPTION );
		$now     = time();

		if ( isset( $current['expires_at'] ) && $current['expires_at'] > $now ) {
			$message = ! empty( $current['context'] )
				? sprintf(
					/* translators: %s is job context */
					__( 'Another %s job is running. Please wait until it finishes.', 'mksddn-migrate-content' ),
					sanitize_text_field( $current['context'] )
				)
				: __( 'Another migration job is running. Please wait.', 'mksddn-migrate-content' );
			return new WP_Error( 'mksddn_mc_lock_active', $message );
		}

		$lock_id = wp_generate_uuid4();
		update_option(
			self::OPTION,
			array(
				'id'         => $lock_id,
				'context'    => sanitize_text_field( $context ),
				'created_at' => gmdate( 'c' ),
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
	 * Force unlock when TTL expired.
	 */
	private function release_if_expired(): void {
		$current = get_option( self::OPTION );
		if ( ! $current || ! is_array( $current ) ) {
			return;
		}

		if ( empty( $current['expires_at'] ) || $current['expires_at'] > time() ) {
			return;
		}

		delete_option( self::OPTION );
	}
}


