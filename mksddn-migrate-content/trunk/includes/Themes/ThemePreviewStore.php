<?php
/**
 * @file: ThemePreviewStore.php
 * @description: Stores pending theme import previews between requests
 * @dependencies: Contracts\ThemePreviewStoreInterface
 * @created: 2026-02-21
 */

namespace MksDdn\MigrateContent\Themes;

use MksDdn\MigrateContent\Contracts\ThemePreviewStoreInterface;

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

	/**
	 * Save preview payload and return generated ID.
	 *
	 * @param array $payload Preview data.
	 * @return string
	 */
	public function create( array $payload ): string {
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

		return $id;
	}

	/**
	 * Fetch preview by ID.
	 *
	 * @param string $id Preview ID.
	 * @return array|null
	 */
	public function get( string $id ): ?array {
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
}

