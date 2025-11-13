<?php
/**
 * Main page template.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap mksddn-mc-main-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-status-section">
		<h2><?php esc_html_e( 'System Status', 'mksddn-migrate-content' ); ?></h2>
		<table class="widefat">
			<tbody>
				<tr>
					<td><strong><?php esc_html_e( 'PHP Version', 'mksddn-migrate-content' ); ?></strong></td>
					<td><?php echo esc_html( $system_status['php_version'] ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'WordPress Version', 'mksddn-migrate-content' ); ?></strong></td>
					<td><?php echo esc_html( $system_status['wp_version'] ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Memory Limit', 'mksddn-migrate-content' ); ?></strong></td>
					<td><?php echo esc_html( $system_status['memory_limit'] ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Max Upload Size', 'mksddn-migrate-content' ); ?></strong></td>
					<td><?php echo esc_html( $system_status['max_upload_size'] ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Upload Directory', 'mksddn-migrate-content' ); ?></strong></td>
					<td>
						<?php if ( $system_status['upload_dir_writable'] ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color: green;"></span>
							<?php esc_html_e( 'Writable', 'mksddn-migrate-content' ); ?>
						<?php else : ?>
							<span class="dashicons dashicons-dismiss" style="color: red;"></span>
							<?php esc_html_e( 'Not Writable', 'mksddn-migrate-content' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Database Version', 'mksddn-migrate-content' ); ?></strong></td>
					<td><?php echo esc_html( $system_status['db_version'] ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>

	<div class="mksddn-mc-quick-actions">
		<h2><?php esc_html_e( 'Quick Actions', 'mksddn-migrate-content' ); ?></h2>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-export' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Export Site', 'mksddn-migrate-content' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-import' ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Import Site', 'mksddn-migrate-content' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-history' ) ); ?>" class="button">
				<?php esc_html_e( 'View History', 'mksddn-migrate-content' ); ?>
			</a>
		</p>
	</div>
</div>

