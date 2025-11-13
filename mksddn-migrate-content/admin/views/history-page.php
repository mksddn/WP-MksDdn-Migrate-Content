<?php
/**
 * History page template.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap mksddn-mc-history-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-history-tabs">
		<a href="#export-history" class="nav-tab nav-tab-active"><?php esc_html_e( 'Export History', 'mksddn-migrate-content' ); ?></a>
		<a href="#import-history" class="nav-tab"><?php esc_html_e( 'Import History', 'mksddn-migrate-content' ); ?></a>
	</div>

	<div id="export-history" class="mksddn-mc-history-section">
		<h2><?php esc_html_e( 'Export History', 'mksddn-migrate-content' ); ?></h2>
		<?php if ( ! empty( $export_history ) ) : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'mksddn-migrate-content' ); ?></th>
						<th><?php esc_html_e( 'Type', 'mksddn-migrate-content' ); ?></th>
						<th><?php esc_html_e( 'File Size', 'mksddn-migrate-content' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'mksddn-migrate-content' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $export_history as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
							<td><?php echo esc_html( isset( $entry['data']['type'] ) ? $entry['data']['type'] : '-' ); ?></td>
							<td><?php echo esc_html( isset( $entry['data']['file_size'] ) ? size_format( $entry['data']['file_size'] ) : '-' ); ?></td>
							<td>
								<?php if ( isset( $entry['data']['file_path'] ) && file_exists( $entry['data']['file_path'] ) ) : ?>
									<?php
									$file_url = str_replace( wp_upload_dir()['basedir'], wp_upload_dir()['baseurl'], $entry['data']['file_path'] );
									?>
									<a href="<?php echo esc_url( $file_url ); ?>" class="button button-small" download>
										<?php esc_html_e( 'Download', 'mksddn-migrate-content' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No export history found.', 'mksddn-migrate-content' ); ?></p>
		<?php endif; ?>
	</div>

	<div id="import-history" class="mksddn-mc-history-section" style="display: none;">
		<h2><?php esc_html_e( 'Import History', 'mksddn-migrate-content' ); ?></h2>
		<?php if ( ! empty( $import_history ) ) : ?>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'mksddn-migrate-content' ); ?></th>
						<th><?php esc_html_e( 'File Size', 'mksddn-migrate-content' ); ?></th>
						<th><?php esc_html_e( 'Status', 'mksddn-migrate-content' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $import_history as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['timestamp'] ); ?></td>
							<td><?php echo esc_html( isset( $entry['data']['file_size'] ) ? size_format( $entry['data']['file_size'] ) : '-' ); ?></td>
							<td>
								<?php if ( isset( $entry['data']['result']['success'] ) && $entry['data']['result']['success'] ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color: green;"></span>
									<?php esc_html_e( 'Success', 'mksddn-migrate-content' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-dismiss" style="color: red;"></span>
									<?php esc_html_e( 'Failed', 'mksddn-migrate-content' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No import history found.', 'mksddn-migrate-content' ); ?></p>
		<?php endif; ?>
	</div>
</div>

