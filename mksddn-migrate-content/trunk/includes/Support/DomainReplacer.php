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
		$target            = untrailingslashit( $target_base );

		$domain_map = $this->build_domain_map( $domain_signatures, $target );
		$path_map   = $this->build_path_map( $path_signatures, $target_paths );

		$combined_map = $domain_map + $path_map;

		if ( empty( $combined_map ) ) {
			return;
		}

		$table_count = count( $dump['tables'] );
		$processed   = 0;

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

			++$processed;

			// Force garbage collection after every 10 tables to prevent memory buildup.
			if ( $processed % 10 === 0 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Final cleanup after all tables processed.
		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}
	}

	/**
	 * Gather old domain signatures from dump metadata.
	 *
	 * @param array $dump Database dump.
	 * @return array Array of signature data with host, port, and path info.
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

			$host = $parts['host'];
			$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'http';
			$path = ! empty( $parts['path'] ) ? '/' . trim( $parts['path'], '/' ) : '';

			// Store signature data with port info.
			$signature_data = array(
				'host'   => $host,
				'port'   => $port,
				'scheme' => $scheme,
				'path'   => $path,
			);

			$signatures[] = $signature_data;
		}

		return $signatures;
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
	 * @param array  $signatures Array of signature data.
	 * @param string $target     Target base.
	 * @return array
	 */
	private function build_domain_map( array $signatures, string $target ): array {
		$map = array();

		// Normalize target URL - remove standard ports.
		$target_parts = wp_parse_url( $target );
		$target_scheme = isset( $target_parts['scheme'] ) ? $target_parts['scheme'] : 'https';
		$target_host = isset( $target_parts['host'] ) ? $target_parts['host'] : '';
		$target_port = isset( $target_parts['port'] ) ? (int) $target_parts['port'] : null;
		$target_path = isset( $target_parts['path'] ) ? $target_parts['path'] : '';

		// Build normalized target without port (if standard).
		$normalized_target = $target_scheme . '://' . $target_host;
		if ( null !== $target_port && ! $this->is_standard_port( $target_port, $target_scheme ) ) {
			$normalized_target .= ':' . $target_port;
		}
		$normalized_target .= $target_path;
		$normalized_target = untrailingslashit( $normalized_target );

		foreach ( $signatures as $sig_data ) {
			$host = $sig_data['host'];
			$port = $sig_data['port'];
			$path = $sig_data['path'];

			foreach ( array( 'http', 'https' ) as $scheme ) {
				// Build URL with port if port exists and is non-standard.
				$host_with_port = $host;
				if ( null !== $port && ! $this->is_standard_port( $port, $scheme ) ) {
					$host_with_port .= ':' . $port;
				}

				// URL with port (if non-standard).
				$base_with_port = $scheme . '://' . $host_with_port . $path;
				$map[ $base_with_port ] = $normalized_target;
				$map[ $base_with_port . '/' ] = trailingslashit( $normalized_target );

				// Also create mapping for URL without port (in case it's stored differently).
				if ( null !== $port && ! $this->is_standard_port( $port, $scheme ) ) {
					$base_no_port = $scheme . '://' . $host . $path;
					$map[ $base_no_port ] = $normalized_target;
					$map[ $base_no_port . '/' ] = trailingslashit( $normalized_target );
				}
			}
		}

		return $map;
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
			$result  = serialize( $updated );
			
			// Free memory immediately after serialization.
			unset( $data, $updated );
			
			return $result;
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

	/**
	 * Check if port is standard for given scheme.
	 *
	 * @param int    $port   Port number.
	 * @param string $scheme URL scheme (http or https).
	 * @return bool True if port is standard, false otherwise.
	 */
	private function is_standard_port( int $port, string $scheme ): bool {
		if ( 'https' === $scheme && 443 === $port ) {
			return true;
		}
		if ( 'http' === $scheme && 80 === $port ) {
			return true;
		}

		return false;
	}
}


