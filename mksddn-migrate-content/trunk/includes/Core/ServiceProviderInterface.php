<?php
/**
 * @file: ServiceProviderInterface.php
 * @description: Interface for service providers that register services in the container
 * @dependencies: Core\ServiceContainer
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for service providers.
 *
 * @since 1.0.0
 */
interface ServiceProviderInterface {

	/**
	 * Register services in the container.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void;
}

