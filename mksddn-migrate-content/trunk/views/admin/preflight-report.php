<?php
/**
 * Preflight (dry-run) report block.
 *
 * @package MksDdn\MigrateContent
 *
 * @var array $preflight_report Normalized preflight report.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status = isset( $preflight_report['status'] ) ? sanitize_key( (string) $preflight_report['status'] ) : 'ok';
$notice_class = 'notice-info';
if ( 'error' === $status ) {
	$notice_class = 'notice-error';
} elseif ( 'warning' === $status ) {
	$notice_class = 'notice-warning';
} elseif ( 'ok' === $status ) {
	$notice_class = 'notice-success';
}

$summary           = isset( $preflight_report['summary'] ) && is_array( $preflight_report['summary'] ) ? $preflight_report['summary'] : array();
$warnings          = isset( $preflight_report['warnings'] ) && is_array( $preflight_report['warnings'] ) ? $preflight_report['warnings'] : array();
$errors            = isset( $preflight_report['errors'] ) && is_array( $preflight_report['errors'] ) ? $preflight_report['errors'] : array();
$estimated         = isset( $preflight_report['estimated_changes'] ) && is_array( $preflight_report['estimated_changes'] ) ? $preflight_report['estimated_changes'] : array();
$next_step         = isset( $preflight_report['next_step'] ) ? (string) $preflight_report['next_step'] : '';
$import_type_code  = isset( $preflight_report['import_type'] ) ? sanitize_key( (string) $preflight_report['import_type'] ) : '';
$source_code       = isset( $preflight_report['source'] ) ? sanitize_key( (string) $preflight_report['source'] ) : '';

$import_type_names = array(
	'full'     => __( 'Full site', 'mksddn-migrate-content' ),
	'themes'   => __( 'Theme archive', 'mksddn-migrate-content' ),
	'selected' => __( 'Selected content', 'mksddn-migrate-content' ),
);
$source_names        = array(
	'upload' => __( 'Browser upload', 'mksddn-migrate-content' ),
	'server' => __( 'Server file', 'mksddn-migrate-content' ),
	'chunk'  => __( 'Chunked upload', 'mksddn-migrate-content' ),
);
$import_type_label = isset( $import_type_names[ $import_type_code ] ) ? $import_type_names[ $import_type_code ] : $import_type_code;
$source_label      = isset( $source_names[ $source_code ] ) ? $source_names[ $source_code ] : $source_code;

$payload_type_labels = array(
	'page'   => __( 'Page', 'mksddn-migrate-content' ),
	'post'   => __( 'Post', 'mksddn-migrate-content' ),
	'bundle' => __( 'Bundle (multiple items)', 'mksddn-migrate-content' ),
);

$import_page_url = admin_url( 'admin.php?page=' . \MksDdn\MigrateContent\Config\PluginConfig::text_domain() . '-import' );

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
	return $post_type;
};
?>
<div class="mksddn-mc-preflight-report notice <?php echo esc_attr( $notice_class ); ?>" style="margin: 15px 0;">
	<p><strong><?php esc_html_e( 'Preflight report (no changes were made)', 'mksddn-migrate-content' ); ?></strong></p>
	<p class="description">
		<?php
		printf(
			/* translators: 1: import type, 2: file source */
			esc_html__( 'Detected type: %1$s · Source: %2$s', 'mksddn-migrate-content' ),
			esc_html( $import_type_label ),
			esc_html( $source_label )
		);
		?>
	</p>

	<?php if ( ! empty( $summary ) ) : ?>
		<ul class="ul-disc">
			<?php if ( ! empty( $summary['file_name'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: file name */
							__( 'File: %s', 'mksddn-migrate-content' ),
							(string) $summary['file_name']
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( ! empty( $summary['file_size'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: formatted file size */
							__( 'Size: %s', 'mksddn-migrate-content' ),
							size_format( (int) $summary['file_size'], 2 )
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( isset( $summary['payload_type'] ) ) : ?>
				<li>
					<?php
					$ptype_raw = sanitize_key( (string) $summary['payload_type'] );
					$ptype_show = isset( $payload_type_labels[ $ptype_raw ] ) ? $payload_type_labels[ $ptype_raw ] : $ptype_raw;
					echo esc_html(
						sprintf(
							/* translators: %s: payload type label */
							__( 'Payload type: %s', 'mksddn-migrate-content' ),
							$ptype_show
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( isset( $summary['media_files'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: media file count */ __( 'Media files in archive: %d', 'mksddn-migrate-content' ), (int) $summary['media_files'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $summary['slug_conflicts_count'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'Slug overlap with existing content: %d', 'mksddn-migrate-content' ), (int) $summary['slug_conflicts_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $summary['users_in_archive'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: user count */ __( 'Users in archive: %d', 'mksddn-migrate-content' ), (int) $summary['users_in_archive'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $summary['user_conflicts'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: conflict count */ __( 'Potential user email conflicts: %d', 'mksddn-migrate-content' ), (int) $summary['user_conflicts'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( isset( $summary['theme_count'] ) ) : ?>
				<li><?php echo esc_html( sprintf( /* translators: %d: theme count */ __( 'Themes in archive: %d', 'mksddn-migrate-content' ), (int) $summary['theme_count'] ) ); ?></li>
			<?php endif; ?>
			<?php if ( ! empty( $summary['themes'] ) && is_array( $summary['themes'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated theme directory slugs */
							__( 'Theme slugs: %s', 'mksddn-migrate-content' ),
							implode( ', ', array_map( 'sanitize_text_field', $summary['themes'] ) )
						)
					);
					?>
				</li>
			<?php endif; ?>
			<?php if ( ! empty( $summary['existing_slugs'] ) && is_array( $summary['existing_slugs'] ) ) : ?>
				<li>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: comma-separated theme slugs */
							__( 'Already installed themes (may be replaced): %s', 'mksddn-migrate-content' ),
							implode( ', ', array_map( 'sanitize_text_field', $summary['existing_slugs'] ) )
						)
					);
					?>
				</li>
			<?php endif; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $estimated['slug_conflicts'] ) && is_array( $estimated['slug_conflicts'] ) ) : ?>
		<p><strong><?php esc_html_e( 'Slug check', 'mksddn-migrate-content' ); ?></strong></p>
		<table class="widefat striped" style="max-width: 640px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Post type', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Existing post ID', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $estimated['slug_conflicts'] as $row ) : ?>
					<?php
					if ( ! is_array( $row ) ) {
						continue;
					}
					?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['slug'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( $mksddn_mc_preflight_post_type_label( (string) ( $row['post_type'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['post_id'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<?php if ( ! empty( $warnings ) ) : ?>
		<p><strong><?php esc_html_e( 'Warnings', 'mksddn-migrate-content' ); ?></strong></p>
		<ul class="ul-disc">
			<?php foreach ( $warnings as $w ) : ?>
				<li><?php echo esc_html( (string) $w ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( ! empty( $errors ) ) : ?>
		<p><strong><?php esc_html_e( 'Errors', 'mksddn-migrate-content' ); ?></strong></p>
		<ul class="ul-disc">
			<?php foreach ( $errors as $e ) : ?>
				<li><?php echo esc_html( (string) $e ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $next_step ) : ?>
		<p><?php echo esc_html( $next_step ); ?></p>
	<?php endif; ?>

	<p>
		<a class="button" href="<?php echo esc_url( $import_page_url ); ?>"><?php esc_html_e( 'Clear report', 'mksddn-migrate-content' ); ?></a>
	</p>
</div>
