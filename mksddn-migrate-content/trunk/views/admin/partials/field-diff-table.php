<?php
/**
 * @file: field-diff-table.php
 * @description: Renders a field-name + status table section for preflight
 * @dependencies: None
 * @created: 2026-10-05
 *
 * @var array  $mksddn_mc_diff_section Section with rows / unchanged_count / more.
 * @var string $mksddn_mc_diff_title   Section title.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_diff_section = ( isset( $mksddn_mc_diff_section ) && is_array( $mksddn_mc_diff_section ) ) ? $mksddn_mc_diff_section : array();
$mksddn_mc_diff_title   = isset( $mksddn_mc_diff_title ) ? (string) $mksddn_mc_diff_title : '';
$mksddn_mc_diff_rows    = ( ! empty( $mksddn_mc_diff_section['rows'] ) && is_array( $mksddn_mc_diff_section['rows'] ) )
	? $mksddn_mc_diff_section['rows']
	: array();
$mksddn_mc_diff_unchanged = (int) ( $mksddn_mc_diff_section['unchanged_count'] ?? 0 );
$mksddn_mc_diff_more      = (int) ( $mksddn_mc_diff_section['more'] ?? 0 );

if ( empty( $mksddn_mc_diff_rows ) && $mksddn_mc_diff_unchanged <= 0 ) {
	return;
}

$mksddn_mc_status_labels = array(
	'added'       => __( 'Added', 'mksddn-migrate-content' ),
	'changed'     => __( 'Changed', 'mksddn-migrate-content' ),
	'removed'     => __( 'Cleared', 'mksddn-migrate-content' ),
	'url_rewrite' => __( 'URL rewrite', 'mksddn-migrate-content' ),
);
?>
<div class="mksddn-mc-field-diff__section">
	<?php if ( '' !== $mksddn_mc_diff_title ) : ?>
		<p class="mksddn-mc-field-diff__section-title"><strong><?php echo esc_html( $mksddn_mc_diff_title ); ?></strong></p>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_diff_rows ) ) : ?>
		<table class="widefat striped mksddn-mc-field-diff__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Field', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mksddn_mc_diff_rows as $mksddn_mc_diff_row ) : ?>
					<?php
					if ( ! is_array( $mksddn_mc_diff_row ) ) {
						continue;
					}
					$mksddn_mc_row_status = sanitize_key( (string) ( $mksddn_mc_diff_row['status'] ?? 'changed' ) );
					$mksddn_mc_row_label  = $mksddn_mc_status_labels[ $mksddn_mc_row_status ] ?? $mksddn_mc_row_status;
					?>
					<tr class="mksddn-mc-field-diff__row mksddn-mc-field-diff__row--<?php echo esc_attr( $mksddn_mc_row_status ); ?>">
						<td><code><?php echo esc_html( (string) ( $mksddn_mc_diff_row['field'] ?? '' ) ); ?></code></td>
						<td><?php echo esc_html( $mksddn_mc_row_label ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( $mksddn_mc_diff_unchanged > 0 ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: unchanged field count */
					_n( '%d field unchanged.', '%d fields unchanged.', $mksddn_mc_diff_unchanged, 'mksddn-migrate-content' ),
					$mksddn_mc_diff_unchanged
				)
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( $mksddn_mc_diff_more > 0 ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: additional fields not shown */
					__( '+%d more fields not shown.', 'mksddn-migrate-content' ),
					$mksddn_mc_diff_more
				)
			);
			?>
		</p>
	<?php endif; ?>
</div>
