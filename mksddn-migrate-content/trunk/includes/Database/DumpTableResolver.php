<?php
/**
 * @file: DumpTableResolver.php
 * @description: Resolves WordPress core user tables inside a database dump without matching foreign prefixes
 * @dependencies: none
 * @created: 2026-10-28
 */

namespace MksDdn\MigrateContent\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Picks the real `{prefix}users` / `{prefix}usermeta` tables from a dump.
 *
 * A dump may contain unrelated tables sharing the suffix (for example Plugin Check
 * runtime tables `wppc_users`), so a plain "first table ending with users" is unsafe.
 *
 * @since 1.0.0
 */
class DumpTableResolver {

	/**
	 * Core table suffixes used to score candidate prefixes.
	 *
	 * @var string[]
	 */
	private const CORE_SUFFIXES = array( 'users', 'usermeta', 'posts', 'options', 'terms', 'term_taxonomy' );

	/**
	 * Find a table by suffix, preferring the dump's own table prefix.
	 *
	 * @param array<int, string> $table_names      Table names present in the dump.
	 * @param string             $suffix           Table suffix (e.g. 'users', 'usermeta').
	 * @param string             $preferred_prefix Prefix recorded in the dump (may be empty).
	 * @return string|null Table name or null when absent.
	 * @since 1.0.0
	 */
	public static function find( array $table_names, string $suffix, string $preferred_prefix = '' ): ?string {
		$prefix = self::resolve_prefix( $table_names, $preferred_prefix );

		if ( null !== $prefix && in_array( $prefix . $suffix, $table_names, true ) ) {
			return $prefix . $suffix;
		}

		// Legacy fallback: only when the dump prefix is unknown (no preferred, scoring failed).
		// Never fall back when a preferred prefix was supplied — that would re-select foreign tables.
		if ( null === $prefix && '' === $preferred_prefix ) {
			foreach ( $table_names as $name ) {
				if ( substr( $name, -strlen( $suffix ) ) === $suffix ) {
					return $name;
				}
			}
		}

		return null;
	}

	/**
	 * Determine the core table prefix of a dump.
	 *
	 * When `$preferred_prefix` is non-empty it is trusted as dump metadata and returned as-is.
	 * Callers that need a concrete table must still check that `{prefix}{suffix}` exists.
	 *
	 * @param array<int, string> $table_names      Table names present in the dump.
	 * @param string             $preferred_prefix Prefix recorded in the dump (may be empty).
	 * @return string|null Prefix or null when it cannot be determined.
	 * @since 1.0.0
	 */
	public static function resolve_prefix( array $table_names, string $preferred_prefix = '' ): ?string {
		if ( '' !== $preferred_prefix ) {
			return $preferred_prefix;
		}

		$best_prefix = null;
		$best_score  = 0;
		$seen        = array();

		foreach ( $table_names as $name ) {
			foreach ( self::CORE_SUFFIXES as $core ) {
				$core_length = strlen( $core );
				if ( substr( $name, -$core_length ) !== $core ) {
					continue;
				}

				$candidate = substr( $name, 0, -$core_length );
				if ( isset( $seen[ $candidate ] ) ) {
					continue;
				}
				$seen[ $candidate ] = true;

				$score = 0;
				foreach ( self::CORE_SUFFIXES as $test ) {
					if ( in_array( $candidate . $test, $table_names, true ) ) {
						++$score;
					}
				}

				// Higher score wins; on a tie prefer the shorter prefix.
				if ( $score > $best_score || ( $score === $best_score && null !== $best_prefix && strlen( $candidate ) < strlen( $best_prefix ) ) ) {
					$best_score  = $score;
					$best_prefix = $candidate;
				}
			}
		}

		return $best_score >= 3 ? $best_prefix : null;
	}
}
