<?php
/**
 * Backups page template
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Direct access not allowed' );
}

$backups = isset( $backups ) ? $backups : array();
$downloadable = isset( $downloadable ) ? $downloadable : true;
?>

<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-backups-page">
		<?php if ( empty( $backups ) ) : ?>
			<div class="mksddn-mc-empty-state">
				<p><?php esc_html_e( 'No backups found. Create your first backup by going to the Export page.', 'mksddn-migrate-content' ); ?></p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mksddn-mc-export' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Backup', 'mksddn-migrate-content' ); ?>
				</a>
			</div>
		<?php else : ?>
			<div class="mksddn-mc-backups-list">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th class="column-filename"><?php esc_html_e( 'Filename', 'mksddn-migrate-content' ); ?></th>
							<th class="column-date"><?php esc_html_e( 'Date', 'mksddn-migrate-content' ); ?></th>
							<th class="column-size"><?php esc_html_e( 'Size', 'mksddn-migrate-content' ); ?></th>
							<th class="column-actions"><?php esc_html_e( 'Actions', 'mksddn-migrate-content' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $backups as $backup ) : ?>
							<tr>
								<td class="column-filename">
									<strong><?php echo esc_html( $backup['filename'] ); ?></strong>
								</td>
								<td class="column-date">
									<?php
									if ( $backup['mtime'] ) {
										echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['mtime'] ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td class="column-size">
									<?php
									if ( $backup['size'] !== null ) {
										echo esc_html( size_format( $backup['size'] ) );
									} else {
										echo '—';
									}
									?>
								</td>
								<td class="column-actions">
									<?php if ( $downloadable ) : ?>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-ajax.php?action=mksddn_mc_backups_download&archive=' . urlencode( $backup['filename'] ) ), 'mksddn_mc_backups', 'nonce' ) ); ?>" class="button button-small">
											<?php esc_html_e( 'Download', 'mksddn-migrate-content' ); ?>
										</a>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete mksddn-mc-delete-backup" data-archive="<?php echo esc_attr( $backup['filename'] ); ?>">
										<?php esc_html_e( 'Delete', 'mksddn-migrate-content' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
	$('.mksddn-mc-delete-backup').on('click', function(e) {
		e.preventDefault();

		var $button = $(this);
		var archive = $button.data('archive');

		if (!confirm('<?php echo esc_js( __( 'Are you sure you want to delete this backup?', 'mksddn-migrate-content' ) ); ?>')) {
			return;
		}

		$button.prop('disabled', true).text('<?php echo esc_js( __( 'Deleting...', 'mksddn-migrate-content' ) ); ?>');

		$.ajax({
			url: mksddnMc.ajaxUrl,
			type: 'POST',
			data: {
				action: 'mksddn_mc_backups_delete',
				nonce: '<?php echo esc_js( wp_create_nonce( 'mksddn_mc_backups' ) ); ?>',
				archive: archive
			},
			success: function(response) {
				if (response.success) {
					$button.closest('tr').fadeOut(300, function() {
						$(this).remove();
						if ($('.mksddn-mc-backups-list tbody tr').length === 0) {
							location.reload();
						}
					});
				} else {
					alert(response.data ? response.data.message : '<?php echo esc_js( __( 'Failed to delete backup.', 'mksddn-migrate-content' ) ); ?>');
					$button.prop('disabled', false).text('<?php echo esc_js( __( 'Delete', 'mksddn-migrate-content' ) ); ?>');
				}
			},
			error: function() {
				alert('<?php echo esc_js( __( 'Network error. Please try again.', 'mksddn-migrate-content' ) ); ?>');
				$button.prop('disabled', false).text('<?php echo esc_js( __( 'Delete', 'mksddn-migrate-content' ) ); ?>');
			}
		});
	});
});
</script>

