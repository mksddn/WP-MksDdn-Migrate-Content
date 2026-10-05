<?php
/**
 * Selected export card template.
 *
 * @package MksDdn\MigrateContent
 * @var array $exportable_types Exportable post types (slug => label).
 * @var array $options_pages    ACF Options Pages for UI (optional).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
$options_pages = isset( $options_pages ) && is_array( $options_pages ) ? $options_pages : array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
$exportable_types = isset( $exportable_types ) && is_array( $exportable_types ) ? $exportable_types : array();
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
$has_picker_items = ! empty( $exportable_types ) || ! empty( $options_pages );
?>
<div class="mksddn-mc-card">
	<h3><?php esc_html_e( 'Export', 'mksddn-migrate-content' ); ?></h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mksddn_mc_selected_export' ); ?>
		<input type="hidden" name="action" value="mksddn_mc_export_selected">
		<div class="mksddn-mc-field">
			<h4><?php esc_html_e( 'Choose content', 'mksddn-migrate-content' ); ?></h4>
			<?php if ( ! empty( $options_pages ) ) : ?>
				<p class="description"><?php esc_html_e( 'Hold Cmd/Ctrl to pick multiple entries or Options Pages. Search matches titles. Scroll a list or use Load more.', 'mksddn-migrate-content' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Hold Cmd/Ctrl to pick multiple entries. Search matches titles. Scroll a list or use Load more.', 'mksddn-migrate-content' ); ?></p>
			<?php endif; ?>

			<?php if ( $has_picker_items ) : ?>
				<div class="mksddn-mc-selection-grid" data-mksddn-mc-content-picker>
					<?php foreach ( $exportable_types as $type => $label ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variables. ?>
						<?php
						$name      = 'selected_' . $type . '_ids[]'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
						$select_id = 'selected_' . $type . '_ids'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
						?>
						<div class="mksddn-mc-basic-selection">
							<label for="<?php echo esc_attr( $select_id ); ?>">
								<?php echo esc_html( (string) $label ); ?>
							</label>
							<input
								type="search"
								class="mksddn-mc-search-input"
								data-post-type="<?php echo esc_attr( (string) $type ); ?>"
								data-target="<?php echo esc_attr( $select_id ); ?>"
								placeholder="<?php esc_attr_e( 'Search by title...', 'mksddn-migrate-content' ); ?>"
								aria-label="<?php esc_attr_e( 'Search by title', 'mksddn-migrate-content' ); ?>"
							>
							<select
								id="<?php echo esc_attr( $select_id ); ?>"
								multiple
								size="12"
								data-post-type="<?php echo esc_attr( (string) $type ); ?>"
							>
								<option value="" disabled><?php esc_html_e( 'Loading...', 'mksddn-migrate-content' ); ?></option>
							</select>
							<button
								type="button"
								class="button mksddn-mc-load-more"
								hidden
								aria-controls="<?php echo esc_attr( $select_id ); ?>"
							>
								<?php esc_html_e( 'Load more', 'mksddn-migrate-content' ); ?>
							</button>
							<input
								type="hidden"
								name="<?php echo esc_attr( $name ); ?>"
								class="mksddn-mc-selected-ids"
								data-post-type="<?php echo esc_attr( (string) $type ); ?>"
								value=""
							>
						</div>
					<?php endforeach; ?>

					<?php if ( ! empty( $options_pages ) ) : ?>
						<div class="mksddn-mc-basic-selection">
							<label for="selected_options_page_slugs">
								<?php esc_html_e( 'Options Pages', 'mksddn-migrate-content' ); ?>
							</label>
							<select id="selected_options_page_slugs" name="selected_options_page_slugs[]" multiple size="12">
								<?php foreach ( $options_pages as $options_page ) : // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variable. ?>
									<?php
									if ( ! is_array( $options_page ) ) {
										continue;
									}
									$menu_slug  = sanitize_key( (string) ( $options_page['menu_slug'] ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
									$page_title = sanitize_text_field( (string) ( $options_page['page_title'] ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
									$menu_title = sanitize_text_field( (string) ( $options_page['menu_title'] ?? '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
									if ( '' === $menu_slug ) {
										continue;
									}
									$label = '' !== $page_title ? $page_title : ( '' !== $menu_title ? $menu_title : $menu_slug ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables.
									?>
									<option value="<?php echo esc_attr( $menu_slug ); ?>">
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No exportable post types were found.', 'mksddn-migrate-content' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="mksddn-mc-field">
			<h4><?php esc_html_e( 'File format', 'mksddn-migrate-content' ); ?></h4>
			<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/format-selector.php' ); ?>
		</div>

		<button type="submit" class="button button-primary"><?php esc_html_e( 'Export selected', 'mksddn-migrate-content' ); ?></button>
	</form>
</div>
