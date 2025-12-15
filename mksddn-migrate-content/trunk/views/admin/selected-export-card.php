<?php
/**
 * Selected export card template.
 *
 * @package MksDdn\MigrateContent
 * @var array $exportable_types Exportable post types.
 * @var array $items_by_type    Items grouped by post type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mksddn-mc-card">
	<h3><?php esc_html_e( 'Export', 'mksddn-migrate-content' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mksddn_mc_selected_export' ); ?>
		<input type="hidden" name="action" value="mksddn_mc_export_selected">
		<div class="mksddn-mc-field">
			<h4><?php esc_html_e( 'Choose content', 'mksddn-migrate-content' ); ?></h4>
			<p class="description"><?php esc_html_e( 'Hold Cmd/Ctrl to pick multiple entries inside each list.', 'mksddn-migrate-content' ); ?></p>
			<div class="mksddn-mc-selection-grid">
				<?php
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variables.
				foreach ( $exportable_types as $type => $label ) : ?>
					<?php
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
					$items = $items_by_type[ $type ] ?? array();
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
					$size = max( 4, min( count( $items ), 12 ) );
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
					$name = 'selected_' . $type . '_ids[]';
					?>
					<div class="mksddn-mc-basic-selection">
						<label for="selected_<?php echo esc_attr( $type ); ?>_ids">
							<?php
							/* translators: %s type label */
							echo esc_html( sprintf( __( '%s entries', 'mksddn-migrate-content' ), $label ) );
							?>
						</label>
						<select id="selected_<?php echo esc_attr( $type ); ?>_ids" name="<?php echo esc_attr( $name ); ?>" multiple size="<?php echo esc_attr( $size ); ?>">
							<?php if ( empty( $items ) ) : ?>
								<option value="" disabled><?php esc_html_e( 'No entries found', 'mksddn-migrate-content' ); ?></option>
							<?php else : ?>
								<?php
								// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable.
								foreach ( $items as $item ) : ?>
									<?php
									// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
									$label_text = $item->post_title ?: ( '#' . $item->ID );
									?>
									<option value="<?php echo esc_attr( $item->ID ); ?>"><?php echo esc_html( $label_text ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="mksddn-mc-field">
			<h4><?php esc_html_e( 'File format', 'mksddn-migrate-content' ); ?></h4>
			<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/format-selector.php' ); ?>
		</div>

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Export selected', 'mksddn-migrate-content' ); ?></button>
	</form>
</div>

