<?php
/**
 * Performs domain replacements inside exported database dumps.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search/replace helper that is safe for serialized data.
 */
class DomainReplacer {

	/**
	 * Replace environment references (domains, paths) inside exported dump array.
	 *
	 * @param array  $dump         Database dump structure.
	 * @param string $target_base  Target base URL (scheme + host + optional path).
	 * @param array  $target_paths Target filesystem paths.
	 */
	public function replace_dump_environment( array &$dump, string $target_base, array $target_paths ): void {
		if ( empty( $dump['tables'] ) || ! is_array( $dump['tables'] ) ) {
			return;
		}

		$domain_signatures = $this->collect_domain_signatures( $dump );
		$path_signatures   = $this->collect_path_signatures( $dump );
		$target            = $this->normalize_target_url( untrailingslashit( $target_base ) );

		$domain_map = $this->build_domain_map( $domain_signatures, $target );
		$path_map   = $this->build_path_map( $path_signatures, $target_paths );

		$combined_map = $domain_map + $path_map;

		if ( empty( $combined_map ) ) {
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
					$row[ $column ] = $this->replace_value( $value, $combined_map );
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
	private function collect_domain_signatures( array $dump ): array {
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

			// Build signature with host and optional port.
			$signature = $parts['host'];
			if ( ! empty( $parts['port'] ) ) {
				$signature .= ':' . $parts['port'];
			}

			if ( ! empty( $parts['path'] ) ) {
				$signature .= '/' . trim( $parts['path'], '/' );
			}

			$signatures[] = trim( $signature, '/' );

			// Also add signature without port for cases where port might not be present.
			if ( ! empty( $parts['port'] ) ) {
				$signature_no_port = $parts['host'];
				if ( ! empty( $parts['path'] ) ) {
					$signature_no_port .= '/' . trim( $parts['path'], '/' );
				}
				$signatures[] = trim( $signature_no_port, '/' );
			}
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
		return $this->build_domain_map( $signatures, $target );
	}

	/**
	 * Build search/replace map for domain signatures.
	 *
	 * @param array  $signatures Domain signatures.
	 * @param string $target     Target base.
	 * @return array
	 */
	private function build_domain_map( array $signatures, string $target ): array {
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
	 * Normalize target URL by removing port to ensure clean replacement.
	 *
	 * @param string $target_url Target URL.
	 * @return string Normalized URL without port.
	 */
	private function normalize_target_url( string $target_url ): string {
		$parts = wp_parse_url( $target_url );
		if ( empty( $parts['host'] ) ) {
			return $target_url;
		}

		// Always remove port from target URL to replace old URLs with ports.
		if ( ! empty( $parts['port'] ) ) {
			unset( $parts['port'] );
		}

		// Rebuild URL without port.
		$normalized = '';
		if ( ! empty( $parts['scheme'] ) ) {
			$normalized .= $parts['scheme'] . '://';
		}
		$normalized .= $parts['host'];
		if ( ! empty( $parts['path'] ) ) {
			$normalized .= $parts['path'];
		}
		if ( ! empty( $parts['query'] ) ) {
			$normalized .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$normalized .= '#' . $parts['fragment'];
		}

		return $normalized;
	}

	/**
	 * Collect old filesystem paths from dump metadata.
	 *
	 * @param array $dump Dump data.
	 * @return array<string,string>
	 */
	private function collect_path_signatures( array $dump ): array {
		$paths = array();
		if ( empty( $dump['paths'] ) || ! is_array( $dump['paths'] ) ) {
			return $paths;
		}

		foreach ( $dump['paths'] as $key => $path ) {
			if ( empty( $path ) || ! is_string( $path ) ) {
				continue;
			}

			$paths[ $key ] = untrailingslashit( $path );
		}

		return $paths;
	}

	/**
	 * Build replacement map for filesystem paths.
	 *
	 * @param array $old_paths    Old path signatures.
	 * @param array $target_paths Target path values.
	 * @return array
	 */
	private function build_path_map( array $old_paths, array $target_paths ): array {
		$map = array();

		foreach ( $old_paths as $key => $old_path ) {
			if ( empty( $old_path ) ) {
				continue;
			}

			$new_path = isset( $target_paths[ $key ] ) ? untrailingslashit( (string) $target_paths[ $key ] ) : '';

			if ( '' === $new_path ) {
				continue;
			}

			$map[ $old_path ]       = $new_path;
			$map[ $old_path . '/' ] = trailingslashit( $new_path );
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
			$data = @unserialize( trim( $value ) );

			if ( false === $data && 'b:0;' !== $value ) {
				return str_replace( array_keys( $map ), array_values( $map ), $value );
			}

			if ( $data instanceof \__PHP_Incomplete_Class ) {
				return str_replace( array_keys( $map ), array_values( $map ), $value );
			}

			$updated = $this->replace_recursive( $data, $map );
			return serialize( $updated );
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
			if ( $data instanceof \__PHP_Incomplete_Class ) {
				return $data;
			}

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


