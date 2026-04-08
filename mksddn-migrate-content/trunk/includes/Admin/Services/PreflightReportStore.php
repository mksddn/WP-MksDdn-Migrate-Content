<?php
/**
 * @file: PreflightReportStore.php
 * @description: Short-lived storage for import preflight (dry-run) reports
 * @dependencies: None
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Admin\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists preflight reports in transients (TTL-bound, per-user).
 *
 * @since 2.2.0
 */
class PreflightReportStore {

	private const TTL_SECONDS = 900;

	private const KEY_PREFIX = 'mksddn_mc_pfl_';

	/**
	 * Save a report with import handle for the follow-up import step.
	 *
	 * @param int   $user_id        User id.
	 * @param array $report         Normalized report payload.
	 * @param array       $import_handle Descriptor to resolve the same file (chunk / server / staged path).
	 * @param string|null $forced_id     Optional fixed id (e.g. precomputed staging directory name).
	 * @return string Report id.
	 */
	public function save( int $user_id, array $report, array $import_handle, ?string $forced_id = null ): string {
		$id   = $forced_id ?? wp_generate_password( 24, false, false );
		$key  = self::KEY_PREFIX . $id;
		$data = array(
			'user_id'       => $user_id,
			'report'        => $report,
			'import_handle' => $import_handle,
		);
		set_transient( $key, $data, self::TTL_SECONDS );
		return $id;
	}

	/**
	 * Load stored bucket (report + handle) if valid for the user.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return array|null Keys: report, import_handle; or null.
	 */
	public function get_bucket_for_user( string $id, int $user_id ): ?array {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
		if ( '' === $id ) {
			return null;
		}
		$key  = self::KEY_PREFIX . $id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || empty( $data['report'] ) || ! is_array( $data['report'] ) ) {
			return null;
		}
		if ( (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}
		$handle = isset( $data['import_handle'] ) && is_array( $data['import_handle'] ) ? $data['import_handle'] : array();

		return array(
			'report'         => $data['report'],
			'import_handle'  => $handle,
		);
	}

	/**
	 * Load report if it exists and belongs to the user.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return array|null Report array or null.
	 */
	public function get_for_user( string $id, int $user_id ): ?array {
		$bucket = $this->get_bucket_for_user( $id, $user_id );
		return $bucket ? $bucket['report'] : null;
	}
}
