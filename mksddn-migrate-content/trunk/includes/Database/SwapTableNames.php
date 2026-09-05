<?php
/**
 * @file: SwapTableNames.php
 * @description: Detects internal table names used while recreating tables during import
 * @dependencies: none
 * @created: 2026-09-05
 */

namespace MksDdn\MigrateContent\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names of temporary tables created during full-site schema recreate.
 *
 * `_mkn` + 8 hex = new empty table (safe to drop).
 * `_mko` + 8 hex = original table moved aside (may hold the only remaining data).
 *
 * @since 2.7.1
 */
final class SwapTableNames {

	/**
	 * Legacy backup suffix from the RENAME-first recreate path.
	 */
	public const LEGACY_BACKUP_SUFFIX = '_mksddn_bak';

	/**
	 * Whether the table is an import swap leftover (current or legacy).
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public static function is_internal( string $table_name ): bool {
		return (bool) preg_match( '/_(?:mk[no][0-9a-f]{8}|mksddn_bak)$/i', $table_name );
	}

	/**
	 * Whether the table is a new empty swap table (`_mkn`).
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public static function is_new_swap( string $table_name ): bool {
		return (bool) preg_match( '/_mkn[0-9a-f]{8}$/i', $table_name );
	}

	/**
	 * Whether the table is an original moved aside (`_mko` or legacy `_mksddn_bak`).
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public static function is_old_copy( string $table_name ): bool {
		return self::is_legacy_backup( $table_name ) || (bool) preg_match( '/_mko[0-9a-f]{8}$/i', $table_name );
	}

	/**
	 * Whether the table uses the legacy `_mksddn_bak` suffix.
	 *
	 * @param string $table_name Table name.
	 * @return bool
	 */
	public static function is_legacy_backup( string $table_name ): bool {
		$suffix = self::LEGACY_BACKUP_SUFFIX;
		$len    = strlen( $suffix );

		return strlen( $table_name ) > $len && 0 === substr_compare( $table_name, $suffix, -$len, $len, true );
	}

	/**
	 * Original table name when the swap name was not truncated to 64 characters.
	 *
	 * @param string $swap_name Swap or legacy backup table name.
	 * @return string Empty when the original name cannot be recovered safely.
	 */
	public static function recoverable_original_name( string $swap_name ): string {
		if ( 64 === strlen( $swap_name ) ) {
			return '';
		}

		if ( preg_match( '/_mk[no][0-9a-f]{8}$/i', $swap_name ) ) {
			return substr( $swap_name, 0, -12 );
		}

		if ( self::is_legacy_backup( $swap_name ) ) {
			return substr( $swap_name, 0, -strlen( self::LEGACY_BACKUP_SUFFIX ) );
		}

		return '';
	}
}
