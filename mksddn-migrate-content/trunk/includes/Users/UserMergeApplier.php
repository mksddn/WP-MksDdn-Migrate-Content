<?php
/**
 * Applies selected user merge plan during full import.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Users;

use MksDdn\MigrateContent\Contracts\UserMergeApplierInterface;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing/updating users from archive payload.
 */
class UserMergeApplier implements UserMergeApplierInterface {

	/**
	 * Summary of last merge operation.
	 *
	 * @var array
	 */
	private array $summary = array(
		'created' => 0,
		'updated' => 0,
		'skipped' => 0,
	);

	/**
	 * Extract remote users keyed by email for merging.
	 *
	 * @param array $database    Database dump.
	 * @param array $table_hints Optional hints for table names.
	 * @return array
	 */
	public function extract_remote_users( array $database, array $table_hints = array() ): array {
		$tables         = isset( $database['tables'] ) && is_array( $database['tables'] ) ? $database['tables'] : array();
		$users_table    = $table_hints['users'] ?? $this->find_table_name( $tables, 'users' );
		$usermeta_table = $table_hints['usermeta'] ?? $this->find_table_name( $tables, 'usermeta' );

		if ( ! $users_table || ! $usermeta_table ) {
			return array(
				'prefix'      => '',
				'users_table' => $users_table,
				'usermeta'    => $usermeta_table,
				'users'       => array(),
			);
		}

		$prefix    = $table_hints['prefix'] ?? substr( $users_table, 0, -strlen( 'users' ) );
		$user_rows = isset( $tables[ $users_table ]['rows'] ) && is_array( $tables[ $users_table ]['rows'] ) ? $tables[ $users_table ]['rows'] : array();
		$meta_rows = isset( $tables[ $usermeta_table ]['rows'] ) && is_array( $tables[ $usermeta_table ]['rows'] ) ? $tables[ $usermeta_table ]['rows'] : array();

		$meta_by_user = array();
		foreach ( $meta_rows as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$user_id = isset( $meta['user_id'] ) ? (int) $meta['user_id'] : 0;
			if ( $user_id <= 0 ) {
				continue;
			}
			$meta_by_user[ $user_id ][] = array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Not a DB query, processing array data from archive dump.
				'meta_key'   => isset( $meta['meta_key'] ) ? (string) $meta['meta_key'] : '',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Not a DB query, processing array data from archive dump.
				'meta_value' => $meta['meta_value'] ?? '',
			);
		}

		$remote_users = array();
		foreach ( $user_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$email = isset( $row['user_email'] ) ? sanitize_email( $row['user_email'] ) : '';
			if ( ! $email ) {
				continue;
			}

			$id = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			$remote_users[ strtolower( $email ) ] = array(
				'email' => $email,
				'row'   => $row,
				'meta'  => $meta_by_user[ $id ] ?? array(),
			);
		}

