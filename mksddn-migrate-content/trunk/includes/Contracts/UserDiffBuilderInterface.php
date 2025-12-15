<?php
/**
 * @file: UserDiffBuilderInterface.php
 * @description: Contract for building user comparison data
 * @dependencies: None
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for building user comparison data.
 *
 * @since 1.0.0
 */
interface UserDiffBuilderInterface {

	/**
	 * Build diff data based on archive payload and current site users.
	 *
	 * @param string $archive_path Absolute archive path.
	 * @return array|WP_Error Diff data or error.
	 * @since 1.0.0
	 */
	public function build( string $archive_path );
}

