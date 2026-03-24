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
	 * @param array $tables   Table hints (optional - will auto-detect if empty).
	 * @return void
	 */
	public function strip_user_tables( array &$database, array $tables = array() ): void {
		if ( empty( $database['tables'] ) || ! is_array( $database['tables'] ) ) {
			return;
		}

		$tables_to_remove = array();

		// Use provided table hints if available.
		if ( ! empty( $tables['users'] ) ) {
			$tables_to_remove[] = $tables['users'];
		}
		if ( ! empty( $tables['usermeta'] ) ) {
			$tables_to_remove[] = $tables['usermeta'];
		}

		// Auto-detect user tables if hints are missing.
		if ( empty( $tables_to_remove ) ) {
			$users_table    = $this->find_table_name( $database['tables'], 'users' );
			$usermeta_table = $this->find_table_name( $database['tables'], 'usermeta' );

			if ( $users_table ) {
				$tables_to_remove[] = $users_table;
			}
			if ( $usermeta_table ) {
				$tables_to_remove[] = $usermeta_table;
			}
		}

		// Remove detected user tables from dump.
		foreach ( $tables_to_remove as $table_name ) {
			if ( isset( $database['tables'][ $table_name ] ) ) {
				unset( $database['tables'][ $table_name ] );
			}
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
			'created'  => 0,
			'updated'  => 0,
			'skipped'  => 0,
			'preserved' => 0,
		);

		// Check if at least one user is selected for import.
		$has_selected = false;
		foreach ( $plan as $action ) {
			if ( ! empty( $action['import'] ) ) {
				$has_selected = true;
				break;
			}
		}

		// If no users selected, preserve current admin to avoid lockout.
		if ( ! $has_selected ) {
			$this->preserve_current_admin();
			return $this->summary;
		}

		// Ensure current admin is not accidentally removed.
		$current_user = wp_get_current_user();
		if ( $current_user && $current_user->exists() ) {
			$current_email = strtolower( $current_user->user_email );
			
			// If current admin is not in the plan or disabled, auto-preserve them.
			if ( ! isset( $plan[ $current_email ] ) || empty( $plan[ $current_email ]['import'] ) ) {
				// Current admin not selected - keep them to avoid lockout.
				$this->summary['preserved']++;
			}
		}

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
	 * Preserve current admin user to avoid lockout.
	 * Called when no users are selected for import.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function preserve_current_admin(): void {
		$current_user = wp_get_current_user();
		if ( ! $current_user || ! $current_user->exists() ) {
			return;
		}

		// Current admin is preserved automatically by not touching user tables.
		$this->summary['preserved'] = 1;
		$this->summary['skipped'] = 0;
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
		$row     = $remote['row'];
		$user_id = (int) $user->ID;

		$desired_login = $this->resolve_remote_user_login( $row, $remote['email'] );
		if ( '' !== $desired_login ) {
			$desired_login = $this->ensure_unique_login_for_user( $desired_login, $user_id );
		} else {
			$desired_login = $user->user_login;
		}

		$userdata = array(
			'ID'            => $user_id,
			'user_login'    => $desired_login,
			'display_name'  => sanitize_text_field( $row['display_name'] ?? $user->display_name ),
			'user_nicename' => sanitize_title( $row['user_nicename'] ?? $user->user_nicename ),
			'user_url'      => esc_url_raw( $row['user_url'] ?? $user->user_url ),
		);

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Core wp_update_user() uses sanitize_user( ..., true ); login can stay unchanged while we still apply password hash below.
		$this->force_wp_users_login_and_password( $user_id, $desired_login, (string) ( $row['user_pass'] ?? '' ) );
		$this->sync_user_meta( $user_id, $remote['meta'], $remote_prefix );

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
		$row   = $remote['row'];

		$base_login = $this->resolve_remote_user_login( $row, $email );
		if ( '' === $base_login ) {
			$local_part = preg_match( '/^([^@]+)@/', $email, $m ) ? $m[1] : '';
			$base_login = sanitize_user( $local_part, false );
		}
		if ( '' === $base_login ) {
			$base_login = 'imported-user';
		}

		$login = $this->ensure_unique_login( $base_login );

		$userdata = array(
			'user_login'      => $login,
			'user_email'      => $email,
			'user_pass'       => wp_generate_password( 32, true, true ),
			'display_name'    => sanitize_text_field( $row['display_name'] ?? $login ),
			'user_nicename'   => sanitize_title( $row['user_nicename'] ?? $login ),
			'user_url'        => esc_url_raw( $row['user_url'] ?? '' ),
			'user_registered' => sanitize_text_field( $row['user_registered'] ?? current_time( 'mysql' ) ),
			'user_status'     => isset( $row['user_status'] ) ? (int) $row['user_status'] : 0,
		);

		$user_id = wp_insert_user( $userdata );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$this->force_wp_users_login_and_password( (int) $user_id, $login, (string) ( $row['user_pass'] ?? '' ) );
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
	 * Resolve a login that is unique on this site, allowing the given user ID to keep its current login.
	 *
	 * @param string $login   Desired login from remote.
	 * @param int    $user_id Local user being updated.
	 * @return string
	 */
	private function ensure_unique_login_for_user( string $login, int $user_id ): string {
		$base = $login ?: 'imported-user';
		$name = $base;
		$i    = 1;

		while ( true ) {
			$existing = get_user_by( 'login', $name );
			if ( ! $existing instanceof WP_User || (int) $existing->ID === $user_id ) {
				return $name;
			}
			$name = $base . '-' . $i;
			++$i;
		}
	}

	/**
	 * Read user_login from archive row. Try loose sanitize first — core wp_update_user/wp_insert_user apply strict sanitize_user() and may drop the login.
	 *
	 * @param array  $row            Users table row.
	 * @param string $fallback_email Sanitized email (local part used if login is empty).
	 * @return string Desired login or empty string.
	 */
	private function resolve_remote_user_login( array $row, string $fallback_email = '' ): string {
		$raw = isset( $row['user_login'] ) ? trim( (string) $row['user_login'] ) : '';

		$loose = sanitize_user( $raw, false );
		if ( '' !== $loose ) {
			return $loose;
		}

		$strict = sanitize_user( $raw, true );
		if ( '' !== $strict ) {
			return $strict;
		}

		if ( '' !== $fallback_email && preg_match( '/^([^@]+)@/', $fallback_email, $m ) ) {
			$from_email = sanitize_user( $m[1], false );
			if ( '' !== $from_email ) {
				return $from_email;
			}
		}

		return '';
	}

	/**
	 * Force user_login and user_pass in wp_users so they match the archive (core sanitization can leave the old login).
	 *
	 * @param int    $user_id   User ID.
	 * @param string $login     Resolved unique login.
	 * @param string $pass_hash Password hash from archive.
	 * @return void
	 */
	private function force_wp_users_login_and_password( int $user_id, string $login, string $pass_hash ): void {
		global $wpdb;

		$data   = array( 'user_login' => $login );
		$format = array( '%s' );

		if ( '' !== $pass_hash ) {
			$data['user_pass'] = $pass_hash;
			$format[]          = '%s';
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->users,
			$data,
			array( 'ID' => $user_id ),
			$format,
			array( '%d' )
		);

		clean_user_cache( $user_id );
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

			// Skip incomplete objects (classes not loaded) to avoid fatal errors.
			// WordPress plugins may serialize objects that aren't available on target site.
			// Check recursively for incomplete objects in arrays/objects.
			if ( $this->contains_incomplete_object( $value ) ) {
				continue;
			}

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
	 * Recursively check if value contains incomplete objects.
	 *
	 * @param mixed $value Value to check.
	 * @return bool True if contains incomplete object, false otherwise.
	 */
	private function contains_incomplete_object( $value ): bool {
		if ( $value instanceof \__PHP_Incomplete_Class ) {
			return true;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_incomplete_object( $item ) ) {
					return true;
				}
			}
		}

		if ( is_object( $value ) && ! ( $value instanceof \__PHP_Incomplete_Class ) ) {
			// Check object properties recursively.
			// Convert object to array to safely access properties.
			// This prevents errors when accessing properties of incomplete objects.
			try {
				$properties = (array) $value;
				foreach ( $properties as $prop_value ) {
					if ( $this->contains_incomplete_object( $prop_value ) ) {
						return true;
					}
				}
			} catch ( \Error $e ) {
				// If we can't access object properties, assume it contains incomplete objects.
				return true;
			}
		}

		return false;
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


