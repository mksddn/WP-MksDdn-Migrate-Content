<?php
/**
 * @file: ImporterInterface.php
 * @description: Contract for import operations
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for import operations.
 *
 * @since 1.0.0
 */
interface ImporterInterface {

	/**
	 * Import data bundle.
	 *
	 * @param array $data Import data bundle.
	 * @return bool|WP_Error True on success, false or WP_Error on failure.
	 * @since 1.0.0
	 */
	public function import_bundle( array $data ): bool|WP_Error;
}

