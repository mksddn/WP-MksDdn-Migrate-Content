<?php
/**
 * @file: WpFunctionsWrapper.php
 * @description: Wrapper for WordPress post and query functions
 * @dependencies: WpFunctionsWrapperInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\Wrappers;

use WP_Error;
use WP_Post;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wrapper for WordPress post and query functions.
 *
 * @since 1.0.0
 */
class WpFunctionsWrapper implements WpFunctionsWrapperInterface {

	/**
	 * Insert a post.
	 *
	 * @param array $post_data Post data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function insert_post( array $post_data ): int|WP_Error {
		return wp_insert_post( $post_data, true );
	}

	/**
	 * Update a post.
	 *
	 * @param array $post_data Post data (must include ID).
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function update_post( array $post_data ): int|WP_Error {
		return wp_update_post( $post_data, true );
	}

	/**
	 * Delete a post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Whether to bypass trash.
	 * @return WP_Post|false|null Post object on success, false/null on failure.
	 * @since 1.0.0
	 */
	public function delete_post( int $post_id, bool $force = false ): WP_Post|false|null {
		return wp_delete_post( $post_id, $force );
	}

	/**
	 * Get posts.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of WP_Post objects.
	 * @since 1.0.0
	 */
	public function get_posts( array $args = array() ): array {
		return get_posts( $args );
	}

	/**
	 * Get page by path.
	 *
	 * @param string $page_path Page path.
	 * @param string $output    Output type.
	 * @param string $post_type Post type.
	 * @return WP_Post|null Post object or null.
	 * @since 1.0.0
	 */
	public function get_page_by_path( string $page_path, string $output = OBJECT, string $post_type = 'page' ): ?WP_Post {
		$page = get_page_by_path( $page_path, $output, $post_type );
		return $page ?: null;
	}

	/**
	 * Create WP_Query instance.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Query Query instance.
	 * @since 1.0.0
	 */
	public function create_query( array $args ): WP_Query {
		return new WP_Query( $args );
	}

	/**
	 * Get post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (optional).
	 * @param bool   $single  Whether to return single value.
	 * @return mixed Meta value(s).
	 * @since 1.0.0
	 */
	public function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return get_post_meta( $post_id, $key, $single );
	}

	/**
	 * Update post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return int|false Meta ID on success, false on failure.
	 * @since 1.0.0
	 */
	public function update_post_meta( int $post_id, string $key, mixed $value ): int|false {
		return update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Get post thumbnail ID.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return int|false Thumbnail ID or false.
	 * @since 1.0.0
	 */
	public function get_post_thumbnail_id( int|WP_Post $post ): int|false {
		return get_post_thumbnail_id( $post );
	}

	/**
	 * Get ACF fields.
	 *
	 * @param int|string $post_id Post ID.
	 * @return array Fields array.
	 * @since 1.0.0
	 */
	public function get_acf_fields( int|string $post_id ): array {
		if ( ! function_exists( 'get_fields' ) ) {
			return array();
		}

		$fields = get_fields( $post_id );
		return is_array( $fields ) ? $fields : array();
	}
}

