<?php
/**
 * @file: ExportServiceProvider.php
 * @description: Service provider for export-related services
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\ServiceProviders;

use MksDdn\MigrateContent\Core\BatchLoader;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Core\ServiceProviderInterface;
use MksDdn\MigrateContent\Export\ExportHandler;
use MksDdn\MigrateContent\Media\AttachmentCollector;
use MksDdn\MigrateContent\Options\OptionsExporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service provider for export-related services.
 *
 * @since 1.0.0
 */
class ExportServiceProvider implements ServiceProviderInterface {

	/**
	 * Register export services.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void {
		$container->register(
			AttachmentCollector::class,
			function ( ServiceContainer $container ) {
				return new AttachmentCollector(
					$container->get( BatchLoader::class )
				);
			}
		);

		$container->register(
			OptionsExporter::class,
			function ( ServiceContainer $container ) {
				return new OptionsExporter();
			}
		);

		$container->register(
			ExportHandler::class,
			function ( ServiceContainer $container ) {
				return new ExportHandler(
					$container->get( \MksDdn\MigrateContent\Archive\Packer::class ),
					$container->get( AttachmentCollector::class ),
					$container->get( OptionsExporter::class ),
					$container->get( BatchLoader::class )
				);
			}
		);
	}
}

