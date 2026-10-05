<?php
/**
 * Preflight (dry-run) report block.
 *
 * @package MksDdn\MigrateContent
 *
 * @var array  $mksddn_mc_preflight_report    Normalized preflight report.
 * @var string $mksddn_mc_preflight_report_id Id for the follow-up import request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_preflight_report_id = isset( $mksddn_mc_preflight_report_id ) ? (string) $mksddn_mc_preflight_report_id : '';

$mksddn_mc_status = isset( $mksddn_mc_preflight_report['status'] ) ? sanitize_key( (string) $mksddn_mc_preflight_report['status'] ) : 'ok';
$mksddn_mc_notice_class = 'notice-info';
if ( 'error' === $mksddn_mc_status ) {
	$mksddn_mc_notice_class = 'notice-error';
} elseif ( 'warning' === $mksddn_mc_status ) {
	$mksddn_mc_notice_class = 'notice-warning';
} elseif ( 'ok' === $mksddn_mc_status ) {
	$mksddn_mc_notice_class = 'notice-success';
}

$mksddn_mc_summary           = isset( $mksddn_mc_preflight_report['summary'] ) && is_array( $mksddn_mc_preflight_report['summary'] ) ? $mksddn_mc_preflight_report['summary'] : array();
$mksddn_mc_warnings          = isset( $mksddn_mc_preflight_report['warnings'] ) && is_array( $mksddn_mc_preflight_report['warnings'] ) ? $mksddn_mc_preflight_report['warnings'] : array();
$mksddn_mc_errors            = isset( $mksddn_mc_preflight_report['errors'] ) && is_array( $mksddn_mc_preflight_report['errors'] ) ? $mksddn_mc_preflight_report['errors'] : array();
$mksddn_mc_estimated         = isset( $mksddn_mc_preflight_report['estimated_changes'] ) && is_array( $mksddn_mc_preflight_report['estimated_changes'] ) ? $mksddn_mc_preflight_report['estimated_changes'] : array();
$mksddn_mc_next_step         = isset( $mksddn_mc_preflight_report['next_step'] ) ? (string) $mksddn_mc_preflight_report['next_step'] : '';
$mksddn_mc_import_type_code  = isset( $mksddn_mc_preflight_report['import_type'] ) ? sanitize_key( (string) $mksddn_mc_preflight_report['import_type'] ) : '';
$mksddn_mc_source_code       = isset( $mksddn_mc_preflight_report['source'] ) ? sanitize_key( (string) $mksddn_mc_preflight_report['source'] ) : '';

$mksddn_mc_import_type_names = array(
	'full'     => __( 'Full site', 'mksddn-migrate-content' ),
	'themes'   => __( 'Theme archive', 'mksddn-migrate-content' ),
	'selected' => __( 'Selected content', 'mksddn-migrate-content' ),
);
$mksddn_mc_source_names        = array(
	'upload' => __( 'Browser upload', 'mksddn-migrate-content' ),
	'server' => __( 'Server file', 'mksddn-migrate-content' ),
	'chunk'  => __( 'Chunked upload', 'mksddn-migrate-content' ),
);
$mksddn_mc_unknown_label       = __( 'Unknown', 'mksddn-migrate-content' );
$mksddn_mc_import_type_label   = $mksddn_mc_import_type_names[ $mksddn_mc_import_type_code ] ?? $mksddn_mc_unknown_label;
$mksddn_mc_source_label        = $mksddn_mc_source_names[ $mksddn_mc_source_code ] ?? $mksddn_mc_unknown_label;

$mksddn_mc_payload_type_labels = array(
	'page'   => __( 'Page', 'mksddn-migrate-content' ),
	'post'   => __( 'Post', 'mksddn-migrate-content' ),
	'bundle' => __( 'Bundle (multiple items)', 'mksddn-migrate-content' ),
);

$mksddn_mc_import_page_url = admin_url( 'admin.php?page=' . \MksDdn\MigrateContent\Config\PluginConfig::text_domain() . '-import' );

/**
 * Human-readable post type for preflight table (uses registered labels when available).
 *
 * @param string $post_type Post type slug.
 * @return string
 */
