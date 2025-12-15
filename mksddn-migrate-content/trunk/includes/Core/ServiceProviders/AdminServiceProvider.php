<?php
/**
 * @file: AdminServiceProvider.php
 * @description: Service provider for admin-related services
 * @dependencies: Core\ServiceContainer, Core\ServiceProviderInterface
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Core\ServiceProviders;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Admin\Handlers\ExportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ImportRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\RecoveryRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\ScheduleRequestHandler;
use MksDdn\MigrateContent\Admin\Handlers\UserMergeRequestHandler;
use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\ProgressService;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Contracts\ExportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\RecoveryRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ScheduleRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\UserMergeRequestHandlerInterface;
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
			ExportRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new ExportRequestHandler(
					$container->get( NotificationService::class )
				);
			}
		);
		$container->register(
			ExportRequestHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( ExportRequestHandlerInterface::class );
			}
		);

		$container->register(
			ImportRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new ImportRequestHandler(
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
			ImportRequestHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( ImportRequestHandlerInterface::class );
			}
		);

		$container->register(
			ScheduleRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new ScheduleRequestHandler(
					$container->get( \MksDdn\MigrateContent\Automation\ScheduleManager::class ),
					$container->get( NotificationService::class )
				);
			}
		);
		$container->register(
			ScheduleRequestHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( ScheduleRequestHandlerInterface::class );
			}
		);

		$container->register(
			RecoveryRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new RecoveryRequestHandler(
					$container->get( \MksDdn\MigrateContent\Recovery\SnapshotManager::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\HistoryRepository::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( NotificationService::class )
				);
			}
		);
		$container->register(
			RecoveryRequestHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( RecoveryRequestHandlerInterface::class );
			}
		);

		$container->register(
			UserMergeRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new UserMergeRequestHandler(
					$container->get( \MksDdn\MigrateContent\Users\UserPreviewStore::class ),
					$container->get( NotificationService::class )
				);
			}
		);
		$container->register(
			UserMergeRequestHandler::class,
			function ( ServiceContainer $container ) {
				return $container->get( UserMergeRequestHandlerInterface::class );
			}
		);

		// Main controller.
		$container->register(
			AdminPageController::class,
			function ( ServiceContainer $container ) {
				return new AdminPageController( $container );
			}
		);
	}
}

