<?php
/**
 * Main plugin bootstrapper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC;

use Mksddn_MC\Admin\ExportImportAdmin;
use Mksddn_MC\Automation\ScheduleManager;

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

		new ExportImportAdmin( null, null, null, null, $schedule_manager );
	}
}

