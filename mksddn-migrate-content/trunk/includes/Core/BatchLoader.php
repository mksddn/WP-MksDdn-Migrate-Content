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
		$placeholders = implode( ',', array_fill( 0, count( $to_load ), '%d' ) );
		$query = $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
			...$to_load
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch loading with internal caching.
		$results = $wpdb->get_results( $query, ARRAY_A );

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
		$placeholders = implode( ',', array_fill( 0, count( $to_load ), '%d' ) );
		$query = $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders) AND meta_key = '_thumbnail_id'",
			...$to_load
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Batch loading with internal caching.
		$results = $wpdb->get_results( $query, ARRAY_A );

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
			$fields = get_fields( $post_id );
			$this->acf_cache[ $post_id ] = is_array( $fields ) ? $fields : array();
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

