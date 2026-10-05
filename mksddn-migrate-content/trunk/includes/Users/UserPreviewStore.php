<?php
/**
 * Stores pending user merge previews between requests.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Users;

use MksDdn\MigrateContent\Contracts\UserPreviewStoreInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight transient-based storage for preview state.
 */
class UserPreviewStore implements UserPreviewStoreInterface {

	private const KEY_PREFIX   = 'mksddn_mc_user_preview_';
	private const TTL          = HOUR_IN_SECONDS;
	private const INDEX_OPTION = 'mksddn_mc_user_preview_index';

	/**
	 * Save preview payload and return generated ID.
	 *
	 * @param array $payload Preview data.
	 * @return string
	 */
	public function create( array $payload ): string {
		$this->cleanup_expired();

		$id   = wp_generate_uuid4();
		$data = array_merge(
			array(
				'id'         => $id,
				'created_at' => time(),
				'created_by' => get_current_user_id(),
			),
			$payload
		);

		set_transient( $this->build_key( $id ), $data, self::TTL );
		$this->store_index_entry( $id, $data );

		return $id;
	}

	/**
	 * Fetch preview by ID.
	 *
	 * @param string $id Preview ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
		$this->cleanup_expired();

		$data = get_transient( $this->build_key( $id ) );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Remove preview entry.
	 *
	 * @param string $id Preview ID.
	 * @return void
	 */
	public function delete( string $id ): void {
		delete_transient( $this->build_key( $id ) );
		$this->remove_index_entry( $id );
	}

	/**
	 * Delete previews owned by the user that match a preflight report or archive path.
	 *
	 * @param string   $report_id  Preflight report id (may be empty).
	 * @param string[] $file_paths Absolute archive paths to match.
	 * @param int      $user_id    Owner user id.
	 * @return int Number of deleted preview sessions.
	 */
	public function delete_related_to_preflight( string $report_id, array $file_paths, int $user_id ): int {
		$report_id = sanitize_text_field( $report_id );
		$path_set  = $this->normalize_path_set( $file_paths );
		$to_delete = array();

		foreach ( array_keys( $this->get_index() ) as $entry_id ) {
			$preview = $this->get( (string) $entry_id );
			if ( ! is_array( $preview ) ) {
				continue;
			}

			if ( (int) ( $preview['created_by'] ?? 0 ) !== $user_id ) {
				continue;
			}

			if ( ! $this->preview_matches_preflight( $preview, $report_id, $path_set ) ) {
				continue;
			}

			$to_delete[] = (string) $entry_id;
		}

		foreach ( $to_delete as $preview_id ) {
			$this->delete( $preview_id );
		}

		return count( $to_delete );
	}

	/**
	 * Build transient key for ID.
	 *
	 * @param string $id Preview ID.
	 * @return string
	 */
	private function build_key( string $id ): string {
		return self::KEY_PREFIX . sanitize_key( $id );
	}

	/**
	 * Store preview entry metadata for cleanup / related deletes.
	 *
	 * @param string $id   Preview ID.
	 * @param array  $data Preview data.
	 * @return void
	 */
	private function store_index_entry( string $id, array $data ): void {
		$index         = $this->get_index();
		$index[ $id ] = array(
			'id'                  => $id,
			'created_at'          => (int) ( $data['created_at'] ?? time() ),
			'created_by'          => (int) ( $data['created_by'] ?? 0 ),
			'file_path'           => isset( $data['file_path'] ) ? (string) $data['file_path'] : '',
			'preflight_report_id' => isset( $data['preflight_report_id'] ) ? sanitize_text_field( (string) $data['preflight_report_id'] ) : '',
		);
		$this->save_index( $index );
	}

	/**
	 * Remove preview entry from index.
	 *
	 * @param string $id Preview ID.
	 * @return void
	 */
	private function remove_index_entry( string $id ): void {
		$index = $this->get_index();
		if ( isset( $index[ $id ] ) ) {
			unset( $index[ $id ] );
			$this->save_index( $index );
		}
	}

	/**
	 * Cleanup expired previews from the index.
	 *
	 * @return void
	 */
	private function cleanup_expired(): void {
		$index = $this->get_index();
		if ( empty( $index ) ) {
			return;
		}

		$now     = time();
		$changed = false;

		foreach ( $index as $entry_id => $entry ) {
			$created_at = (int) ( is_array( $entry ) ? ( $entry['created_at'] ?? 0 ) : 0 );
			if ( $created_at > 0 && ( $created_at + self::TTL ) > $now ) {
				continue;
			}

			delete_transient( $this->build_key( (string) $entry_id ) );
			unset( $index[ $entry_id ] );
			$changed = true;
		}

		if ( $changed ) {
			$this->save_index( $index );
		}
	}

	/**
	 * Whether a preview belongs to the dismissed preflight session.
	 *
	 * @param array    $preview   Preview payload.
	 * @param string   $report_id Report id.
	 * @param string[] $path_set  Normalized absolute paths.
	 */
	private function preview_matches_preflight( array $preview, string $report_id, array $path_set ): bool {
		if ( '' !== $report_id ) {
			$preview_report = isset( $preview['preflight_report_id'] )
				? sanitize_text_field( (string) $preview['preflight_report_id'] )
				: '';
			if ( $preview_report === $report_id ) {
				return true;
			}
		}

		$file_path = isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '';
		if ( '' === $file_path || array() === $path_set ) {
			return false;
		}

		$real = realpath( $file_path );
		$key  = false !== $real ? $real : $file_path;
		return isset( $path_set[ $key ] );
	}

	/**
	 * Normalize absolute paths into a set keyed by realpath when available.
	 *
	 * @param string[] $file_paths Paths.
	 * @return array<string, true>
	 */
	private function normalize_path_set( array $file_paths ): array {
		$set = array();
		foreach ( $file_paths as $path ) {
			if ( ! is_string( $path ) || '' === $path ) {
				continue;
			}
			$real = realpath( $path );
			$set[ false !== $real ? $real : $path ] = true;
		}
		return $set;
	}

	/**
	 * Get index value.
	 *
	 * @return array
	 */
	private function get_index(): array {
		$index = get_option( self::INDEX_OPTION, array() );
		return is_array( $index ) ? $index : array();
	}

	/**
	 * Save index value.
	 *
	 * @param array $index Index data.
	 * @return void
	 */
	private function save_index( array $index ): void {
		update_option( self::INDEX_OPTION, $index, false );
	}
}
