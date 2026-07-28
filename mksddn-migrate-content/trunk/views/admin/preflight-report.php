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
				<li><?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files to overwrite (merge): %d', 'mksddn-migrate-content' ), (int) $mksddn_mc_summary['files_overwrite'] ) ); ?></li>
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
	?>
	<?php if ( ! empty( $mksddn_mc_theme_files ) ) : ?>
		<div class="mksddn-mc-theme-diff">
			<div class="mksddn-mc-theme-diff__intro">
				<p><strong><?php esc_html_e( 'Theme file changes', 'mksddn-migrate-content' ); ?></strong></p>
				<p class="description">
					<?php esc_html_e( 'Counts use Merge semantics: new paths are added, matching paths are overwritten. Replace mode removes the whole theme directory first, then writes all archive files.', 'mksddn-migrate-content' ); ?>
				</p>
			</div>
			<?php foreach ( $mksddn_mc_theme_files as $mksddn_mc_theme_row ) : ?>
				<?php
				if ( ! is_array( $mksddn_mc_theme_row ) ) {
					continue;
				}
				$mksddn_mc_theme_slug       = sanitize_file_name( (string) ( $mksddn_mc_theme_row['slug'] ?? '' ) );
				$mksddn_mc_theme_exists     = ! empty( $mksddn_mc_theme_row['exists'] );
				$mksddn_mc_theme_added      = (int) ( $mksddn_mc_theme_row['added_count'] ?? 0 );
				$mksddn_mc_theme_overwrite  = (int) ( $mksddn_mc_theme_row['overwrite_count'] ?? 0 );
				$mksddn_mc_theme_file_total = (int) ( $mksddn_mc_theme_row['file_count'] ?? ( $mksddn_mc_theme_added + $mksddn_mc_theme_overwrite ) );
				$mksddn_mc_sample_added     = ( ! empty( $mksddn_mc_theme_row['sample_added'] ) && is_array( $mksddn_mc_theme_row['sample_added'] ) )
					? $mksddn_mc_theme_row['sample_added']
					: array();
				$mksddn_mc_sample_overwrite = ( ! empty( $mksddn_mc_theme_row['sample_overwrite'] ) && is_array( $mksddn_mc_theme_row['sample_overwrite'] ) )
					? $mksddn_mc_theme_row['sample_overwrite']
					: array();
				$mksddn_mc_truncated_added = ! empty( $mksddn_mc_theme_row['samples_truncated_added'] );
				$mksddn_mc_truncated_overwrite = ! empty( $mksddn_mc_theme_row['samples_truncated_overwrite'] );
				if ( '' === $mksddn_mc_theme_slug ) {
					continue;
				}
				?>
				<article class="mksddn-mc-theme-diff__card">
					<header class="mksddn-mc-theme-diff__header">
						<div class="mksddn-mc-theme-diff__title">
							<span class="mksddn-mc-theme-diff__slug"><?php echo esc_html( $mksddn_mc_theme_slug ); ?></span>
							<?php if ( $mksddn_mc_theme_exists ) : ?>
								<span class="mksddn-mc-theme-diff__badge mksddn-mc-theme-diff__badge--exists"><?php esc_html_e( 'Installed', 'mksddn-migrate-content' ); ?></span>
							<?php else : ?>
								<span class="mksddn-mc-theme-diff__badge mksddn-mc-theme-diff__badge--new"><?php esc_html_e( 'New', 'mksddn-migrate-content' ); ?></span>
							<?php endif; ?>
						</div>
						<div class="mksddn-mc-theme-diff__stats" aria-label="<?php esc_attr_e( 'File change counts', 'mksddn-migrate-content' ); ?>">
							<span class="mksddn-mc-theme-diff__stat mksddn-mc-theme-diff__stat--total">
								<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '%d files', 'mksddn-migrate-content' ), $mksddn_mc_theme_file_total ) ); ?>
							</span>
							<span class="mksddn-mc-theme-diff__stat mksddn-mc-theme-diff__stat--added">
								<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '+%d add', 'mksddn-migrate-content' ), $mksddn_mc_theme_added ) ); ?>
							</span>
							<span class="mksddn-mc-theme-diff__stat mksddn-mc-theme-diff__stat--overwrite">
								<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '~%d overwrite', 'mksddn-migrate-content' ), $mksddn_mc_theme_overwrite ) ); ?>
							</span>
						</div>
					</header>

					<div class="mksddn-mc-theme-diff__panels">
						<details class="mksddn-mc-theme-diff__panel"<?php echo ( $mksddn_mc_theme_added > 0 && 0 === $mksddn_mc_theme_overwrite ) ? ' open' : ''; ?>>
							<summary>
								<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files to add (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_added ) ); ?>
							</summary>
							<?php if ( empty( $mksddn_mc_sample_added ) ) : ?>
								<p class="description mksddn-mc-theme-diff__empty"><?php esc_html_e( 'No new files — all archive paths already exist.', 'mksddn-migrate-content' ); ?></p>
							<?php else : ?>
								<ul class="mksddn-mc-theme-diff__files">
									<?php foreach ( $mksddn_mc_sample_added as $mksddn_mc_file_path ) : ?>
										<li><code><?php echo esc_html( (string) $mksddn_mc_file_path ); ?></code></li>
									<?php endforeach; ?>
								</ul>
								<?php if ( $mksddn_mc_truncated_added && $mksddn_mc_theme_added > count( $mksddn_mc_sample_added ) ) : ?>
									<p class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: shown count, 2: total count */
												__( 'Showing %1$d of %2$d paths.', 'mksddn-migrate-content' ),
												count( $mksddn_mc_sample_added ),
												$mksddn_mc_theme_added
											)
										);
										?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</details>

						<details class="mksddn-mc-theme-diff__panel"<?php echo ( $mksddn_mc_theme_overwrite > 0 ) ? ' open' : ''; ?>>
							<summary>
								<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files to overwrite (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_overwrite ) ); ?>
							</summary>
							<?php if ( empty( $mksddn_mc_sample_overwrite ) ) : ?>
								<p class="description mksddn-mc-theme-diff__empty"><?php esc_html_e( 'No overwrites — theme is new or paths do not collide.', 'mksddn-migrate-content' ); ?></p>
							<?php else : ?>
								<ul class="mksddn-mc-theme-diff__files">
									<?php foreach ( $mksddn_mc_sample_overwrite as $mksddn_mc_file_path ) : ?>
										<li><code><?php echo esc_html( (string) $mksddn_mc_file_path ); ?></code></li>
									<?php endforeach; ?>
								</ul>
								<?php if ( $mksddn_mc_truncated_overwrite && $mksddn_mc_theme_overwrite > count( $mksddn_mc_sample_overwrite ) ) : ?>
									<p class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: shown count, 2: total count */
												__( 'Showing %1$d of %2$d paths.', 'mksddn-migrate-content' ),
												count( $mksddn_mc_sample_overwrite ),
												$mksddn_mc_theme_overwrite
											)
										);
										?>
									</p>
								<?php endif; ?>
							<?php endif; ?>
						</details>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $mksddn_mc_estimated['items'] ) && is_array( $mksddn_mc_estimated['items'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Content to import', 'mksddn-migrate-content' ); ?></strong></p>
		<table class="widefat striped" style="max-width: 720px;">
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
					if ( 'update' === $mksddn_mc_item_action ) {
						$mksddn_mc_existing_id = (int) ( $mksddn_mc_item['existing_post_id'] ?? 0 );
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

	<p>
		<a class="button" href="<?php echo esc_url( $mksddn_mc_import_page_url ); ?>"><?php esc_html_e( 'Dismiss report', 'mksddn-migrate-content' ); ?></a>
	</p>
</div>
