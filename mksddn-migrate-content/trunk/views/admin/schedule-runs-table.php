<?php
/**
 * Schedule runs table template.
 *
 * @package MksDdn\MigrateContent
 * @var array $runs Processed run entries with formatted data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $runs ) ) :
	?>
	<p><?php esc_html_e( 'No scheduled backups have been created yet.', 'mksddn-migrate-content' ); ?></p>
	<?php
	return;
endif;
?>
<div class="mksddn-mc-user-table-wrapper">
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Run time', 'mksddn-migrate-content' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mksddn-migrate-content' ); ?></th>
				<th><?php esc_html_e( 'Archive', 'mksddn-migrate-content' ); ?></th>
				<th><?php esc_html_e( 'Notes', 'mksddn-migrate-content' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mksddn-migrate-content' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $runs as $run ) : ?>
				<?php
				$filename = $run['filename'];
				$download = '';

				if ( $filename ) {
					$download_url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'mksddn_mc_download_scheduled',
								'file'   => $filename,
							),
							admin_url( 'admin-post.php' )
						),
						'mksddn_mc_download_scheduled_' . $filename
					);

					$delete_url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'mksddn_mc_delete_scheduled',
								'file'   => $filename,
							),
							admin_url( 'admin-post.php' )
						),
						'mksddn_mc_delete_scheduled_' . $filename
					);

					$download = '<a class="button button-small" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'mksddn-migrate-content' ) . '</a>';
					$download .= ' <a class="button button-small button-secondary" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this scheduled backup?', 'mksddn-migrate-content' ) ) . '\');">' . esc_html__( 'Delete', 'mksddn-migrate-content' ) . '</a>';
				}
				?>
				<tr>
					<td><?php echo esc_html( $run['created_at'] ); ?></td>
					<td><?php echo wp_kses_post( $run['status_badge'] ); ?></td>
					<td><?php echo $filename ? esc_html( $filename ) . '<br><span class="description">' . esc_html( $run['size'] ) . '</span>' : '&mdash;'; ?></td>
					<td><?php echo $run['message'] ? wp_kses_post( $run['message'] ) : '&mdash;'; ?></td>
					<td><?php echo wp_kses_post( $download ?: '&mdash;' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

