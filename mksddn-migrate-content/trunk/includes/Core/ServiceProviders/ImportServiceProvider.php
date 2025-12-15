<?php
/**
 * @file: ImportServiceProvider.php
 * @description: Service provider for import-related services
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\ServiceProviders;

use MksDdn\MigrateContent\Contracts\ImporterInterface;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Core\ServiceProviderInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapperInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapperInterface;
use MksDdn\MigrateContent\Import\ImportHandler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service provider for import-related services.
 *
 * @since 1.0.0
 */
class ImportServiceProvider implements ServiceProviderInterface {

	/**
	 * Register import services.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void {
		$container->register(
			ImporterInterface::class,
			function ( ServiceContainer $container ) {
				return new ImportHandler(
					null,
					null,
					$container->get( WpFunctionsWrapperInterface::class ),
					$container->get( WpUserFunctionsWrapperInterface::class )
				);
			}
		);
		$container->register(
			ImportHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( ImporterInterface::class );
			}
		);
	}
}

