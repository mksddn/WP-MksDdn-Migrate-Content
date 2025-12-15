<?php
/**
 * Stores lightweight job history in options table.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Recovery;

use MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists history entries for exports/imports/rollbacks.
 */
class HistoryRepository implements HistoryRepositoryInterface {

	private const OPTION = 'mksddn_mc_history';
	private const LIMIT  = 50;

	/**
	 * Start new entry.
	 *
	 * @param string $type    Entry type.
	 * @param array  $context Additional meta.
	 * @return string Entry ID.
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
	 * @param string $id      Entry ID.
	 * @param string $status  success|error|cancelled.
	 * @param array  $context Extra context.
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
	 * @param string $id      Entry ID.
	 * @param array  $context Context overrides.
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
	 * Fetch all entries (descending).
	 *
	 * @param int $limit Optional limit.
	 * @return array
	 */
	public function all( int $limit = 20 ): array {
		return array_slice( $this->load(), 0, $limit );
	}

	/**
	 * Fetch entry by ID.
	 *
	 * @param string $id Entry ID.
	 * @return array|null
	 */
	public function find( string $id ): ?array {
		foreach ( $this->load() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Persist entries.
	 *
	 * @param array $entries Entries to store.
	 */
	private function persist( array $entries ): void {
		update_option( self::OPTION, $entries, false );
	}

	/**
	 * Load existing entries.
	 *
	 * @return array
	 */
	private function load(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Sanitize context data.
	 *
	 * @param array $context Context data.
	 * @return array
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