$mksddn_mc_preflight_post_type_label = static function ( string $post_type ): string {
	$post_type = sanitize_key( $post_type );
	if ( '' === $post_type ) {
		return '';
	}
	$object = get_post_type_object( $post_type );
	if ( $object && ! empty( $object->labels->singular_name ) ) {
		return $object->labels->singular_name;
	}
	return __( 'Unknown', 'mksddn-migrate-content' );
};
?>
<div class="mksddn-mc-preflight-report notice <?php echo esc_attr( $mksddn_mc_notice_class ); ?>" style="margin: 15px 0;">
	<p><strong><?php esc_html_e( 'Preflight report (no changes were made)', 'mksddn-migrate-content' ); ?></strong></p>
	<p class="description">
		<?php
		printf(
			/* translators: 1: import type, 2: file source */
			esc_html__( 'Detected type: %1$s · Source: %2$s', 'mksddn-migrate-content' ),
			esc_html( $mksddn_mc_import_type_label ),
			esc_html( $mksddn_mc_source_label )
		);
		?>
	</p>

	<?php if ( ! empty( $mksddn_mc_summary ) ) : ?>
		<ul class="ul-disc">
			<?php if ( ! empty( $mksddn_mc_summary['file_name'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: file name */
							__( 'File: %s', 'mksddn-migrate-content' ),
							(string) $mksddn_mc_summary['file_name']
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( ! empty( $mksddn_mc_summary['file_size'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: formatted file size */
							__( 'Size: %s', 'mksddn-migrate-content' ),
							size_format( (int) $mksddn_mc_summary['file_size'], 2 )
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['payload_type'] ) ) : ?>
				<li>
					<?php
					$mksddn_mc_ptype_raw = sanitize_key( (string) $mksddn_mc_summary['payload_type'] );
					$mksddn_mc_ptype_show = $mksddn_mc_payload_type_labels[ $mksddn_mc_ptype_raw ] ?? $mksddn_mc_unknown_label;
					echo esc_html(
						sprintf(
							/* translators: %s: payload type label */
							__( 'Payload type: %s', 'mksddn-migrate-content' ),
							$mksddn_mc_ptype_show
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['item_count'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: content item count */ __( 'Items to import: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['item_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['options_pages_count'] ) && (int) $mksddn_mc_summary['options_pages_count'] > 0 ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: options pages count */ __( 'ACF Options Pages: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['options_pages_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['media_files'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: media file count */ __( 'Media files in archive: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['media_files'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['slug_conflicts_count'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Slug overlap with existing content: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['slug_conflicts_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['users_in_archive'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: user count */ __( 'Users in archive: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['users_in_archive'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['user_conflicts'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: conflict count */ __( 'Potential user email conflicts: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['user_conflicts'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( in_array( $mksddn_mc_import_type_code, array( 'full', 'themes' ), true ) ) : ?>
				<?php if ( ! empty( $mksddn_mc_summary['source_php_version'] ) ) : ?>
					<li>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: PHP version in archive, 2: PHP version on this site (or Unknown) */
								__( 'PHP: archive %1$s -> this site %2$s', 'mksddn-migrate-content' ),
								(string) $mksddn_mc_summary['source_php_version'],
								! empty( $mksddn_mc_summary['target_php_version'] ) ? (string) $mksddn_mc_summary['target_php_version'] : $mksddn_mc_unknown_label
							)
						);
						?>
					</li>
				<?php endif; ?>
				<?php if ( ! empty( $mksddn_mc_summary['source_wp_version'] ) ) : ?>
					<li>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: WordPress version in archive, 2: WordPress version on this site (or Unknown) */
								__( 'WordPress: archive %1$s -> this site %2$s', 'mksddn-migrate-content' ),
								(string) $mksddn_mc_summary['source_wp_version'],
								! empty( $mksddn_mc_summary['target_wp_version'] ) ? (string) $mksddn_mc_summary['target_wp_version'] : $mksddn_mc_unknown_label
							)
						);
						?>
					</li>
				<?php endif; ?>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['theme_count'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: theme count */ __( 'Themes in archive: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['theme_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['files_total'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Theme files in archive: %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_total'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['files_added'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files to add (merge): %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_added'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['files_overwrite'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files with changed content (merge): %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_overwrite'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['files_unverified'] ) && (int) $mksddn_mc_summary['files_unverified'] > 0 ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Same-size unverified (merge): %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_unverified'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $mksddn_mc_summary['files_identical'] ) && (int) $mksddn_mc_summary['files_identical'] > 0 ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Identical theme files (merge): %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_identical'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( ! empty( $mksddn_mc_summary['existing_slugs'] ) && is_array( $mksddn_mc_summary['existing_slugs'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated theme slugs */
							__( 'Already installed themes: %s', 'mksddn-migrate-content' ),
							implode( ', ', array_map( 'sanitize_text_field', $mksddn_mc_summary['existing_slugs'] ) )
						)
					);
					?>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>

	<?php
	$mksddn_mc_theme_files = ( ! empty( $mksddn_mc_estimated['theme_files'] ) && is_array( $mksddn_mc_estimated['theme_files'] ) )
		? $mksddn_mc_estimated['theme_files']
		: array();
	if ( ! empty( $mksddn_mc_theme_files ) ) {
		include MKSDDN_MC_DIR . 'views/admin/partials/theme-file-diff.php';
	}
	?>

	<?php if ( isset( $mksddn_mc_summary['fields_changed_count'] ) ) : ?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: changed fields count */
					__( 'Estimated field/taxonomy changes: %d', 'mksddn-migrate-content' ),
					(int) $mksddn_mc_summary['fields_changed_count']
				)
			);
			if ( ! empty( $mksddn_mc_summary['field_diff_omitted'] ) ) {
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %d: number of content items skipped in field-level analysis */
						__( '(%d more content items not included in this total.)', 'mksddn-migrate-content' ),
						(int) $mksddn_mc_summary['field_diff_omitted']
					)
				);
			}
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_estimated['items'] ) && is_array( $mksddn_mc_estimated['items'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Content to import', 'mksddn-migrate-content' ); ?></strong></p>
		<table class="widefat striped" style="max-width: 840px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Slug', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Post type', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Action', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mksddn_mc_estimated['items'] as $mksddn_mc_item ) : ?>
					<?php
					if ( ! is_array( $mksddn_mc_item ) ) {
						continue;
					}
					$mksddn_mc_item_action = sanitize_key( (string) ( $mksddn_mc_item['action'] ?? 'create' ) );
					$mksddn_mc_item_title  = (string) ( $mksddn_mc_item['title'] ?? '' );
					if ( '' === $mksddn_mc_item_title ) {
						$mksddn_mc_item_title = (string) ( $mksddn_mc_item['slug'] ?? '' );
					}
					$mksddn_mc_changes        = ( isset( $mksddn_mc_item['changes'] ) && is_array( $mksddn_mc_item['changes'] ) ) ? $mksddn_mc_item['changes'] : array();
					$mksddn_mc_change_summary = ( isset( $mksddn_mc_changes['summary'] ) && is_array( $mksddn_mc_changes['summary'] ) ) ? $mksddn_mc_changes['summary'] : array();
					$mksddn_mc_field_count    = (int) ( $mksddn_mc_change_summary['field_count'] ?? 0 );
					$mksddn_mc_tax_count      = (int) ( $mksddn_mc_change_summary['tax_count'] ?? 0 );
					if ( 'update' === $mksddn_mc_item_action ) {
						$mksddn_mc_existing_id  = (int) ( $mksddn_mc_item['existing_post_id'] ?? 0 );
						$mksddn_mc_action_label = $mksddn_mc_existing_id > 0
							? sprintf(
								/* translators: %d: existing post ID */
								__( 'Update (#%d)', 'mksddn-migrate-content' ),
								$mksddn_mc_existing_id
							)
							: __( 'Update', 'mksddn-migrate-content' );
					} else {
						$mksddn_mc_action_label = __( 'Create', 'mksddn-migrate-content' );
					}
					$mksddn_mc_detail_bits = array();
					if ( $mksddn_mc_field_count > 0 ) {
						$mksddn_mc_detail_bits[] = sprintf(
							/* translators: %d: field count */
							_n( '%d field', '%d fields', $mksddn_mc_field_count, 'mksddn-migrate-content' ),
							$mksddn_mc_field_count
						);
					}
					if ( $mksddn_mc_tax_count > 0 ) {
						$mksddn_mc_detail_bits[] = sprintf(
							/* translators: %d: taxonomy count */
							_n( '%d taxonomy', '%d taxonomies', $mksddn_mc_tax_count, 'mksddn-migrate-content' ),
							$mksddn_mc_tax_count
						);
					}
					if ( ! empty( $mksddn_mc_detail_bits ) ) {
						$mksddn_mc_action_label .= ' · ' . implode( ', ', $mksddn_mc_detail_bits );
					}
					if ( ! empty( $mksddn_mc_changes['omitted'] ) ) {
						$mksddn_mc_action_label .= ' · ' . __( 'field analysis skipped', 'mksddn-migrate-content' );
					}
					?>
					<tr>
						<td><?php echo esc_html( $mksddn_mc_item_title ); ?></td>
						<td><?php echo esc_html( (string) ( $mksddn_mc_item['slug'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $mksddn_mc_preflight_post_type_label( (string) ( $mksddn_mc_item['post_type'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( $mksddn_mc_action_label ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="mksddn-mc-field-diff">
			<?php foreach ( $mksddn_mc_estimated['items'] as $mksddn_mc_item ) : ?>
				<?php
				if ( ! is_array( $mksddn_mc_item ) ) {
					continue;
				}
				$mksddn_mc_changes = ( isset( $mksddn_mc_item['changes'] ) && is_array( $mksddn_mc_item['changes'] ) ) ? $mksddn_mc_item['changes'] : array();
				if ( empty( $mksddn_mc_changes ) || ! empty( $mksddn_mc_changes['omitted'] ) ) {
					continue;
				}
				$mksddn_mc_change_summary = ( isset( $mksddn_mc_changes['summary'] ) && is_array( $mksddn_mc_changes['summary'] ) )
					? $mksddn_mc_changes['summary']
					: array();
				$mksddn_mc_changed_count  = (int) ( $mksddn_mc_change_summary['changed_count'] ?? 0 );
				$mksddn_mc_field_count    = (int) ( $mksddn_mc_change_summary['field_count'] ?? 0 );
				$mksddn_mc_tax_count      = (int) ( $mksddn_mc_change_summary['tax_count'] ?? 0 );
				// Skip empty/unchanged items (keep URL-rewrite-only and taxonomy-only rows).
				if ( $mksddn_mc_changed_count <= 0 && $mksddn_mc_field_count <= 0 && $mksddn_mc_tax_count <= 0 ) {
					continue;
				}
				$mksddn_mc_item_title = (string) ( $mksddn_mc_item['title'] ?? '' );
				if ( '' === $mksddn_mc_item_title ) {
					$mksddn_mc_item_title = (string) ( $mksddn_mc_item['slug'] ?? '' );
				}
				$mksddn_mc_featured = ( isset( $mksddn_mc_changes['featured_media'] ) && is_array( $mksddn_mc_changes['featured_media'] ) )
					? $mksddn_mc_changes['featured_media']
					: null;
				?>
				<details class="mksddn-mc-field-diff__item"<?php echo ( $mksddn_mc_changed_count > 0 ) ? ' open' : ''; ?>>
					<summary>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: post title, 2: change count */
								__( '%1$s (%2$d changes)', 'mksddn-migrate-content' ),
								$mksddn_mc_item_title,
								$mksddn_mc_changed_count
							)
						);
						?>
					</summary>
					<?php
					$mksddn_mc_diff_title   = __( 'Core fields', 'mksddn-migrate-content' );
					$mksddn_mc_diff_section = $mksddn_mc_changes['core'] ?? array();
					include MKSDDN_MC_DIR . 'views/admin/partials/field-diff-table.php';

					$mksddn_mc_diff_title   = __( 'ACF fields', 'mksddn-migrate-content' );
					$mksddn_mc_diff_section = $mksddn_mc_changes['acf'] ?? array();
					include MKSDDN_MC_DIR . 'views/admin/partials/field-diff-table.php';

					$mksddn_mc_diff_title   = __( 'Meta', 'mksddn-migrate-content' );
					$mksddn_mc_diff_section = $mksddn_mc_changes['meta'] ?? array();
					include MKSDDN_MC_DIR . 'views/admin/partials/field-diff-table.php';

					if ( ! empty( $mksddn_mc_changes['taxonomies'] ) && is_array( $mksddn_mc_changes['taxonomies'] ) ) :
						?>
						<div class="mksddn-mc-field-diff__section">
							<p class="mksddn-mc-field-diff__section-title"><strong><?php esc_html_e( 'Taxonomies', 'mksddn-migrate-content' ); ?></strong></p>
							<ul class="ul-disc">
								<?php foreach ( $mksddn_mc_changes['taxonomies'] as $mksddn_mc_tax ) : ?>
									<?php
									if ( ! is_array( $mksddn_mc_tax ) ) {
										continue;
									}
									$mksddn_mc_added   = ( ! empty( $mksddn_mc_tax['added_terms'] ) && is_array( $mksddn_mc_tax['added_terms'] ) ) ? $mksddn_mc_tax['added_terms'] : array();
									$mksddn_mc_removed = ( ! empty( $mksddn_mc_tax['removed_terms'] ) && is_array( $mksddn_mc_tax['removed_terms'] ) ) ? $mksddn_mc_tax['removed_terms'] : array();
									?>
									<li>
										<code><?php echo esc_html( (string) ( $mksddn_mc_tax['taxonomy'] ?? '' ) ); ?></code>
										<?php if ( ! empty( $mksddn_mc_added ) ) : ?>
											— <?php esc_html_e( 'add:', 'mksddn-migrate-content' ); ?>
											<?php echo esc_html( implode( ', ', array_map( 'strval', $mksddn_mc_added ) ) ); ?>
										<?php endif; ?>
										<?php if ( ! empty( $mksddn_mc_removed ) ) : ?>
											— <?php esc_html_e( 'remove:', 'mksddn-migrate-content' ); ?>
											<?php echo esc_html( implode( ', ', array_map( 'strval', $mksddn_mc_removed ) ) ); ?>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
						<?php
					endif;

					if ( is_array( $mksddn_mc_featured ) && ! empty( $mksddn_mc_featured['status'] ) ) :
						$mksddn_mc_fm_status = sanitize_key( (string) $mksddn_mc_featured['status'] );
						$mksddn_mc_fm_labels = array(
							'same_file'  => __( 'Featured image: same file (local thumbnail kept)', 'mksddn-migrate-content' ),
							'will_set'   => __( 'Featured image: will set (local has none)', 'mksddn-migrate-content' ),
							'local_kept' => __( 'Featured image: local thumbnail kept (import does not replace or clear)', 'mksddn-migrate-content' ),
							'unknown'    => __( 'Featured image: present in archive (file mapping unknown; set only if local has none)', 'mksddn-migrate-content' ),
							'unchanged'  => __( 'Featured image: unchanged', 'mksddn-migrate-content' ),
						);
						?>
						<p class="description">
							<?php echo esc_html( $mksddn_mc_fm_labels[ $mksddn_mc_fm_status ] ?? $mksddn_mc_fm_status ); ?>
							<?php if ( ! empty( $mksddn_mc_featured['local_filename'] ) || ! empty( $mksddn_mc_featured['archive_filename'] ) ) : ?>
								<code><?php echo esc_html( (string) ( $mksddn_mc_featured['local_filename'] ?? '' ) ); ?></code>
								→
								<code><?php echo esc_html( (string) ( $mksddn_mc_featured['archive_filename'] ?? '' ) ); ?></code>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</details>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_estimated['options_pages'] ) && is_array( $mksddn_mc_estimated['options_pages'] ) ) : ?>
		<p><strong><?php esc_html_e( 'ACF Options Pages to import', 'mksddn-migrate-content' ); ?></strong></p>
		<table class="widefat striped" style="max-width: 840px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Title', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Menu slug', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'ACF post_id', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Action', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mksddn_mc_estimated['options_pages'] as $mksddn_mc_options_page ) : ?>
					<?php
					if ( ! is_array( $mksddn_mc_options_page ) ) {
						continue;
					}
					$mksddn_mc_op_title  = (string) ( $mksddn_mc_options_page['title'] ?? '' );
					$mksddn_mc_op_slug   = (string) ( $mksddn_mc_options_page['menu_slug'] ?? '' );
					$mksddn_mc_op_action = (string) ( $mksddn_mc_options_page['action'] ?? 'update' );
					if ( '' === $mksddn_mc_op_title ) {
						$mksddn_mc_op_title = $mksddn_mc_op_slug;
					}
					if ( 'missing' === $mksddn_mc_op_action ) {
						$mksddn_mc_op_action_label = __( 'Missing on site', 'mksddn-migrate-content' );
					} else {
						$mksddn_mc_op_action_label = __( 'Update fields', 'mksddn-migrate-content' );
					}
					$mksddn_mc_op_changes = ( isset( $mksddn_mc_options_page['changes']['summary']['field_count'] ) )
						? (int) $mksddn_mc_options_page['changes']['summary']['field_count']
						: 0;
					if ( $mksddn_mc_op_changes > 0 ) {
						$mksddn_mc_op_action_label .= ' · ' . sprintf(
							/* translators: %d: field count */
							_n( '%d field', '%d fields', $mksddn_mc_op_changes, 'mksddn-migrate-content' ),
							$mksddn_mc_op_changes
						);
					}
					?>
					<tr>
						<td><?php echo esc_html( $mksddn_mc_op_title ); ?></td>
						<td><?php echo esc_html( $mksddn_mc_op_slug ); ?></td>
						<td><?php echo esc_html( (string) ( $mksddn_mc_options_page['post_id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $mksddn_mc_op_action_label ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="mksddn-mc-field-diff">
			<?php foreach ( $mksddn_mc_estimated['options_pages'] as $mksddn_mc_options_page ) : ?>
				<?php
				if ( ! is_array( $mksddn_mc_options_page ) ) {
					continue;
				}
				$mksddn_mc_changes = ( isset( $mksddn_mc_options_page['changes'] ) && is_array( $mksddn_mc_options_page['changes'] ) )
					? $mksddn_mc_options_page['changes']
					: array();
				if ( empty( $mksddn_mc_changes ) ) {
					continue;
				}
				$mksddn_mc_op_title      = (string) ( $mksddn_mc_options_page['title'] ?? $mksddn_mc_options_page['menu_slug'] ?? '' );
				$mksddn_mc_changed_count = (int) ( $mksddn_mc_changes['summary']['changed_count'] ?? 0 );
				$mksddn_mc_op_field_count = (int) ( $mksddn_mc_changes['summary']['field_count'] ?? 0 );
				if ( $mksddn_mc_changed_count <= 0 && $mksddn_mc_op_field_count <= 0 ) {
					continue;
				}
				?>
				<details class="mksddn-mc-field-diff__item"<?php echo ( $mksddn_mc_changed_count > 0 ) ? ' open' : ''; ?>>
					<summary>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: options page title, 2: change count */
								__( 'Options: %1$s (%2$d changes)', 'mksddn-migrate-content' ),
								$mksddn_mc_op_title,
								$mksddn_mc_changed_count
							)
						);
						?>
					</summary>
					<?php
					$mksddn_mc_diff_title   = __( 'ACF fields', 'mksddn-migrate-content' );
					$mksddn_mc_diff_section = $mksddn_mc_changes['acf'] ?? array();
					include MKSDDN_MC_DIR . 'views/admin/partials/field-diff-table.php';
					?>
				</details>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php
	$mksddn_mc_media_block  = ( ! empty( $mksddn_mc_estimated['media_files'] ) && is_array( $mksddn_mc_estimated['media_files'] ) )
		? $mksddn_mc_estimated['media_files']
		: array();
	$mksddn_mc_media_sample = ( ! empty( $mksddn_mc_media_block['sample'] ) && is_array( $mksddn_mc_media_block['sample'] ) )
		? $mksddn_mc_media_block['sample']
		: array();
	if ( ! empty( $mksddn_mc_media_sample ) ) :
		$mksddn_mc_media_total = (int) ( $mksddn_mc_media_block['total'] ?? count( $mksddn_mc_media_sample ) );
		?>
		<div class="mksddn-mc-field-diff__section">
			<p><strong><?php esc_html_e( 'Media files in archive', 'mksddn-migrate-content' ); ?></strong></p>
			<ul class="mksddn-mc-theme-diff__files">
				<?php foreach ( $mksddn_mc_media_sample as $mksddn_mc_media_row ) : ?>
					<?php
					if ( ! is_array( $mksddn_mc_media_row ) ) {
						continue;
					}
					$mksddn_mc_media_name = (string) ( $mksddn_mc_media_row['filename'] ?? '' );
					$mksddn_mc_media_size = (int) ( $mksddn_mc_media_row['filesize'] ?? 0 );
					$mksddn_mc_media_host = (string) ( $mksddn_mc_media_row['host'] ?? '' );
					?>
					<li>
						<code><?php echo esc_html( $mksddn_mc_media_name ); ?></code>
						<?php if ( $mksddn_mc_media_size > 0 ) : ?>
							— <?php echo esc_html( size_format( $mksddn_mc_media_size, 1 ) ); ?>
						<?php endif; ?>
						<?php if ( '' !== $mksddn_mc_media_host ) : ?>
							<span class="description">(<?php echo esc_html( $mksddn_mc_media_host ); ?>)</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $mksddn_mc_media_block['truncated'] ) && $mksddn_mc_media_total > count( $mksddn_mc_media_sample ) ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: shown count, 2: total count */
							__( 'Showing %1$d of %2$d media files.', 'mksddn-migrate-content' ),
							count( $mksddn_mc_media_sample ),
							$mksddn_mc_media_total
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_warnings ) ) : ?>
		<p><strong><?php esc_html_e( 'Warnings', 'mksddn-migrate-content' ); ?></strong></p>
		<ul class="ul-disc">
			<?php foreach ( $mksddn_mc_warnings as $mksddn_mc_w ) : ?>
				<li><?php echo esc_html( (string) $mksddn_mc_w ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_errors ) ) : ?>
		<p><strong><?php esc_html_e( 'Errors', 'mksddn-migrate-content' ); ?></strong></p>
		<ul class="ul-disc">
			<?php foreach ( $mksddn_mc_errors as $mksddn_mc_e ) : ?>
				<li><?php echo esc_html( (string) $mksddn_mc_e ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $mksddn_mc_next_step ) : ?>
		<p><?php echo esc_html( $mksddn_mc_next_step ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== $mksddn_mc_preflight_report_id && empty( $mksddn_mc_errors ) ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-preflight-import-form" style="margin: 1rem 0;">
			<?php wp_nonce_field( 'mksddn_mc_unified_import' ); ?>
			<input type="hidden" name="action" value="mksddn_mc_unified_import">
			<input type="hidden" name="preflight_report_id" value="<?php echo esc_attr( $mksddn_mc_preflight_report_id ); ?>">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Start import', 'mksddn-migrate-content' ); ?></button>
		</form>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1rem 0;">
		<?php if ( '' !== $mksddn_mc_preflight_report_id ) : ?>
			<?php wp_nonce_field( 'mksddn_mc_dismiss_preflight_' . $mksddn_mc_preflight_report_id ); ?>
			<input type="hidden" name="action" value="mksddn_mc_dismiss_preflight_report">
			<input type="hidden" name="preflight_report_id" value="<?php echo esc_attr( $mksddn_mc_preflight_report_id ); ?>">
			<button type="submit" class="button"><?php esc_html_e( 'Dismiss report', 'mksddn-migrate-content' ); ?></button>
		<?php else : ?>
			<a class="button" href="<?php echo esc_url( $mksddn_mc_import_page_url ); ?>"><?php esc_html_e( 'Dismiss report', 'mksddn-migrate-content' ); ?></a>
		<?php endif; ?>
	</form>
</div>
