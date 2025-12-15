<?php
/**
 * @file: ServiceContainerFactory.php
 * @description: Factory for creating and configuring the service container
 * @dependencies: Core\ServiceContainer, Core\ServiceProviders\*
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core;

use MksDdn\MigrateContent\Chunking\ChunkServiceProvider;
use MksDdn\MigrateContent\Core\ServiceProviders\AdminServiceProvider;
use MksDdn\MigrateContent\Core\ServiceProviders\CoreServiceProvider;
use MksDdn\MigrateContent\Core\ServiceProviders\ExportServiceProvider;
use MksDdn\MigrateContent\Core\ServiceProviders\ImportServiceProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Factory for creating and configuring the service container.
 *
 * @since 1.0.0
 */
class ServiceContainerFactory {

	/**
	 * Create and configure service container.
	 *
	 * @return ServiceContainer Configured container.
	 * @since 1.0.0
	 */
	public static function create(): ServiceContainer {
		$container = new ServiceContainer();

		// Register service providers.
		$providers = array(
			new CoreServiceProvider(),
			new AdminServiceProvider(),
			new ExportServiceProvider(),
			new ImportServiceProvider(),
			new ChunkServiceProvider(),
		);

		foreach ( $providers as $provider ) {
			$provider->register( $container );
		}

		/**
		 * Allow plugins to register additional services.
		 *
		 * @param ServiceContainer $container Service container.
		 * @since 1.0.0
		 */
		do_action( 'mksddn_mc_service_container_ready', $container );

		return $container;
	}
}

