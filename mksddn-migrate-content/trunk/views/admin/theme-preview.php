<?php
/**
 * @file: theme-preview.php
 * @description: Theme import preview template
 * @dependencies: partials/theme-file-diff.php
 * @created: 2026-02-21
 */

/**
 * Theme preview template.
 *
 * @package MksDdn\MigrateContent
 * @var array $preview Preview data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_theme_files = ( ! empty( $preview['theme_files'] ) && is_array( $preview['theme_files'] ) )
	? $preview['theme_files']
	: array();

$mksddn_mc_preflight_report_id = isset( $preview['preflight_report_id'] )
	? sanitize_text_field( (string) $preview['preflight_report_id'] )
	: '';
$mksddn_mc_preflight_report_url = '';
if ( '' !== $mksddn_mc_preflight_report_id ) {
	$mksddn_mc_preflight_report_url = add_query_arg(
		array(
			'page'                => \MksDdn\MigrateContent\Config\PluginConfig::text_domain() . '-import',
			'mksddn_mc_preflight' => $mksddn_mc_preflight_report_id,
			'_wpnonce'            => wp_create_nonce( 'mksddn_mc_preflight_' . $mksddn_mc_preflight_report_id ),
		),
		admin_url( 'admin.php' )
	);
}

$mksddn_mc_totals = array(
	'added'      => 0,
	'overwrite'  => 0,
	'identical'  => 0,
	'unverified' => 0,
	'delete'     => 0,
	'archive'    => 0,
);
foreach ( $mksddn_mc_theme_files as $mksddn_mc_theme_row ) {
	if ( ! is_array( $mksddn_mc_theme_row ) ) {
		continue;
	}
	$mksddn_mc_totals['added']      += (int) ( $mksddn_mc_theme_row['added_count'] ?? 0 );
	$mksddn_mc_totals['overwrite']  += (int) ( $mksddn_mc_theme_row['overwrite_count'] ?? 0 );
	$mksddn_mc_totals['identical']  += (int) ( $mksddn_mc_theme_row['identical_count'] ?? 0 );
	$mksddn_mc_totals['unverified'] += (int) ( $mksddn_mc_theme_row['unverified_count'] ?? 0 );
	$mksddn_mc_totals['delete']     += (int) ( $mksddn_mc_theme_row['will_delete_on_replace_count'] ?? 0 );
	$mksddn_mc_totals['archive']    += (int) ( $mksddn_mc_theme_row['file_count'] ?? 0 );
}
?>
<h3><?php esc_html_e( 'Choose theme import mode', 'mksddn-migrate-content' ); ?></h3>
<p><?php esc_html_e( 'Select how the theme archive should be applied to this site.', 'mksddn-migrate-content' ); ?></p>
<p><strong><?php esc_html_e( 'Archive', 'mksddn-migrate-content' ); ?>:</strong> <?php echo esc_html( $preview['original_name'] ?: __( 'uploaded file', 'mksddn-migrate-content' ) ); ?></p>
<div class="notice notice-warning" style="margin: 15px 0;">
	<p>
		<strong><?php esc_html_e( 'Warning:', 'mksddn-migrate-content' ); ?></strong>
		<?php esc_html_e( 'Replace removes the existing theme directory before importing. If the theme is active, the site may be temporarily unavailable until import completes. Consider switching to a safe theme or using Merge.', 'mksddn-migrate-content' ); ?>
	</p>
</div>

<?php
if ( ! empty( $mksddn_mc_theme_files ) ) {
	include MKSDDN_MC_DIR . 'views/admin/partials/theme-file-diff.php';
}
if ( '' !== $mksddn_mc_preflight_report_url ) :
	?>
	<p class="description">
		<a href="<?php echo esc_url( $mksddn_mc_preflight_report_url ); ?>">
			<?php esc_html_e( 'Back to preflight report (path samples and full inventory)', 'mksddn-migrate-content' ); ?>
		</a>
	</p>
	<?php
endif;
?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-theme-plan">
	<?php wp_nonce_field( 'mksddn_mc_theme_preview_' . $preview['id'] ); ?>
	<input type="hidden" name="action" value="mksddn_mc_import_theme">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">

	<div class="mksddn-mc-field">
		<label>
			<input type="radio" name="import_mode" value="replace" checked>
			<span>
				<strong><?php esc_html_e( 'Replace', 'mksddn-migrate-content' ); ?></strong>
				<span class="description"><?php esc_html_e( 'Remove existing theme directory and replace with files from archive.', 'mksddn-migrate-content' ); ?></span>
			</span>
		</label>
		<label>
			<input type="radio" name="import_mode" value="merge">
			<span>
				<strong><?php esc_html_e( 'Merge', 'mksddn-migrate-content' ); ?></strong>
				<span class="description"><?php esc_html_e( 'Combine files from archive with existing theme. Files from archive will overwrite existing files.', 'mksddn-migrate-content' ); ?></span>
			</span>
		</label>
	</div>

	<div class="mksddn-mc-theme-mode-summary mksddn-mc-theme-mode-summary--replace" data-mode="replace">
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: archive file count, 2: local-only delete count */
					__( 'Replace will write %1$d archive files and remove about %2$d local-only files from installed themes.', 'mksddn-migrate-content' ),
					$mksddn_mc_totals['archive'],
					$mksddn_mc_totals['delete']
				)
			);
			?>
		</p>
	</div>
	<div class="mksddn-mc-theme-mode-summary mksddn-mc-theme-mode-summary--merge" data-mode="merge" hidden>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: add count, 2: changed content count, 3: unverified count, 4: identical count */
					__( 'Merge will add %1$d files and write %2$d files with changed content. %3$d same-size files were not byte-checked (unverified). %4$d files already match on disk (content identical). Merge still writes all colliding archive paths over existing files.', 'mksddn-migrate-content' ),
					$mksddn_mc_totals['added'],
					$mksddn_mc_totals['overwrite'],
					$mksddn_mc_totals['unverified'],
					$mksddn_mc_totals['identical']
				)
			);
			?>
		</p>
	</div>

	<div class="mksddn-mc-user-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Import themes', 'mksddn-migrate-content' ); ?></button>
	</div>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-inline-form">
	<?php wp_nonce_field( 'mksddn_mc_cancel_theme_preview_' . $preview['id'] ); ?>
	<input type="hidden" name="action" value="mksddn_mc_cancel_theme_preview">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">
	<button type="submit" class="button button-secondary"><?php esc_html_e( 'Cancel theme import', 'mksddn-migrate-content' ); ?></button>
</form>
