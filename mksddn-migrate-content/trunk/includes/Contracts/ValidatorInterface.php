<?php
/**
 * @file: ValidatorInterface.php
 * @description: Contract for validation operations
 * @dependencies: None
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for validation operations.
 *
 * @since 1.0.0
 */
interface ValidatorInterface {

	/**
	 * Validate data.
	 *
	 * @param mixed  $data Data to validate.
	 * @param string $type Validation type.
	 * @return bool True if valid, false otherwise.
	 * @since 1.0.0
	 */
	public function validate( mixed $data, string $type ): bool;
}

