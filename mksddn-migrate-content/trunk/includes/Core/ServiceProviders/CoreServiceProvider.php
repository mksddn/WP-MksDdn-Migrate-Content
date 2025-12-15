<?php
/**
 * @file: CoreServiceProvider.php
 * @description: Service provider for core services (recovery, users, automation, archive)
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\ServiceProviders;

use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Archive\Packer;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Automation\ScheduleSettings;
use MksDdn\MigrateContent\Automation\ScheduledBackupRunner;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Core\ServiceProviderInterface;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Recovery\JobLock;
use MksDdn\MigrateContent\Recovery\SnapshotManager;
use MksDdn\MigrateContent\Core\Wrappers\WpFilesystemWrapper;
use MksDdn\MigrateContent\Core\Wrappers\WpFilesystemWrapperInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapper;
use MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapperInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapper;
use MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapperInterface;
use MksDdn\MigrateContent\Core\BatchLoader;
use MksDdn\MigrateContent\Users\UserPreviewStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service provider for core services.
 *
 * @since 1.0.0
 */
class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Register core services.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void {
		// Batch loader for optimizing database queries.
		$container->register(
			BatchLoader::class,
			function ( ServiceContainer $container ) {
				return new BatchLoader();
			}
		);

		// WordPress function wrappers.
		$container->register(
			WpFunctionsWrapperInterface::class,
			function ( ServiceContainer $container ) {
				return new WpFunctionsWrapper();
			}
		);

		$container->register(
			WpUserFunctionsWrapperInterface::class,
			function ( ServiceContainer $container ) {
				return new WpUserFunctionsWrapper();
			}
		);

		$container->register(
			WpFilesystemWrapperInterface::class,
			function ( ServiceContainer $container ) {
				return new WpFilesystemWrapper();
			}
		);
		// Archive services.
		$container->register(
			Extractor::class,
			function ( ServiceContainer $container ) {
				return new Extractor();
			}
		);

		$container->register(
			Packer::class,
			function ( ServiceContainer $container ) {
				return new Packer();
			}
		);

		// Recovery services.
		$container->register(
			HistoryRepository::class,
			function ( ServiceContainer $container ) {
				return new HistoryRepository();
			}
		);

		$container->register(
			JobLock::class,
			function ( ServiceContainer $container ) {
				return new JobLock();
			}
		);

		$container->register(
			SnapshotManager::class,
			function ( ServiceContainer $container ) {
				return new SnapshotManager();
			}
		);

		// User services.
		$container->register(
			UserPreviewStore::class,
			function ( ServiceContainer $container ) {
				return new UserPreviewStore();
			}
		);

		// Automation services.
		$container->register(
			ScheduleSettings::class,
			function ( ServiceContainer $container ) {
				return new ScheduleSettings();
			}
		);

		$container->register(
			ScheduledBackupRunner::class,
			function ( ServiceContainer $container ) {
				return new ScheduledBackupRunner(
					null,
					null,
					null,
					$container->get( ScheduleSettings::class )
				);
			}
		);

		$container->register(
			ScheduleManager::class,
			function ( ServiceContainer $container ) {
				return new ScheduleManager(
					$container->get( ScheduleSettings::class ),
					$container->get( ScheduledBackupRunner::class )
				);
			}
		);
	}
}

