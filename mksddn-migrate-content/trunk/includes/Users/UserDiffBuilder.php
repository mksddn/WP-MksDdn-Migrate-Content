<?php
/**
 * Builds comparison between current users and archive payload.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Users;

use MksDdn\MigrateContent\Contracts\UserDiffBuilderInterface;
use MksDdn\MigrateContent\Filesystem\FullArchivePayload;
use WP_Error;
use WP_Roles;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates normalized structures for user selection UI.
 */
class UserDiffBuilder implements UserDiffBuilderInterface {

	/**
	 * Build diff data based on archive payload and current site users.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @return array|WP_Error
	 */
	public function build( string $archive_path ) {
		$payload = FullArchivePayload::read( $archive_path );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$database = isset( $payload['database'] ) && is_array( $payload['database'] ) ? $payload['database'] : array();
		$remote   = $this->extract_remote_users( $database );
		$local    = $this->collect_local_users();

		$incoming = $this->combine_users( $remote, $local );
		$counts   = array(
			'incoming'  => count( $incoming ),
			'conflicts' => $this->count_conflicts( $incoming ),
			'local'     => count( $local ),
		);

		return array(
			'incoming' => $incoming,
			'counts'   => $counts,
			'tables'   => array(
				'users'    => $remote['users_table'],
				'usermeta' => $remote['usermeta_table'],
				'prefix'   => $remote['prefix'],
			),
		);
	}

	/**
	 * Extract remote users and basic metadata.
	 *
	 * @param array $database Database dump.
	 * @return array
	 */
	private function extract_remote_users( array $database ): array {
		$tables         = isset( $database['tables'] ) && is_array( $database['tables'] ) ? $database['tables'] : array();
		$users_table    = $this->find_table_name( $tables, 'users' );
		$usermeta_table = $this->find_table_name( $tables, 'usermeta' );

		if ( ! $users_table || ! $usermeta_table ) {
			return array(
				'prefix'          => '',
				'users_table'     => $users_table,
				'usermeta_table'  => $usermeta_table,
				'users'           => array(),
				'meta_by_user_id' => array(),
			);
		}

		$prefix        = substr( $users_table, 0, -strlen( 'users' ) );
		$user_rows     = isset( $tables[ $users_table ]['rows'] ) && is_array( $tables[ $users_table ]['rows'] ) ? $tables[ $users_table ]['rows'] : array();
		$meta_rows     = isset( $tables[ $usermeta_table ]['rows'] ) && is_array( $tables[ $usermeta_table ]['rows'] ) ? $tables[ $usermeta_table ]['rows'] : array();
		$meta_by_user  = $this->group_meta_by_user( $meta_rows );
		$capabilities  = $prefix . 'capabilities';
		$remote_users  = array();

		foreach ( $user_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$email = isset( $row['user_email'] ) ? sanitize_email( $row['user_email'] ) : '';
			if ( ! $email ) {
				continue;
			}

			$user_id   = isset( $row['ID'] ) ? (int) $row['ID'] : 0;
			$roles     = $this->resolve_remote_roles( $meta_by_user[ $user_id ] ?? array(), $capabilities );
			$remote_users[ strtolower( $email ) ] = array(
				'email'       => $email,
				'login'       => isset( $row['user_login'] ) ? sanitize_text_field( (string) $row['user_login'] ) : '',
				'display'     => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : '',
				'role'        => $this->format_role_label( $roles ),
				'registered'  => isset( $row['user_registered'] ) ? sanitize_text_field( (string) $row['user_registered'] ) : '',
				'raw'         => $row,
				'meta'        => $meta_by_user[ $user_id ] ?? array(),
			);
		}

		return array(
			'prefix'          => $prefix,
			'users_table'     => $users_table,
			'usermeta_table'  => $usermeta_table,
			'users'           => $remote_users,
			'meta_by_user_id' => $meta_by_user,
		);
	}

