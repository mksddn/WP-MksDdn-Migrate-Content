<?php
/**
 * History section template.
 *
 * @package MksDdn\MigrateContent
 * @var array $entries Processed history entries with formatted data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section mksddn-mc-history">
	<h2><?php esc_html_e( 'History & Recovery', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Recent imports, rollbacks, and available snapshots.', 'mksddn-migrate-content' ); ?></p>

	<?php if ( empty( $entries ) ) : ?>
		<p><?php esc_html_e( 'History is empty for now.', 'mksddn-migrate-content' ); ?></p>
	</section>
	<?php return; ?>
	<?php endif; ?>

	<div class="mksddn-mc-history__table">
		<table>
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Started', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Finished', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Snapshot', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'User', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable.
				foreach ( $entries as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( $entry['type'] ); ?></td>
						<td><?php echo wp_kses_post( $entry['status_badge'] ); ?></td>
						<td><?php echo esc_html( $entry['started_at'] ); ?></td>
						<td><?php echo esc_html( $entry['finished_at'] ); ?></td>
						<td><?php echo $entry['snapshot_id'] ? esc_html( $entry['snapshot_label'] ) : '&mdash;'; ?></td>
						<td><?php echo esc_html( $entry['user_label'] ); ?></td>
						<td><?php echo wp_kses_post( $entry['actions'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

