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

		<?php
		$backups_count = MksDdn_MC_Backups::count_files();
		$latest_backups = array_slice( MksDdn_MC_Backups::get_files(), 0, 5 );
		?>

		<div class="mksddn-mc-dashboard-grid">
			<div class="mksddn-mc-dashboard-card">
				<h3><?php esc_html_e( 'Quick Actions', 'mksddn-migrate-content' ); ?></h3>
				<div class="mksddn-mc-action-buttons">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-export' ) ); ?>" class="button button-primary button-large">
						<?php esc_html_e( 'Export Site', 'mksddn-migrate-content' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-import' ) ); ?>" class="button button-secondary button-large">
						<?php esc_html_e( 'Import Site', 'mksddn-migrate-content' ); ?>
					</a>
				</div>
			</div>

			<div class="mksddn-mc-dashboard-card">
				<h3><?php esc_html_e( 'Backups', 'mksddn-migrate-content' ); ?></h3>
				<div class="mksddn-mc-stats">
					<p class="mksddn-mc-stat-number"><?php echo esc_html( $backups_count ); ?></p>
					<p class="mksddn-mc-stat-label"><?php esc_html_e( 'Total Backups', 'mksddn-migrate-content' ); ?></p>
				</div>
				<?php if ( $backups_count > 0 ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-backups' ) ); ?>" class="button">
						<?php esc_html_e( 'View All Backups', 'mksddn-migrate-content' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( ! empty( $latest_backups ) ) : ?>
			<div class="mksddn-mc-dashboard-card mksddn-mc-latest-backups">
				<h3><?php esc_html_e( 'Latest Backups', 'mksddn-migrate-content' ); ?></h3>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Filename', 'mksddn-migrate-content' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mksddn-migrate-content' ); ?></th>
							<th><?php esc_html_e( 'Size', 'mksddn-migrate-content' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $latest_backups as $backup ) : ?>
							<tr>
								<td><?php echo esc_html( $backup['filename'] ); ?></td>
								<td>
									<?php
									if ( $backup['mtime'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['mtime'] ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									if ( $backup['size'] !== null ) {
										echo esc_html( size_format( $backup['size'] ) );
									} else {
										echo '—';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

