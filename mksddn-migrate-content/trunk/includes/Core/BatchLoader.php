<?php
/**
 * @file: BatchLoader.php
 * @description: Batch loader for optimizing database queries and avoiding N+1 problems
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Batch loader for optimizing database queries.
 *
 * @since 1.0.0
 */
class BatchLoader {

	/**
	 * Cache for post meta data.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $meta_cache = array();

	/**
	 * Cache for post thumbnails.
	 *
	 * @var array<int, int|false>
	 */
	private array $thumbnail_cache = array();

	/**
	 * Cache for taxonomy terms.
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $terms_cache = array();

	/**
	 * Cache for ACF fields.
	 *
	 * @var array<int, array>
	 */
	private array $acf_cache = array();

	/**
	 * Cache for attachment posts.
	 *
	 * @var array<int, \WP_Post|null>
	 */
	private array $attachment_cache = array();

	/**
	 * Load post meta for multiple posts in batch.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return void
	 * @since 1.0.0
	 */
	public function load_post_meta_batch( array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$post_ids = array_unique( $post_ids );
		$to_load  = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! isset( $this->meta_cache[ $post_id ] ) ) {
				$to_load[] = $post_id;
			}
		}

		if ( empty( $to_load ) ) {
			return;
		}

		global $wpdb;
		$ids_escaped = array_map( 'absint', $to_load );
		$ids_string  = implode( ',', $ids_escaped );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Batch loading with internal caching, IDs are sanitized via absint().
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized via absint() before interpolation.
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids_string)",
			ARRAY_A
		);

		foreach ( $to_load as $post_id ) {
			$this->meta_cache[ $post_id ] = array();
		}

		foreach ( $results as $row ) {
			$post_id = (int) $row['post_id'];
			if ( ! isset( $this->meta_cache[ $post_id ] ) ) {
				$this->meta_cache[ $post_id ] = array();
			}
			$this->meta_cache[ $post_id ][ $row['meta_key'] ] = maybe_unserialize( $row['meta_value'] );
		}
	}

	/**
	 * Get post meta for a post (from cache or load).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed> Meta data.
	 * @since 1.0.0
	 */
	public function get_post_meta( int $post_id ): array {
		if ( ! isset( $this->meta_cache[ $post_id ] ) ) {
			$this->load_post_meta_batch( array( $post_id ) );
		}

		return $this->meta_cache[ $post_id ] ?? array();
	}

	/**
	 * Load post thumbnails for multiple posts in batch.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return void
	 * @since 1.0.0
	 */
	public function load_thumbnails_batch( array $post_ids ): void {
		if ( empty( $post_ids ) ) {
			return;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$post_ids = array_unique( $post_ids );
		$to_load  = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! isset( $this->thumbnail_cache[ $post_id ] ) ) {
				$to_load[] = $post_id;
			}
		}

		if ( empty( $to_load ) ) {
			return;
		}

		global $wpdb;
		$ids_escaped = array_map( 'absint', $to_load );
		$ids_string  = implode( ',', $ids_escaped );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Batch loading with internal caching, IDs are sanitized via absint().
		$results = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- IDs are sanitized via absint() before interpolation.
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($ids_string) AND meta_key = '_thumbnail_id'",
			ARRAY_A
		);

		foreach ( $to_load as $post_id ) {
			$this->thumbnail_cache[ $post_id ] = false;
		}

		foreach ( $results as $row ) {
			$post_id = (int) $row['post_id'];
			$this->thumbnail_cache[ $post_id ] = (int) $row['meta_value'];
		}
	}

	/**
	 * Get post thumbnail ID (from cache or load).
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Thumbnail ID or false.
	 * @since 1.0.0
	 */
	public function get_post_thumbnail_id( int $post_id ): int|false {
		if ( ! isset( $this->thumbnail_cache[ $post_id ] ) ) {
			$this->load_thumbnails_batch( array( $post_id ) );
		}

		return $this->thumbnail_cache[ $post_id ] ?? false;
	}

	/**
	 * Load taxonomy terms for multiple posts in batch.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @param string    $taxonomy  Taxonomy name.
	 * @return void
	 * @since 1.0.0
	 */
	public function load_terms_batch( array $post_ids, string $taxonomy ): void {
		if ( empty( $post_ids ) || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$post_ids = array_unique( $post_ids );
		$cache_key = $taxonomy;

		if ( ! isset( $this->terms_cache[ $cache_key ] ) ) {
			$this->terms_cache[ $cache_key ] = array();
		}

		$to_load = array();
		foreach ( $post_ids as $post_id ) {
			if ( ! isset( $this->terms_cache[ $cache_key ][ $post_id ] ) ) {
				$to_load[] = $post_id;
			}
		}

		if ( empty( $to_load ) ) {
			return;
		}

		$terms = wp_get_object_terms( $to_load, $taxonomy, array( 'fields' => 'all_with_object_id' ) );

		if ( is_wp_error( $terms ) ) {
			foreach ( $to_load as $post_id ) {
				$this->terms_cache[ $cache_key ][ $post_id ] = array();
			}
			return;
		}

		foreach ( $to_load as $post_id ) {
			$this->terms_cache[ $cache_key ][ $post_id ] = array();
		}

		foreach ( $terms as $term ) {
			$post_id = (int) $term->object_id;
			if ( ! isset( $this->terms_cache[ $cache_key ][ $post_id ] ) ) {
				$this->terms_cache[ $cache_key ][ $post_id ] = array();
			}
			$this->terms_cache[ $cache_key ][ $post_id ][] = array(
				'slug'        => $term->slug,
				'name'        => $term->name,
				'description' => $term->description,
			);
		}
	}

	/**
	 * Get taxonomy terms for a post (from cache or load).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, array> Terms data.
	 * @since 1.0.0
	 */
	public function get_terms( int $post_id, string $taxonomy ): array {
		$cache_key = $taxonomy;
		if ( ! isset( $this->terms_cache[ $cache_key ][ $post_id ] ) ) {
			$this->load_terms_batch( array( $post_id ), $taxonomy );
		}

		return $this->terms_cache[ $cache_key ][ $post_id ] ?? array();
	}

	/**
	 * Load ACF fields for multiple posts in batch.
	 *
	 * @param array<int> $post_ids Post IDs.
	 * @return void
	 * @since 1.0.0
	 */
	public function load_acf_fields_batch( array $post_ids ): void {
		if ( ! function_exists( 'get_fields' ) || empty( $post_ids ) ) {
			return;
		}

		$post_ids = array_map( 'absint', $post_ids );
		$post_ids = array_unique( $post_ids );
		$to_load  = array();

		foreach ( $post_ids as $post_id ) {
			if ( ! isset( $this->acf_cache[ $post_id ] ) ) {
				$to_load[] = $post_id;
			}
		}

		if ( empty( $to_load ) ) {
			return;
		}

		foreach ( $to_load as $post_id ) {
			$fields = array();

			// Try get_fields() first (ACF API method).
			if ( function_exists( 'get_fields' ) ) {
				$fields_raw = get_fields( $post_id, false );
				// get_fields() can return false or null, ensure we always have an array.
				if ( is_array( $fields_raw ) ) {
					$fields = $fields_raw;
				}
			}

			// Fallback: get ACF fields from field objects if get_fields() didn't work.
			if ( empty( $fields ) && function_exists( 'get_field_objects' ) ) {
				$field_objects = get_field_objects( $post_id, false );
				if ( is_array( $field_objects ) ) {
					foreach ( $field_objects as $field_name => $field_object ) {
						if ( isset( $field_object['value'] ) ) {
							$fields[ $field_name ] = $field_object['value'];
						}
					}
				}
			}

			// Normalize link fields in repeaters: convert field_key structure to field name structure.
			$fields = $this->normalize_acf_fields( $fields, $post_id );

			$this->acf_cache[ $post_id ] = $fields;
		}
	}

	/**
	 * Get ACF fields for a post (from cache or load).
	 *
	 * @param int $post_id Post ID.
	 * @return array ACF fields.
	 * @since 1.0.0
	 */
	public function get_acf_fields( int $post_id ): array {
		if ( ! isset( $this->acf_cache[ $post_id ] ) ) {
			$this->load_acf_fields_batch( array( $post_id ) );
		}

		return $this->acf_cache[ $post_id ] ?? array();
	}

	/**
	 * Load attachment posts in batch.
	 *
	 * @param array<int> $attachment_ids Attachment IDs.
	 * @return void
	 * @since 1.0.0
	 */
	public function load_attachments_batch( array $attachment_ids ): void {
		if ( empty( $attachment_ids ) ) {
			return;
		}

		$attachment_ids = array_map( 'absint', $attachment_ids );
		$attachment_ids = array_unique( $attachment_ids );
		$to_load        = array();

		foreach ( $attachment_ids as $attachment_id ) {
			if ( ! isset( $this->attachment_cache[ $attachment_id ] ) ) {
				$to_load[] = $attachment_id;
			}
		}

		if ( empty( $to_load ) ) {
			return;
		}

		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'post__in'       => $to_load,
				'posts_per_page' => -1,
				'post_status'    => 'inherit',
			)
		);

		foreach ( $to_load as $attachment_id ) {
			$this->attachment_cache[ $attachment_id ] = null;
		}

		foreach ( $attachments as $attachment ) {
			$this->attachment_cache[ $attachment->ID ] = $attachment;
		}
	}

	/**
	 * Get attachment post (from cache or load).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return \WP_Post|null Attachment post or null.
	 * @since 1.0.0
	 */
	public function get_attachment( int $attachment_id ): ?\WP_Post {
		if ( ! isset( $this->attachment_cache[ $attachment_id ] ) ) {
			$this->load_attachments_batch( array( $attachment_id ) );
		}

		return $this->attachment_cache[ $attachment_id ] ?? null;
	}

	/**
	 * Normalize ACF fields structure, especially for link fields in repeaters.
	 * Converts field_key-based structures to field name-based structures where possible.
	 *
	 * @param array $fields ACF fields array.
	 * @param int   $post_id Post ID.
	 * @return array Normalized fields array.
	 * @since 1.0.0
	 */
	private function normalize_acf_fields( array $fields, int $post_id ): array {
		if ( ! function_exists( 'get_field_object' ) ) {
			return $fields;
		}

		$normalized = array();
		foreach ( $fields as $field_name => $field_value ) {
			$field_object = get_field_object( $field_name, $post_id, false, true );
			if ( false === $field_object ) {
				$field_object = null;
			}

			// Normalize repeater fields that may contain link fields with field_key structure.
			if ( $field_object && 'repeater' === ( $field_object['type'] ?? null ) && is_array( $field_value ) ) {
				$normalized[ $field_name ] = $this->normalize_repeater_field( $field_value, $field_object, $post_id );
			} elseif ( $field_object && 'group' === ( $field_object['type'] ?? null ) && is_array( $field_value ) ) {
				// Normalize group fields that may contain repeaters with link fields.
				$normalized[ $field_name ] = $this->normalize_group_field( $field_value, $field_object, $post_id );
			} else {
				$normalized[ $field_name ] = $field_value;
			}
		}

		return $normalized;
	}

	/**
	 * Normalize repeater field structure, especially for link fields.
	 *
	 * @param array      $repeater_value Repeater field value.
	 * @param array|null $repeater_object Repeater field object.
	 * @param int        $post_id Post ID.
	 * @return array Normalized repeater value.
	 */
	private function normalize_repeater_field( array $repeater_value, ?array $repeater_object, int $post_id ): array {
		if ( ! $repeater_object || ! isset( $repeater_object['sub_fields'] ) ) {
			return $repeater_value;
		}

		$normalized = array();
		foreach ( $repeater_value as $row_index => $row_data ) {
			if ( ! is_array( $row_data ) ) {
				$normalized[ $row_index ] = $row_data;
				continue;
			}

			$normalized_row = array();
			foreach ( $row_data as $sub_field_key => $sub_field_value ) {
				// Find sub-field definition by key or name.
				$sub_field_def = null;
				$sub_field_name = null;
				foreach ( $repeater_object['sub_fields'] as $def ) {
					if ( ( $def['key'] ?? null ) === $sub_field_key || ( $def['name'] ?? null ) === $sub_field_key ) {
						$sub_field_def = $def;
						$sub_field_name = $def['name'] ?? $sub_field_key;
						break;
					}
				}

				// For link fields, ensure proper structure and use field name instead of key.
				if ( $sub_field_def && 'link' === ( $sub_field_def['type'] ?? null ) && is_array( $sub_field_value ) ) {
					// Use field name instead of field key.
					$normalized_row[ $sub_field_name ] = $sub_field_value;
				} else {
					// For other fields, use the key/name as found.
					$normalized_row[ $sub_field_name ?? $sub_field_key ] = $sub_field_value;
				}
			}
			$normalized[ $row_index ] = $normalized_row;
		}

		return array_values( $normalized );
	}

	/**
	 * Normalize group field structure, recursively handling nested repeaters.
	 *
	 * @param array      $group_value Group field value.
	 * @param array|null $group_object Group field object.
	 * @param int        $post_id Post ID.
	 * @return array Normalized group value.
	 */
	private function normalize_group_field( array $group_value, ?array $group_object, int $post_id ): array {
		if ( ! $group_object || ! isset( $group_object['sub_fields'] ) ) {
			return $group_value;
		}

		$normalized = array();
		foreach ( $group_value as $sub_field_key => $sub_field_value ) {
			// Find sub-field definition by key or name.
			$sub_field_def = null;
			$sub_field_name = null;
			foreach ( $group_object['sub_fields'] as $def ) {
				if ( ( $def['key'] ?? null ) === $sub_field_key || ( $def['name'] ?? null ) === $sub_field_key ) {
					$sub_field_def = $def;
					$sub_field_name = $def['name'] ?? $sub_field_key;
					break;
				}
			}

			// Recursively normalize repeater fields within groups.
			if ( $sub_field_def && 'repeater' === ( $sub_field_def['type'] ?? null ) && is_array( $sub_field_value ) ) {
				$normalized[ $sub_field_name ] = $this->normalize_repeater_field( $sub_field_value, $sub_field_def, $post_id );
			} elseif ( $sub_field_def && 'group' === ( $sub_field_def['type'] ?? null ) && is_array( $sub_field_value ) ) {
				// Recursively normalize nested groups.
				$normalized[ $sub_field_name ] = $this->normalize_group_field( $sub_field_value, $sub_field_def, $post_id );
			} else {
				$normalized[ $sub_field_name ?? $sub_field_key ] = $sub_field_value;
			}
		}

		return $normalized;
	}

	/**
	 * Clear all caches.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function clear_cache(): void {
		$this->meta_cache       = array();
		$this->thumbnail_cache   = array();
		$this->terms_cache      = array();
		$this->acf_cache        = array();
		$this->attachment_cache = array();
	}
}

