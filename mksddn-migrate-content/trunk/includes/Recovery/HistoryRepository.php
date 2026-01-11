<?php
/**
 * @file: HistoryRepository.php
 * @description: Stores lightweight job history in options table
 * @dependencies: HistoryRepositoryInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Recovery;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists history entries for exports/imports/rollbacks.
 *
 * @since 1.0.0
 */
class HistoryRepository implements HistoryRepositoryInterface {

	private const OPTION = 'mksddn_mc_history';
	private const LIMIT  = 50;
	private const CACHE_KEY = 'mksddn_mc_history_cache';
	private const CACHE_EXPIRY = 300; // 5 minutes

	/**
	 * Cached entries.
	 *
	 * @var array|null
	 */
	private ?array $cached_entries = null;

	/**
	 * Start new entry.
	 *
	 * @param string                $type    Entry type (e.g., 'export', 'import', 'rollback').
	 * @param array<string, mixed>  $context Additional meta data.
	 * @return string Entry ID (UUID).
	 * @since 1.0.0
	 */
	public function start( string $type, array $context = array() ): string {
		$id     = wp_generate_uuid4();
		$entry  = array(
			'id'          => $id,
			'type'        => sanitize_key( $type ),
			'status'      => 'running',
			'started_at'  => gmdate( 'c' ),
			'finished_at' => null,
			'user_id'     => get_current_user_id(),
			'context'     => $this->sanitize_context( $context ),
		);
		$entries = $this->load();

		array_unshift( $entries, $entry );
		$entries = array_slice( $entries, 0, self::LIMIT );
		$this->persist( $entries );

		return $id;
	}

	/**
	 * Complete entry with final status.
	 *
	 * @param string                $id      Entry ID.
	 * @param string                $status  Final status: 'success', 'error', or 'cancelled'.
	 * @param array<string, mixed>  $context Extra context data.
	 * @return void
	 * @since 1.0.0
	 */
	public function finish( string $id, string $status, array $context = array() ): void {
		$entries = $this->load();
		foreach ( $entries as &$entry ) {
			if ( $entry['id'] !== $id ) {
				continue;
			}

			$entry['status']      = sanitize_key( $status );
			$entry['finished_at'] = gmdate( 'c' );
			$entry['context']     = array_merge( $entry['context'], $this->sanitize_context( $context ) );
		}
		unset( $entry );

		$this->persist( $entries );
	}

	/**
	 * Update context for specific entry.
	 *
	 * @param string                $id      Entry ID.
	 * @param array<string, mixed>  $context Context overrides.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_context( string $id, array $context ): void {
		$entries = $this->load();
		foreach ( $entries as &$entry ) {
			if ( $entry['id'] !== $id ) {
				continue;
			}

			$entry['context'] = array_merge( $entry['context'], $this->sanitize_context( $context ) );
		}
		unset( $entry );

		$this->persist( $entries );
	}

	/**
	 * Update progress for running entry.
	 *
	 * @param string $id      Entry ID.
	 * @param int    $percent Progress percentage (0-100).
	 * @param string $message Progress message.
	 * @return void
	 * @since 1.0.0
	 */
	public function update_progress( string $id, int $percent, string $message = '' ): void {
		$entries = $this->load();
		foreach ( $entries as &$entry ) {
			if ( $entry['id'] !== $id ) {
				continue;
			}

			$entry['progress'] = array(
				'percent' => max( 0, min( 100, $percent ) ),
				'message' => sanitize_text_field( $message ),
			);
		}
		unset( $entry );

		$this->persist( $entries );
	}

	/**
	 * Fetch all entries (descending), filtering out entries with missing files.
	 *
	 * Automatically cleans up stale "running" entries before returning.
	 *
	 * @param int $limit Optional limit. Default 20.
	 * @return array<int, array<string, mixed>> Array of history entries.
	 * @since 1.0.0
	 */
	public function all( int $limit = 20 ): array {
		$this->cleanup_stale();

		$entries  = $this->load();
		$filtered = array_filter( $entries, array( $this, 'entry_file_exists' ) );

		return array_slice( array_values( $filtered ), 0, $limit );
	}

