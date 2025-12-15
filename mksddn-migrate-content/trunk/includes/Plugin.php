<?php
/**
 * Main plugin bootstrapper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Automation\ScheduleManager;
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
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'boot' ) );
	}

	/**
	 * Initialize services.
	 */
	public function boot(): void {
		$schedule_manager = $this->container->get( ScheduleManager::class );
		$schedule_manager->register();

		$admin_controller = $this->container->get( AdminPageController::class );
		$admin_controller->register();
	}
}

