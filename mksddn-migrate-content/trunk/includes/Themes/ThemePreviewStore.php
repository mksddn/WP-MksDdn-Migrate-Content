<?php
/**
 * @file: ThemePreviewStore.php
 * @description: Stores pending theme import previews between requests
 * @dependencies: Contracts\ThemePreviewStoreInterface, Support\FilesystemHelper
 * @created: 2026-02-21
 */

namespace MksDdn\MigrateContent\Themes;

use MksDdn\MigrateContent\Contracts\ThemePreviewStoreInterface;
use MksDdn\MigrateContent\Support\FilesystemHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight transient-based storage for theme preview state.
 *
 * @since 2.1.0
 */
class ThemePreviewStore implements ThemePreviewStoreInterface {

	private const KEY_PREFIX = 'mksddn_mc_theme_preview_';
	private const TTL        = HOUR_IN_SECONDS;
	private const INDEX_OPTION = 'mksddn_mc_theme_preview_index';

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
	 * Remove all theme preview entries, temp files, and the index (plugin deactivation).
	 *
	 * @return void
	 * @since 2.1.7
	 */
	public function purge_all(): void {
		$index = $this->get_index();
		foreach ( $index as $entry_id => $entry ) {
			if ( is_array( $entry ) ) {
				$this->cleanup_entry( $entry );
			}
			delete_transient( $this->build_key( (string) $entry_id ) );
		}
		delete_option( self::INDEX_OPTION );
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
	 * Store preview entry metadata for cleanup.
	 *
	 * @param string $id Preview ID.
	 * @param array  $data Preview data.
	 * @return void
	 */
	private function store_index_entry( string $id, array $data ): void {
		$index = $this->get_index();
		$index[ $id ] = array(
			'id'         => $id,
			'created_at' => (int) ( $data['created_at'] ?? time() ),
			'file_path'  => isset( $data['file_path'] ) ? (string) $data['file_path'] : '',
			'cleanup'    => ! empty( $data['cleanup'] ),
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
	 * Cleanup expired previews and temp files.
	 *
	 * @return void
	 */
	private function cleanup_expired(): void {
		$index = $this->get_index();
		if ( empty( $index ) ) {
			return;
		}

		$now = time();
		$changed = false;

		foreach ( $index as $entry_id => $entry ) {
			$created_at = (int) ( $entry['created_at'] ?? 0 );
			if ( $created_at > 0 && ( $created_at + self::TTL ) > $now ) {
				continue;
			}

			$this->cleanup_entry( $entry );
			delete_transient( $this->build_key( (string) $entry_id ) );
			unset( $index[ $entry_id ] );
			$changed = true;
		}

		if ( $changed ) {
			$this->save_index( $index );
		}
	}

	/**
	 * Cleanup entry resources.
	 *
	 * @param array $entry Entry data.
	 * @return void
	 */
	private function cleanup_entry( array $entry ): void {
		$cleanup   = ! empty( $entry['cleanup'] );
		$file_path = isset( $entry['file_path'] ) ? (string) $entry['file_path'] : '';

		if ( $cleanup && $file_path && file_exists( $file_path ) ) {
			FilesystemHelper::delete( $file_path );
		}
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

