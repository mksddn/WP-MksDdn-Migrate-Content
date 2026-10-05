<?php
/**
 * @file: PreflightReportStore.php
 * @description: Short-lived storage for import preflight (dry-run) reports
 * @dependencies: Config\PluginConfig, Support\FilesystemHelper, Support\PreflightStagingPath
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\PreflightStagingPath;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists preflight reports on disk with a small transient index (TTL-bound, per-user).
 *
 * Field-level Selected Content diffs can exceed typical MySQL max_allowed_packet /
 * object-cache value limits when stored entirely in a transient. The report body
 * lives under uploads/mksddn-mc/preflight/ as JSON; the transient only keeps
 * user_id, import_handle, and the report basename.
 *
 * @since 2.2.0
 */
class PreflightReportStore {

	/**
	 * Session TTL in seconds (transient index + report JSON retention).
	 */
	public const TTL_SECONDS = 900;

	private const KEY_PREFIX = 'mksddn_mc_pfl_';

	private const REPORT_FILE_PREFIX = 'report-';

	private const REPORT_FILE_SUFFIX = '.json';

	/**
	 * Save a report with import handle for the follow-up import step.
	 *
	 * @param int         $user_id        User id.
	 * @param array       $report         Normalized report payload.
	 * @param array       $import_handle  Descriptor to resolve the same file (chunk / server / staged path).
	 * @param string|null $forced_id      Optional fixed id (e.g. precomputed staging directory name).
	 * @return string|WP_Error Report id or error.
	 */
	public function save( int $user_id, array $report, array $import_handle, ?string $forced_id = null ) {
		$id = $forced_id ?? wp_generate_password( 24, false, false );
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $id );
		if ( '' === $id ) {
			return new WP_Error(
				'mksddn_mc_preflight_save',
				__( 'Could not create a preflight report identifier.', 'mksddn-migrate-content' )
			);
		}

		$report_file = $this->write_report_file( $id, $report );
		if ( is_wp_error( $report_file ) ) {
			return $report_file;
		}

		$key  = self::KEY_PREFIX . $id;
		$data = array(
			'user_id'       => $user_id,
			'report_file'   => basename( $report_file ),
			'import_handle' => $import_handle,
			'phase'         => 'ready',
		);

		$saved = set_transient( $key, $data, self::TTL_SECONDS );
		if ( ! $saved ) {
			$this->delete_report_file( $report_file );
			return new WP_Error(
				'mksddn_mc_preflight_save',
				__( 'Could not store the preflight session. Check object cache / database limits and try again.', 'mksddn-migrate-content' )
			);
		}

