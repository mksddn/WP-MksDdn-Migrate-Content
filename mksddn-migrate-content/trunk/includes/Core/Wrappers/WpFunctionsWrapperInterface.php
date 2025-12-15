<?php
/**
 * @file: WpFunctionsWrapperInterface.php
 * @description: Interface for WordPress functions wrapper
 * @dependencies: None
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
 * Interface for WordPress functions wrapper.
 *
 * @since 1.0.0
 */
interface WpFunctionsWrapperInterface {

	/**
	 * Insert a post.
	 *
	 * @param array $post_data Post data.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function insert_post( array $post_data ): int|WP_Error;

	/**
	 * Update a post.
	 *
	 * @param array $post_data Post data (must include ID).
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function update_post( array $post_data ): int|WP_Error;

	/**
	 * Delete a post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Whether to bypass trash.
	 * @return WP_Post|false|null Post object on success, false/null on failure.
	 * @since 1.0.0
	 */
	public function delete_post( int $post_id, bool $force = false ): WP_Post|false|null;

	/**
	 * Get posts.
	 *
	 * @param array $args Query arguments.
	 * @return array Array of WP_Post objects.
	 * @since 1.0.0
	 */
	public function get_posts( array $args = array() ): array;

	/**
	 * Get page by path.
	 *
	 * @param string $page_path Page path.
	 * @param string $output    Output type.
	 * @param string $post_type Post type.
	 * @return WP_Post|null Post object or null.
	 * @since 1.0.0
	 */
	public function get_page_by_path( string $page_path, string $output = OBJECT, string $post_type = 'page' ): ?WP_Post;

	/**
	 * Create WP_Query instance.
	 *
	 * @param array $args Query arguments.
	 * @return WP_Query Query instance.
	 * @since 1.0.0
	 */
	public function create_query( array $args ): WP_Query;

	/**
	 * Get post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (optional).
	 * @param bool   $single  Whether to return single value.
	 * @return mixed Meta value(s).
	 * @since 1.0.0
	 */
	public function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed;

	/**
	 * Update post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param mixed  $value   Meta value.
	 * @return int|false Meta ID on success, false on failure.
	 * @since 1.0.0
	 */
	public function update_post_meta( int $post_id, string $key, mixed $value ): int|false;

	/**
	 * Get post thumbnail ID.
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return int|false Thumbnail ID or false.
	 * @since 1.0.0
	 */
	public function get_post_thumbnail_id( int|WP_Post $post ): int|false;

	/**
	 * Get ACF fields.
	 *
	 * @param int|string $post_id Post ID.
	 * @return array Fields array.
	 * @since 1.0.0
	 */
	public function get_acf_fields( int|string $post_id ): array;
}

