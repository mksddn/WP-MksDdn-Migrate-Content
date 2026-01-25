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
	private string $key = 'mksddn_mc_import_lock';

	/**
	 * Acquire lock.
	 *
	 * @param int $ttl Lock time-to-live in seconds.
	 * @return string|false Lock token or false if already locked.
	 * @since 1.0.0
	 */
	public function acquire( int $ttl = 1800 ): string|false {
		$current = get_transient( $this->key );
		if ( is_array( $current ) && ! empty( $current['token'] ) ) {
			return false;
		}

		$token = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mksddn_mc_', true );
		set_transient( $this->key, array( 'token' => $token ), $ttl );

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
		$current = get_transient( $this->key );
		if ( ! is_array( $current ) ) {
			return;
		}

		if ( (string) ( $current['token'] ?? '' ) !== $token ) {
			return;
		}

		delete_transient( $this->key );
	}
}