		return array(
			'prefix'      => $prefix,
			'users_table' => $users_table,
			'usermeta'    => $usermeta_table,
			'users'       => $remote_users,
		);
	}

	/**
	 * Remove user tables from database dump prior to import.
	 *
	 * @param array $database Database dump reference.
	 * @param array $tables   Table hints.
	 * @return void
	 */
	public function strip_user_tables( array &$database, array $tables ): void {
		if ( empty( $database['tables'] ) || ! is_array( $database['tables'] ) ) {
			return;
		}

		foreach ( array( 'users', 'usermeta' ) as $key ) {
			if ( empty( $tables[ $key ] ) ) {
				continue;
			}

			unset( $database['tables'][ $tables[ $key ] ] );
		}
	}

	/**
	 * Apply merge plan to current site.
	 *
	 * @param array $remote_users Remote users keyed by lowercase email.
	 * @param array $plan         Normalized plan keyed by email.
	 * @param string $remote_prefix Remote table prefix for meta normalization.
	 * @return array|WP_Error Summary or error.
	 */
	public function merge( array $remote_users, array $plan, string $remote_prefix ): array|WP_Error {
		$this->summary = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
		);

		foreach ( $plan as $email_key => $action ) {
			$email_key = strtolower( sanitize_email( $email_key ) );
			if ( ! $email_key || empty( $action['import'] ) ) {
				$this->summary['skipped']++;
				continue;
			}

			$remote = $remote_users[ $email_key ] ?? null;
			if ( ! $remote ) {
				$this->summary['skipped']++;
				continue;
			}

			$result = $this->apply_single_user( $remote, $action, $remote_prefix );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->summary[ $result ]++;
		}

		return $this->summary;
	}

	/**
	 * Get last summary stats.
	 *
	 * @return array
	 */
	public function get_summary(): array {
		return $this->summary;
	}

	/**
	 * Apply merge action for single user.
	 *
	 * @param array  $remote        Remote user data.
	 * @param array  $action        Action config.
	 * @param string $remote_prefix Remote prefix for meta normalization.
	 * @return string|WP_Error created|updated|skipped or error.
	 */
	private function apply_single_user( array $remote, array $action, string $remote_prefix ) {
		$email    = $remote['email'];
		$existing = get_user_by( 'email', $email );

		if ( $existing instanceof WP_User ) {
			$mode = isset( $action['mode'] ) && 'keep' === $action['mode'] ? 'keep' : 'replace';
			if ( 'keep' === $mode ) {
				return 'skipped';
			}

			$result = $this->update_existing_user( $existing, $remote, $remote_prefix );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return 'updated';
		}

		$result = $this->create_new_user( $remote, $remote_prefix );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return 'created';
	}

	/**
	 * Update existing user with archive data.
	 *
	 * @param WP_User $user          Existing user.
	 * @param array   $remote        Remote data.
	 * @param string  $remote_prefix Remote prefix.
	 * @return true|WP_Error
	 */
	private function update_existing_user( WP_User $user, array $remote, string $remote_prefix ) {
		$userdata = array(
			'ID'           => $user->ID,
			'display_name' => sanitize_text_field( $remote['row']['display_name'] ?? $user->display_name ),
			'user_nicename'=> sanitize_title( $remote['row']['user_nicename'] ?? $user->user_nicename ),
			'user_url'     => esc_url_raw( $remote['row']['user_url'] ?? $user->user_url ),
		);

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->update_password_hash( $user->ID, $remote['row']['user_pass'] ?? '' );
		$this->sync_user_meta( $user->ID, $remote['meta'], $remote_prefix );

		return true;
	}

	/**
	 * Create new user from archive row.
	 *
	 * @param array  $remote        Remote data.
	 * @param string $remote_prefix Remote prefix.
	 * @return true|WP_Error
	 */
	private function create_new_user( array $remote, string $remote_prefix ) {
		$email = $remote['email'];
		$login = $this->ensure_unique_login( sanitize_user( $remote['row']['user_login'] ?? $email, true ) );

		$userdata = array(
			'user_login'    => $login,
			'user_email'    => $email,
			'user_pass'     => wp_generate_password( 32, true, true ),
			'display_name'  => sanitize_text_field( $remote['row']['display_name'] ?? $login ),
			'user_nicename' => sanitize_title( $remote['row']['user_nicename'] ?? $login ),
			'user_url'      => esc_url_raw( $remote['row']['user_url'] ?? '' ),
			'user_registered' => sanitize_text_field( $remote['row']['user_registered'] ?? current_time( 'mysql' ) ),
			'user_status'   => isset( $remote['row']['user_status'] ) ? (int) $remote['row']['user_status'] : 0,
		);

		$user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$this->update_password_hash( (int) $user_id, $remote['row']['user_pass'] ?? '' );
		$this->sync_user_meta( (int) $user_id, $remote['meta'], $remote_prefix );

		return true;
	}

	/**
	 * Ensure username collision-free.
	 *
	 * @param string $login Desired login.
	 * @return string
	 */
	private function ensure_unique_login( string $login ): string {
		$base = $login ?: 'imported-user';
		$name = $base;
		$i    = 1;

		while ( username_exists( $name ) ) {
			$name = $base . '-' . $i;
			$i++;
		}

		return $name;
	}

	/**
	 * Update password hash directly.
	 *
	 * @param int    $user_id User ID.
	 * @param string $hash    Hash string.
	 * @return void
	 */
	private function update_password_hash( int $user_id, string $hash ): void {
		if ( '' === $hash ) {
			return;
		}

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->users,
			array( 'user_pass' => $hash ),
			array( 'ID' => $user_id )
		);
	}

	/**
	 * Sync user meta entries from archive to target site.
	 *
	 * @param int    $user_id       Local user ID.
	 * @param array  $meta_rows     Remote meta rows.
	 * @param string $remote_prefix Remote prefix for capability keys.
	 * @return void
	 */
	private function sync_user_meta( int $user_id, array $meta_rows, string $remote_prefix ): void {
		global $wpdb;
		$local_prefix = $wpdb->prefix;

		foreach ( $meta_rows as $row ) {
			$key = isset( $row['meta_key'] ) ? (string) $row['meta_key'] : '';
			if ( '' === $key ) {
				continue;
			}

			if ( 'session_tokens' === $key ) {
				continue;
			}

			$normalized = $this->normalize_meta_key( $key, $remote_prefix, $local_prefix );
			$value      = maybe_unserialize( $row['meta_value'] ?? '' );

			update_user_meta( $user_id, $normalized, $value );
		}
	}

	/**
	 * Normalize meta keys by swapping table prefixes.
	 *
	 * @param string $key           Meta key.
	 * @param string $remote_prefix Remote prefix.
	 * @param string $local_prefix  Local prefix.
	 * @return string
	 */
	private function normalize_meta_key( string $key, string $remote_prefix, string $local_prefix ): string {
		if ( $remote_prefix && 0 === strpos( $key, $remote_prefix ) ) {
			return $local_prefix . substr( $key, strlen( $remote_prefix ) );
		}

		return $key;
	}

	/**
	 * Locate table name by suffix.
	 *
	 * @param array  $tables Table definitions.
	 * @param string $suffix Target suffix.
	 * @return string|null
	 */
	private function find_table_name( array $tables, string $suffix ): ?string {
		$length = strlen( $suffix );
		foreach ( array_keys( $tables ) as $name ) {
			$normalized = sanitize_text_field( (string) $name );
			if ( '' === $normalized ) {
				continue;
			}

			if ( substr( $normalized, -$length ) === $suffix ) {
				return $normalized;
			}
		}

		return null;
	}
}