		return $id;
	}

	/**
	 * Claim a ready preflight session for the import step.
	 *
	 * Validates report status, marks the session as importing (blocks concurrent
	 * Start import / dismiss races), and returns the bucket.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return array|WP_Error Keys: report, import_handle, phase.
	 */
	public function claim_for_import( string $id, int $user_id ) {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
		if ( '' === $id ) {
			return new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		$key  = self::KEY_PREFIX . $id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		$report = $this->resolve_report_payload( $data, $id );
		if ( null === $report ) {
			return new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		$status = isset( $report['status'] ) ? sanitize_key( (string) $report['status'] ) : 'ok';
		$errors = isset( $report['errors'] ) && is_array( $report['errors'] ) ? $report['errors'] : array();
		if ( 'error' === $status || array() !== $errors ) {
			return new WP_Error(
				'mksddn_mc_preflight_blocked',
				__( 'This preflight report has errors. Resolve them and run preflight again before importing.', 'mksddn-migrate-content' )
			);
		}

		$handle = isset( $data['import_handle'] ) && is_array( $data['import_handle'] ) ? $data['import_handle'] : array();
		if ( array() === $handle ) {
			return new WP_Error(
				'mksddn_mc_preflight_invalid',
				__( 'Preflight session expired or invalid. Run preflight again.', 'mksddn-migrate-content' )
			);
		}

		$phase = isset( $data['phase'] ) ? sanitize_key( (string) $data['phase'] ) : 'ready';
		if ( 'importing' === $phase ) {
			return new WP_Error(
				'mksddn_mc_preflight_busy',
				__( 'Import already started for this preflight session. Finish or wait for it to complete before starting again.', 'mksddn-migrate-content' )
			);
		}

		$data['phase'] = 'importing';
		$saved         = set_transient( $key, $data, self::TTL_SECONDS );
		if ( ! $saved ) {
			return new WP_Error(
				'mksddn_mc_preflight_claim_failed',
				__( 'Could not update preflight session status. Try again.', 'mksddn-migrate-content' )
			);
		}

		// Touch report JSON file so its retention aligns with refreshed transient TTL.
		$report_path = $this->report_path_from_meta( $data, $id );
		if ( null !== $report_path && is_file( $report_path ) ) {
			FilesystemHelper::instance()->touch( $report_path );
		}

		return array(
			'report'        => $report,
			'import_handle' => $handle,
			'phase'         => 'importing',
		);
	}

	/**
	 * Load stored bucket (report + handle) if valid for the user.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return array|null Keys: report, import_handle, phase, claimed_at; or null.
	 */
	public function get_bucket_for_user( string $id, int $user_id ): ?array {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
		if ( '' === $id ) {
			return null;
		}

		$key  = self::KEY_PREFIX . $id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return null;
		}

		$handle = isset( $data['import_handle'] ) && is_array( $data['import_handle'] ) ? $data['import_handle'] : array();
		$report = $this->resolve_report_payload( $data, $id );
		if ( null === $report ) {
			return null;
		}

		$phase = isset( $data['phase'] ) ? sanitize_key( (string) $data['phase'] ) : 'ready';

		return array(
			'report'        => $report,
			'import_handle' => $handle,
			'phase'         => '' !== $phase ? $phase : 'ready',
		);
	}

	/**
	 * Release an import claim so the user can retry or dismiss after a failed start.
	 *
	 * Only resets phase from importing → ready; does not delete the report or handle.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return bool True when phase was reset.
	 */
	public function release_import_claim( string $id, int $user_id ): bool {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
		if ( '' === $id ) {
			return false;
		}

		$key  = self::KEY_PREFIX . $id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return false;
		}

		$phase = isset( $data['phase'] ) ? sanitize_key( (string) $data['phase'] ) : 'ready';
		if ( 'importing' !== $phase ) {
			return false;
		}

		$data['phase'] = 'ready';
		set_transient( $key, $data, self::TTL_SECONDS );

		// Touch report JSON file so its retention aligns with refreshed transient TTL.
		$report_path = $this->report_path_from_meta( $data, $id );
		if ( null !== $report_path && is_file( $report_path ) ) {
			FilesystemHelper::instance()->touch( $report_path );
		}

		return true;
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

	/**
	 * Delete a stored preflight report bucket when it belongs to the user.
	 *
	 * @param string $id      Report id.
	 * @param int    $user_id Current user id.
	 * @return bool True when a matching bucket was deleted.
	 */
	public function delete_for_user( string $id, int $user_id ): bool {
		$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', $id );
		if ( '' === $id ) {
			return false;
		}

		$key  = self::KEY_PREFIX . $id;
		$data = get_transient( $key );
		if ( ! is_array( $data ) || (int) ( $data['user_id'] ?? 0 ) !== $user_id ) {
			return false;
		}

		$path = $this->report_path_from_meta( $data, $id );
		if ( null !== $path ) {
			$this->delete_report_file( $path );
		}

		delete_transient( $key );
		return true;
	}

	/**
	 * Resolve report array from file-backed or legacy inline transient payload.
	 *
	 * @param array  $data Transient payload.
	 * @param string $id   Report id.
	 * @return array|null
	 */
	private function resolve_report_payload( array $data, string $id ): ?array {
		// Legacy: entire report stored inside the transient.
		if ( ! empty( $data['report'] ) && is_array( $data['report'] ) ) {
			return $data['report'];
		}

		$path = $this->report_path_from_meta( $data, $id );
		if ( null === $path || ! is_readable( $path ) ) {
			return null;
		}

		$raw = FilesystemHelper::instance()->get_contents( $path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Absolute report JSON path from transient meta, validated under preflight/.
	 *
	 * @param array  $data Transient payload.
	 * @param string $id   Report id.
	 * @return string|null
	 */
	private function report_path_from_meta( array $data, string $id ): ?string {
		if ( ! empty( $data['report_file'] ) && is_string( $data['report_file'] ) ) {
			$basename = basename( $data['report_file'] );
		} else {
			$basename = self::REPORT_FILE_PREFIX . $id . self::REPORT_FILE_SUFFIX;
		}

		$pattern = '/^' . preg_quote( self::REPORT_FILE_PREFIX, '/' ) . '[a-zA-Z0-9_-]+' . preg_quote( self::REPORT_FILE_SUFFIX, '/' ) . '$/';
		if ( ! preg_match( $pattern, $basename ) ) {
			return null;
		}

		$path = trailingslashit( PluginConfig::preflight_dir() ) . $basename;
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) ) {
			return null;
		}

		if ( ! PreflightStagingPath::is_allowed_path( $real ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * Write report JSON into the preflight staging directory.
	 *
	 * @param string $id     Report id.
	 * @param array  $report Report payload.
	 * @return string|WP_Error Absolute path or error.
	 */
	private function write_report_file( string $id, array $report ) {
		$dir = PluginConfig::preflight_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_dir',
				__( 'Could not create the preflight staging directory.', 'mksddn-migrate-content' )
			);
		}

		FilesystemHelper::protect_directory_from_web( $dir );

		$json = wp_json_encode( $report );
		if ( ! is_string( $json ) || '' === $json ) {
			return new WP_Error(
				'mksddn_mc_preflight_encode',
				__( 'Could not encode the preflight report.', 'mksddn-migrate-content' )
			);
		}

		$path = trailingslashit( $dir ) . self::REPORT_FILE_PREFIX . $id . self::REPORT_FILE_SUFFIX;
		if ( ! FilesystemHelper::put_contents( $path, $json ) ) {
			return new WP_Error(
				'mksddn_mc_preflight_write',
				__( 'Could not write the preflight report file.', 'mksddn-migrate-content' )
			);
		}

		return $path;
	}

	/**
	 * Delete a report JSON file when it is under preflight/.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private function delete_report_file( string $path ): void {
		if ( '' === $path ) {
			return;
		}
		if ( PreflightStagingPath::is_ephemeral_path( $path ) || PreflightStagingPath::is_allowed_path( $path ) ) {
			FilesystemHelper::delete( $path );
		}
	}
}
