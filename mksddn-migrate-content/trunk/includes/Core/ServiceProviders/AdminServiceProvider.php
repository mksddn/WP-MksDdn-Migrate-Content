<?php
/**
 * @file: AdminServiceProvider.php
 * @description: Service provider for admin-related services
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\ServiceProviders;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Admin\Handlers\ExportHandler;
use MksDdn\MigrateContent\Admin\Handlers\ImportHandler;
use MksDdn\MigrateContent\Admin\Handlers\RecoveryHandler;
use MksDdn\MigrateContent\Admin\Handlers\ScheduleHandler;
use MksDdn\MigrateContent\Admin\Handlers\UserMergeHandler;
use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\ProgressService;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Core\ServiceContainer;
use MksDdn\MigrateContent\Core\ServiceProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service provider for admin-related services.
 *
 * @since 1.0.0
 */
class AdminServiceProvider implements ServiceProviderInterface {

	/**
	 * Register admin services.
	 *
	 * @param ServiceContainer $container Service container.
	 * @return void
	 * @since 1.0.0
	 */
	public function register( ServiceContainer $container ): void {
		// Services.
		$container->register(
			NotificationService::class,
			function ( ServiceContainer $container ) {
				return new NotificationService();
			}
		);

		$container->register(
			ProgressService::class,
			function ( ServiceContainer $container ) {
				return new ProgressService();
			}
		);

		// View.
		$container->register(
			AdminPageView::class,
			function ( ServiceContainer $container ) {
				return new AdminPageView(
					$container->get( \MksDdn\MigrateContent\Recovery\HistoryRepository::class ),
					$container->get( \MksDdn\MigrateContent\Automation\ScheduleManager::class ),
					$container->get( \MksDdn\MigrateContent\Users\UserPreviewStore::class )
				);
			}
		);

		// Handlers.
		$container->register(
			ExportHandler::class,
			function ( ServiceContainer $container ) {
				return new ExportHandler(
					$container->get( NotificationService::class )
				);
			}
		);

		$container->register(
			ImportHandler::class,
			function ( ServiceContainer $container ) {
				return new ImportHandler(
					$container->get( \MksDdn\MigrateContent\Archive\Extractor::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\SnapshotManager::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\HistoryRepository::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( \MksDdn\MigrateContent\Users\UserPreviewStore::class ),
					$container->get( NotificationService::class ),
					$container->get( ProgressService::class )
				);
			}
		);

		$container->register(
			ScheduleHandler::class,
			function ( ServiceContainer $container ) {
				return new ScheduleHandler(
					$container->get( \MksDdn\MigrateContent\Automation\ScheduleManager::class ),
					$container->get( NotificationService::class )
				);
			}
		);

		$container->register(
			RecoveryHandler::class,
			function ( ServiceContainer $container ) {
				return new RecoveryHandler(
					$container->get( \MksDdn\MigrateContent\Recovery\SnapshotManager::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\HistoryRepository::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( NotificationService::class )
				);
			}
		);

		$container->register(
			UserMergeHandler::class,
			function ( ServiceContainer $container ) {
				return new UserMergeHandler(
					$container->get( \MksDdn\MigrateContent\Users\UserPreviewStore::class ),
					$container->get( NotificationService::class )
				);
			}
		);

		// Main controller.
		$container->register(
			AdminPageController::class,
			function ( ServiceContainer $container ) {
				return new AdminPageController(
					$container->get( AdminPageView::class ),
					$container->get( ExportHandler::class ),
					$container->get( ImportHandler::class ),
					$container->get( ScheduleHandler::class ),
					$container->get( RecoveryHandler::class ),
					$container->get( UserMergeHandler::class ),
					$container->get( NotificationService::class ),
					$container->get( ProgressService::class ),
					$container->get( \MksDdn\MigrateContent\Users\UserPreviewStore::class )
				);
			}
		);
	}
}

