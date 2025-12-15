<?php
/**
 * Main plugin bootstrapper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Automation\ScheduleManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin orchestrator.
 */
class Plugin {

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
		$schedule_manager = new ScheduleManager();
		$schedule_manager->register();

		$admin_controller = new AdminPageController();
		$admin_controller->register();
	}
}