	/**
	 * Check if the file associated with entry exists.
	 *
	 * @param array<string, mixed> $entry History entry.
	 * @return bool True if file exists or entry has no file.
	 * @since 1.0.0
	 */
	private function entry_file_exists( array $entry ): bool {
		$file_keys = array( 'file', 'archive_path', 'snapshot_id' );

		foreach ( $file_keys as $key ) {
			if ( empty( $entry['context'][ $key ] ) ) {
				continue;
			}

			$path = $entry['context'][ $key ];

			// Handle relative paths.
			if ( ! path_is_absolute( $path ) ) {
				$path = PluginConfig::uploads_base_dir() . $path;
			}

			return file_exists( $path );
		}

		// Entry without file reference - keep it.
		return true;
	}

	/**
	 * Fetch entry by ID.
	 *
	 * This method bypasses cache to ensure fresh data, which is critical for
	 * REST API polling during long-running imports. Progress updates happen
	 * in one PHP process while REST API requests come from separate processes.
	 *
	 * @param string $id Entry ID.
	 * @return array<string, mixed>|null Entry data or null if not found.
	 * @since 1.0.0
	 */
	public function find( string $id ): ?array {
		// Load fresh data from database, bypassing cache to get latest progress updates.
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();

		foreach ( $entries as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Mark stale "running" entries as failed.
	 *
	 * Entries stuck in "running" status for longer than threshold are
	 * considered crashed and marked as errors.
	 *
	 * @param int $threshold_seconds Max seconds before marking as stale. Default 1 hour.
	 * @return int Number of entries cleaned up.
	 * @since 1.0.0
	 */
	public function cleanup_stale( int $threshold_seconds = 3600 ): int {
		$entries = $this->load();
		$now     = time();
		$cleaned = 0;

		foreach ( $entries as &$entry ) {
			if ( 'running' !== ( $entry['status'] ?? '' ) ) {
				continue;
			}

			$started = strtotime( $entry['started_at'] ?? '' );
			if ( false === $started ) {
				continue;
			}

			if ( ( $now - $started ) > $threshold_seconds ) {
				$entry['status']      = 'error';
				$entry['finished_at'] = gmdate( 'c' );
				$entry['context']     = array_merge(
					$entry['context'] ?? array(),
					array( 'message' => __( 'Operation timed out or crashed.', 'mksddn-migrate-content' ) )
				);
				++$cleaned;
			}
		}
		unset( $entry );

		if ( $cleaned > 0 ) {
			$this->persist( $entries );
		}

		return $cleaned;
	}

	/**
	 * Persist entries to database and cache.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries to store.
	 * @return void
	 * @since 1.0.0
	 */
	private function persist( array $entries ): void {
		update_option( self::OPTION, $entries, false );
		// Update cache.
		set_transient( self::CACHE_KEY, $entries, self::CACHE_EXPIRY );
		$this->cached_entries = $entries;
	}

	/**
	 * Load existing entries from cache or database.
	 *
	 * @return array<int, array<string, mixed>> Array of history entries.
	 * @since 1.0.0
	 */
	private function load(): array {
		// Return cached entries if available.
		if ( null !== $this->cached_entries ) {
			return $this->cached_entries;
		}

		// Try to get from transient cache.
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			$this->cached_entries = $cached;
			return $this->cached_entries;
		}

		// Load from database.
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? $entries : array();

		// Cache for 5 minutes.
		set_transient( self::CACHE_KEY, $entries, self::CACHE_EXPIRY );
		$this->cached_entries = $entries;

		return $entries;
	}

	/**
	 * Sanitize context data to prevent XSS and ensure data integrity.
	 *
	 * @param array<string, mixed> $context Context data.
	 * @return array<string, string> Sanitized context data.
	 * @since 1.0.0
	 */
	private function sanitize_context( array $context ): array {
		$allowed = array(
			'mode',
			'file',
			'snapshot_id',
			'snapshot_label',
			'archive_path',
			'message',
			'action',
			'user_selection',
		);

		$sanitized = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $context[ $key ] ) ) {
				continue;
			}

			$value = $context[ $key ];
			if ( is_scalar( $value ) ) {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return $sanitized;
	}
}


