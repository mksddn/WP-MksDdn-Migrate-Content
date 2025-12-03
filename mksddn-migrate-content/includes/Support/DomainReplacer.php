<?php
/**
 * Performs domain replacements inside exported database dumps.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search/replace helper that is safe for serialized data.
 */
class DomainReplacer {

	/**
	 * Replace domain references inside exported dump array.
	 *
	 * @param array  $dump        Database dump structure.
	 * @param string $target_base Target base URL (scheme + host + optional path).
	 */
	public function replace_dump_domains( array &$dump, string $target_base ): void {
		if ( empty( $dump['tables'] ) || ! is_array( $dump['tables'] ) ) {
			return;
		}

		$signatures = $this->collect_signatures( $dump );
		if ( empty( $signatures ) ) {
			return;
		}

		$target = untrailingslashit( $target_base );
		$map    = $this->build_replacement_map( $signatures, $target );

		if ( empty( $map ) ) {
			return;
		}

		foreach ( $dump['tables'] as &$table ) {
			if ( empty( $table['rows'] ) || ! is_array( $table['rows'] ) ) {
				continue;
			}

			foreach ( $table['rows'] as &$row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				foreach ( $row as $column => $value ) {
					$row[ $column ] = $this->replace_value( $value, $map );
				}
			}
		}
	}

	/**
	 * Gather old domain signatures from dump metadata.
	 *
	 * @param array $dump Database dump.
	 * @return array
	 */
	private function collect_signatures( array $dump ): array {
		$candidates = array(
			$dump['site_url'] ?? '',
			$dump['home_url'] ?? '',
		);

		$signatures = array();
		foreach ( $candidates as $url ) {
			if ( ! $url ) {
				continue;
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['host'] ) ) {
				continue;
			}

			$signature = $parts['host'];

			if ( ! empty( $parts['path'] ) ) {
				$signature .= '/' . trim( $parts['path'], '/' );
			}

			$signatures[] = trim( $signature, '/' );
		}

		return array_values( array_unique( array_filter( $signatures ) ) );
	}

	/**
	 * Build mapping of search => replacement strings.
	 *
	 * @param array  $signatures Old domains.
	 * @param string $target     Target base URL.
	 * @return array<string,string>
	 */
	private function build_replacement_map( array $signatures, string $target ): array {
		$map = array();

		foreach ( $signatures as $signature ) {
			foreach ( array( 'http', 'https' ) as $scheme ) {
				$base = $scheme . '://' . $signature;
				$map[ $base ]      = $target;
				$map[ $base . '/' ] = trailingslashit( $target );
			}
		}

		return $map;
	}

	/**
	 * Replace value (string or serialized payload).
	 *
	 * @param mixed $value Original value.
	 * @param array $map   Replacement map.
	 * @return mixed
	 */
	private function replace_value( $value, array $map ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( is_serialized( $value ) ) {
			$data    = maybe_unserialize( $value );
			$updated = $this->replace_recursive( $data, $map );
			return maybe_serialize( $updated );
		}

		return str_replace( array_keys( $map ), array_values( $map ), $value );
	}

	/**
	 * Recursively replace strings inside complex structures.
	 *
	 * @param mixed $data Payload.
	 * @param array $map  Replacement map.
	 * @return mixed
	 */
	private function replace_recursive( $data, array $map ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_recursive( $value, $map );
			}

			return $data;
		}

		if ( is_object( $data ) ) {
			foreach ( $data as $property => $value ) {
				$data->$property = $this->replace_recursive( $value, $map );
			}

			return $data;
		}

		if ( is_string( $data ) && '' !== $data ) {
			return str_replace( array_keys( $map ), array_values( $map ), $data );
		}

		return $data;
	}
}


