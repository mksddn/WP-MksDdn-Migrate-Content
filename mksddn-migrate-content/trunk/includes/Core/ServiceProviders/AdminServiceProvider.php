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
use MksDdn\MigrateContent\Admin\Services\FullSiteImportService;
use MksDdn\MigrateContent\Admin\Services\ImportFileValidator;
use MksDdn\MigrateContent\Admin\Services\ImportPayloadPreparer;
use MksDdn\MigrateContent\Admin\Services\NotificationService;
use MksDdn\MigrateContent\Admin\Services\ProgressService;
use MksDdn\MigrateContent\Admin\Services\ResponseHandler;
use MksDdn\MigrateContent\Admin\Services\SelectedContentImportService;
use MksDdn\MigrateContent\Admin\Views\AdminPageView;
use MksDdn\MigrateContent\Contracts\ExportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\ImportRequestHandlerInterface;
use MksDdn\MigrateContent\Contracts\NotificationServiceInterface;
use MksDdn\MigrateContent\Contracts\ProgressServiceInterface;
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
			NotificationServiceInterface::class,
			function ( ServiceContainer $container ) {
				return new NotificationService();
			}
		);
		$container->register(
			NotificationService::class,
			function ( ServiceContainer $container ) {
				return $container->get( NotificationServiceInterface::class );
			}
		);

		$container->register(
			ProgressServiceInterface::class,
			function ( ServiceContainer $container ) {
				return new ProgressService();
			}
		);
		$container->register(
			ProgressService::class,
			function ( ServiceContainer $container ) {
				return $container->get( ProgressServiceInterface::class );
			}
		);

		$container->register(
			ResponseHandler::class,
			function ( ServiceContainer $container ) {
				return new ResponseHandler(
					$container->get( NotificationServiceInterface::class )
				);
			}
		);

		$container->register(
			ImportFileValidator::class,
			function ( ServiceContainer $container ) {
				return new ImportFileValidator();
			}
		);

		$container->register(
			ImportPayloadPreparer::class,
			function ( ServiceContainer $container ) {
				return new ImportPayloadPreparer(
					$container->get( \MksDdn\MigrateContent\Archive\Extractor::class )
				);
			}
		);

		$container->register(
			SelectedContentImportService::class,
			function ( ServiceContainer $container ) {
				return new SelectedContentImportService(
					$container->get( \MksDdn\MigrateContent\Archive\Extractor::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\SnapshotManagerInterface::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( NotificationServiceInterface::class ),
					$container->get( ProgressServiceInterface::class ),
					$container->get( ImportFileValidator::class ),
					$container->get( ImportPayloadPreparer::class )
				);
			}
		);

		$container->register(
			FullSiteImportService::class,
			function ( ServiceContainer $container ) {
				return new FullSiteImportService(
					$container->get( \MksDdn\MigrateContent\Contracts\SnapshotManagerInterface::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\UserPreviewStoreInterface::class ),
					$container->get( NotificationServiceInterface::class ),
					$container->get( ResponseHandler::class )
				);
			}
		);

		// View.
		$container->register(
			AdminPageView::class,
			function ( ServiceContainer $container ) {
				return new AdminPageView(
					$container->get( \MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\ScheduleManagerInterface::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\UserPreviewStoreInterface::class )
				);
			}
		);

		// Handlers.
		$container->register(
			ExportRequestHandlerInterface::class,
			function ( ServiceContainer $container ) {
				return new ExportRequestHandler(
					$container->get( NotificationServiceInterface::class )
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
					$container->get( SelectedContentImportService::class ),
					$container->get( FullSiteImportService::class )
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
					$container->get( \MksDdn\MigrateContent\Contracts\ScheduleManagerInterface::class ),
					$container->get( NotificationServiceInterface::class )
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
					$container->get( \MksDdn\MigrateContent\Contracts\SnapshotManagerInterface::class ),
					$container->get( \MksDdn\MigrateContent\Contracts\HistoryRepositoryInterface::class ),
					$container->get( \MksDdn\MigrateContent\Recovery\JobLock::class ),
					$container->get( NotificationServiceInterface::class )
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
					$container->get( \MksDdn\MigrateContent\Contracts\UserPreviewStoreInterface::class ),
					$container->get( NotificationServiceInterface::class )
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

