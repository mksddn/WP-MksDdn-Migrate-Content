<?php
/**
 * Main plugin bootstrapper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent;

use MksDdn\MigrateContent\Admin\AdminPageController;
use MksDdn\MigrateContent\Chunking\ChunkRestController;
use MksDdn\MigrateContent\Chunking\FullExportBuilder;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Core\ServiceContainerFactory;
use MksDdn\MigrateContent\Support\FullImportMaintenance;

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
		add_action( 'plugins_loaded', array( $this, 'boot_rest' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'ensure_storage_protection' ), 6 );
		add_action( 'init', array( FullImportMaintenance::class, 'maybe_block_public_requests' ), 0 );
		add_action( 'init', array( $this, 'boot_admin' ) );
	}

	/**
	 * Guard existing plugin storage directories on upgraded installs.
	 *
	 * @return void
	 * @since 2.5.0
	 */
	public function ensure_storage_protection(): void {
		PluginConfig::protect_existing_directories();
	}

	/**
	 * Register chunk REST routes as early as possible.
	 *
	 * @return void
	 * @since 2.3.1
	 */
	public function boot_rest(): void {
		$this->container->get( ChunkRestController::class );
		// Always resolved (including on wp-cron.php requests) so the background
		// full-export build hook is registered even outside admin context.
		$this->container->get( FullExportBuilder::class );
	}

	/**
	 * Initialize admin services.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function boot_admin(): void {
		if ( is_admin() ) {
			$admin_controller = $this->container->get( AdminPageController::class );
			$admin_controller->register();
		}
	}
}

