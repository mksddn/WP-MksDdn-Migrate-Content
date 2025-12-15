<?php
/**
 * @file: ChunkServiceProvider.php
 * @description: Service provider for chunking-related services
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Chunking;

use MksDdn\MigrateContent\Contracts\ChunkJobRepositoryInterface;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Core\ServiceProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service provider for chunking-related services.
 *
 * @since 1.0.0
 */
class ChunkServiceProvider implements ServiceProviderInterface {

	/**
	 * Register chunking services.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void {
		// Register ChunkJobRepository.
		$container->register(
			ChunkJobRepositoryInterface::class,
			function ( ServiceContainer $container ) {
				return new ChunkJobRepository();
			}
		);
		$container->register(
			ChunkJobRepository::class,
			function ( ServiceContainer $container ) {
				return $container->get( ChunkJobRepositoryInterface::class );
			}
		);

		// Register ChunkRestController.
		$container->register(
			ChunkRestController::class,
			function ( ServiceContainer $container ) {
				return new ChunkRestController(
					$container->get( ChunkJobRepositoryInterface::class )
				);
			}
		);
	}
}

