<?php
/**
 * Main page template
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-main-page">
		<div class="mksddn-mc-welcome">
			<h2><?php esc_html_e( 'Welcome to MksDdn Migrate Content', 'mksddn-migrate-content' ); ?></h2>
			<p><?php esc_html_e( 'Export or import your entire WordPress site with ease.', 'mksddn-migrate-content' ); ?></p>
		</div>

		<div class="mksddn-mc-actions">
			<div class="mksddn-mc-action-card">
				<h3><?php esc_html_e( 'Export', 'mksddn-migrate-content' ); ?></h3>
				<p><?php esc_html_e( 'Export your site to a migration file.', 'mksddn-migrate-content' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-export' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Export Site', 'mksddn-migrate-content' ); ?>
				</a>
			</div>

			<div class="mksddn-mc-action-card">
				<h3><?php esc_html_e( 'Import', 'mksddn-migrate-content' ); ?></h3>
				<p><?php esc_html_e( 'Import a migration file to this site.', 'mksddn-migrate-content' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-import' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Import Site', 'mksddn-migrate-content' ); ?>
				</a>
			</div>

			<div class="mksddn-mc-action-card">
				<h3><?php esc_html_e( 'Backups', 'mksddn-migrate-content' ); ?></h3>
				<p><?php esc_html_e( 'Manage your backup files.', 'mksddn-migrate-content' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-backups' ) ); ?>" class="button">
					<?php esc_html_e( 'View Backups', 'mksddn-migrate-content' ); ?>
				</a>
			</div>
		</div>
	</div>
</div>