	/**
	 * Group user meta rows by user ID.
	 *
	 * @param array $meta_rows Meta rows from dump.
	 * @return array
	 */
	private function group_meta_by_user( array $meta_rows ): array {
		$grouped = array();

		foreach ( $meta_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$user_id = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
			if ( $user_id <= 0 ) {
				continue;
			}

			$grouped[ $user_id ][] = array(
				'meta_key'   => isset( $row['meta_key'] ) ? sanitize_text_field( (string) $row['meta_key'] ) : '',
				'meta_value' => $row['meta_value'] ?? '',
			);
		}

		return $grouped;
	}

	/**
	 * Detect table name with suffix.
	 *
	 * @param array  $tables Table list.
	 * @param string $suffix Table suffix.
	 * @return string|null
	 */
	private function find_table_name( array $tables, string $suffix ): ?string {
		$suffix_length = strlen( $suffix );

		foreach ( array_keys( $tables ) as $name ) {
			$normalized = sanitize_text_field( (string) $name );
			if ( $suffix_length <= 0 || substr( $normalized, -$suffix_length ) === $suffix ) {
				return $normalized;
			}
		}

		return null;
	}

	/**
	 * Resolve remote role slugs from meta rows.
	 *
	 * @param array  $meta_rows Meta rows.
	 * @param string $capabilities_key Serialized capabilities key.
	 * @return array
	 */
	private function resolve_remote_roles( array $meta_rows, string $capabilities_key ): array {
		foreach ( $meta_rows as $row ) {
			if ( empty( $row['meta_key'] ) || $row['meta_key'] !== $capabilities_key ) {
				continue;
			}

			$value = maybe_unserialize( $row['meta_value'] ?? '' );
			if ( is_array( $value ) ) {
				return array_keys(
					array_filter(
						$value,
						static function ( $granted ) {
							return (bool) $granted;
						}
					)
				);
			}
		}

		return array();
	}

	/**
	 * Collect current site users.
	 *
	 * @return array
	 */
	private function collect_local_users(): array {
		$result = array();
		$users  = get_users(
			array(
				'fields' => array( 'ID', 'user_login', 'user_email', 'display_name', 'user_registered' ),
			)
		);

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			$email = sanitize_email( $user->user_email );
			if ( ! $email ) {
				continue;
			}

			$result[ strtolower( $email ) ] = array(
				'email'      => $email,
				'login'      => sanitize_text_field( $user->user_login ),
				'display'    => sanitize_text_field( $user->display_name ),
				'role'       => $this->format_role_label( $user->roles ?? array() ),
				'registered' => sanitize_text_field( $user->user_registered ),
			);
		}

		return $result;
	}

	/**
	 * Build combined list for UI.
	 *
	 * @param array $remote Remote users map.
	 * @param array $local  Local users map.
	 * @return array
	 */
	private function combine_users( array $remote, array $local ): array {
		$entries = array();

		foreach ( $remote['users'] as $email_key => $user ) {
			$local_match = $local[ $email_key ] ?? null;
			$status      = $local_match ? 'conflict' : 'new';

			$entries[] = array(
				'email'       => $user['email'],
				'login'       => $user['login'],
				'display'     => $user['display'],
				'role'        => $user['role'],
				'registered'  => $user['registered'],
				'status'      => $status,
				'local_role'  => $local_match['role'] ?? '',
				'local_since' => $local_match['registered'] ?? '',
			);
		}

		return $entries;
	}

	/**
	 * Count conflicts in incoming list.
	 *
	 * @param array $incoming Incoming users.
	 * @return int
	 */
	private function count_conflicts( array $incoming ): int {
		$total = 0;
		foreach ( $incoming as $entry ) {
			if ( 'conflict' === ( $entry['status'] ?? '' ) ) {
				$total++;
			}
		}

		return $total;
	}

	/**
	 * Convert role slugs to labels.
	 *
	 * @param array $roles Role slugs.
	 * @return string
	 */
	private function format_role_label( array $roles ): string {
		if ( empty( $roles ) ) {
			return __( 'Subscriber', 'mksddn-migrate-content' );
		}

		$slug  = reset( $roles );
		$roles = function_exists( 'wp_roles' ) ? wp_roles() : new WP_Roles();
		$name  = $roles->roles[ $slug ]['name'] ?? ucfirst( (string) $slug );

		return translate_user_role( $name );
	}
}


