<?php
/**
 * @file: WpUserFunctionsWrapperInterface.php
 * @description: Interface for WordPress user functions wrapper
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\Wrappers;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for WordPress user functions wrapper.
 *
 * @since 1.0.0
 */
interface WpUserFunctionsWrapperInterface {

	/**
	 * Insert a user.
	 *
	 * @param array $userdata User data.
	 * @return int|WP_Error User ID on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function insert_user( array $userdata ): int|WP_Error;

	/**
	 * Check if username exists.
	 *
	 * @param string $username Username to check.
	 * @return int|false User ID if exists, false otherwise.
	 * @since 1.0.0
	 */
	public function username_exists( string $username ): int|false;

	/**
	 * Get current user ID.
	 *
	 * @return int User ID.
	 * @since 1.0.0
	 */
	public function get_current_user_id(): int;
}

