<?php
/**
 * Main plugin bootstrapper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Core\ServiceContainerFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin orchestrator.
 */
class Plugin {

	/**
	 * Service container instance.
	 *
	 * @var \MksDdn\MigrateContent\Core\ServiceContainer
	 */
	private \MksDdn\MigrateContent\Core\ServiceContainer $container;

	/**
	 * Constructor.
	 *
	 * @param \MksDdn\MigrateContent\Core\ServiceContainer|null $container Optional service container.
	 * @since 1.0.0
	 */
	public function __construct( ?\MksDdn\MigrateContent\Core\ServiceContainer $container = null ) {
		$this->container = $container ?? ServiceContainerFactory::create();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'boot' ) );
	}

	/**
	 * Initialize services.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function boot(): void {
		// Always load schedule manager (needed for cron).
		$schedule_manager = $this->container->get( ScheduleManager::class );
		$schedule_manager->register();

		// Initialize chunk REST controller (needed for REST API routes).
		$this->container->get( ChunkRestController::class );

		// Only load admin controller on admin pages.
		if ( is_admin() ) {
			$admin_controller = $this->container->get( AdminPageController::class );
			$admin_controller->register();
		}
	}
}

