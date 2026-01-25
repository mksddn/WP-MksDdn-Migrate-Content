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
					$name = 'selected_' . $type . '_ids[]';
					?>
					<div class="mksddn-mc-basic-selection">
						<label for="selected_<?php echo esc_attr( $type ); ?>_ids">
							<?php
							/* translators: %s type label */
							echo esc_html( sprintf( __( '%s entries', 'mksddn-migrate-content' ), $label ) );
							?>
						</label>
						<input 
							type="search" 
							class="mksddn-mc-search-input" 
							data-post-type="<?php echo esc_attr( $type ); ?>"
							data-target="selected_<?php echo esc_attr( $type ); ?>_ids"
							placeholder="<?php esc_attr_e( 'Search...', 'mksddn-migrate-content' ); ?>"
							aria-label="<?php esc_attr_e( 'Search entries', 'mksddn-migrate-content' ); ?>"
						>
						<select id="selected_<?php echo esc_attr( $type ); ?>_ids" multiple size="12" data-post-type="<?php echo esc_attr( $type ); ?>">
							<option value="" disabled><?php esc_html_e( 'Start typing to search...', 'mksddn-migrate-content' ); ?></option>
						</select>
						<input 
							type="hidden" 
							name="<?php echo esc_attr( $name ); ?>" 
							class="mksddn-mc-selected-ids" 
							data-post-type="<?php echo esc_attr( $type ); ?>"
							value=""
						>
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

