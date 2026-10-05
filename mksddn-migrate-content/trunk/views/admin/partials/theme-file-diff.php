<?php
/**
 * @file: theme-file-diff.php
 * @description: Renders theme file add/changed/identical/unverified/replace-delete cards
 * @dependencies: None
 * @created: 2026-10-05
 *
 * @var array $mksddn_mc_theme_files Theme file diff rows from preflight.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_theme_files = ( isset( $mksddn_mc_theme_files ) && is_array( $mksddn_mc_theme_files ) ) ? $mksddn_mc_theme_files : array();
if ( empty( $mksddn_mc_theme_files ) ) {
	return;
}
?>
<div class="mksddn-mc-theme-diff">
	<div class="mksddn-mc-theme-diff__intro">
		<p><strong><?php esc_html_e( 'Theme file changes', 'mksddn-migrate-content' ); ?></strong></p>
		<p class="description">
			<?php esc_html_e( 'Counts use Merge semantics: new paths are added; colliding paths are listed as changed (content differs), identical (byte-checked match, including ACF JSON that only differs by modified timestamp), or unverified (same size, content not compared). Merge still writes all colliding archive paths. Replace mode removes the whole theme directory first, then writes all archive files.', 'mksddn-migrate-content' ); ?>
		</p>
	</div>
	<?php foreach ( $mksddn_mc_theme_files as $mksddn_mc_theme_row ) : ?>
		<?php
		if ( ! is_array( $mksddn_mc_theme_row ) ) {
			continue;
		}
		$mksddn_mc_theme_slug        = sanitize_file_name( (string) ( $mksddn_mc_theme_row['slug'] ?? '' ) );
		$mksddn_mc_theme_exists      = ! empty( $mksddn_mc_theme_row['exists'] );
		$mksddn_mc_theme_added       = (int) ( $mksddn_mc_theme_row['added_count'] ?? 0 );
		$mksddn_mc_theme_overwrite   = (int) ( $mksddn_mc_theme_row['overwrite_count'] ?? 0 );
		$mksddn_mc_theme_identical   = (int) ( $mksddn_mc_theme_row['identical_count'] ?? 0 );
		$mksddn_mc_theme_unverified  = (int) ( $mksddn_mc_theme_row['unverified_count'] ?? 0 );
		$mksddn_mc_theme_delete      = (int) ( $mksddn_mc_theme_row['will_delete_on_replace_count'] ?? 0 );
		$mksddn_mc_theme_file_total  = (int) ( $mksddn_mc_theme_row['file_count'] ?? ( $mksddn_mc_theme_added + $mksddn_mc_theme_overwrite + $mksddn_mc_theme_identical + $mksddn_mc_theme_unverified ) );
		$mksddn_mc_sample_added      = ( ! empty( $mksddn_mc_theme_row['sample_added'] ) && is_array( $mksddn_mc_theme_row['sample_added'] ) )
			? $mksddn_mc_theme_row['sample_added']
			: array();
		$mksddn_mc_sample_overwrite  = ( ! empty( $mksddn_mc_theme_row['sample_overwrite'] ) && is_array( $mksddn_mc_theme_row['sample_overwrite'] ) )
			? $mksddn_mc_theme_row['sample_overwrite']
			: array();
		$mksddn_mc_sample_identical  = ( ! empty( $mksddn_mc_theme_row['sample_identical'] ) && is_array( $mksddn_mc_theme_row['sample_identical'] ) )
			? $mksddn_mc_theme_row['sample_identical']
			: array();
		$mksddn_mc_sample_unverified = ( ! empty( $mksddn_mc_theme_row['sample_unverified'] ) && is_array( $mksddn_mc_theme_row['sample_unverified'] ) )
			? $mksddn_mc_theme_row['sample_unverified']
			: array();
		$mksddn_mc_sample_delete = ( ! empty( $mksddn_mc_theme_row['sample_will_delete_on_replace'] ) && is_array( $mksddn_mc_theme_row['sample_will_delete_on_replace'] ) )
			? $mksddn_mc_theme_row['sample_will_delete_on_replace']
			: array();
		$mksddn_mc_truncated_added      = ! empty( $mksddn_mc_theme_row['samples_truncated_added'] );
		$mksddn_mc_truncated_overwrite  = ! empty( $mksddn_mc_theme_row['samples_truncated_overwrite'] );
		$mksddn_mc_truncated_identical  = ! empty( $mksddn_mc_theme_row['samples_truncated_identical'] );
		$mksddn_mc_truncated_unverified = ! empty( $mksddn_mc_theme_row['samples_truncated_unverified'] );
		$mksddn_mc_truncated_delete     = ! empty( $mksddn_mc_theme_row['samples_truncated_will_delete'] );
		$mksddn_mc_counts_only          = ! empty( $mksddn_mc_theme_row['counts_only'] );
		if ( '' === $mksddn_mc_theme_slug ) {
			continue;
		}

		/**
		 * Render a path sample list with optional truncation note.
		 *
		 * @param array  $paths       Paths.
		 * @param int    $total       Total count.
		 * @param bool   $truncated   Whether truncated.
		 * @param string $empty       Empty message when total is 0.
		 * @param bool   $counts_only Whether path samples were stripped for storage.
		 * @return void
		 */
		$mksddn_mc_render_path_list = static function ( array $paths, int $total, bool $truncated, string $empty, bool $counts_only = false ): void {
			if ( empty( $paths ) ) {
				if ( $counts_only && $total > 0 ) {
					echo '<p class="description mksddn-mc-theme-diff__empty">' . esc_html(
						sprintf(
							/* translators: %d: file count */
							__( '%d paths (samples omitted here; open the preflight report for examples).', 'mksddn-migrate-content' ),
							$total
						)
					) . '</p>';
					return;
				}
				echo '<p class="description mksddn-mc-theme-diff__empty">' . esc_html( $empty ) . '</p>';
				return;
			}
			echo '<ul class="mksddn-mc-theme-diff__files">';
			foreach ( $paths as $mksddn_mc_file_path ) {
				echo '<li><code>' . esc_html( (string) $mksddn_mc_file_path ) . '</code></li>';
			}
			echo '</ul>';
			if ( $truncated && $total > count( $paths ) ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: 1: shown count, 2: total count */
						__( 'Showing %1$d of %2$d paths.', 'mksddn-migrate-content' ),
						count( $paths ),
						$total
					)
				) . '</p>';
			}
		};
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
						<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '~%d changed', 'mksddn-migrate-content' ), $mksddn_mc_theme_overwrite ) ); ?>
					</span>
					<?php if ( $mksddn_mc_theme_unverified > 0 ) : ?>
						<span class="mksddn-mc-theme-diff__stat mksddn-mc-theme-diff__stat--unverified">
							<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '?%d unverified', 'mksddn-migrate-content' ), $mksddn_mc_theme_unverified ) ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $mksddn_mc_theme_identical > 0 ) : ?>
						<span class="mksddn-mc-theme-diff__stat mksddn-mc-theme-diff__stat--identical">
							<?php echo esc_html( sprintf( /* translators: %d: file count */ __( '=%d identical', 'mksddn-migrate-content' ), $mksddn_mc_theme_identical ) ); ?>
						</span>
					<?php endif; ?>
				</div>
			</header>

			<div class="mksddn-mc-theme-diff__panels">
				<details class="mksddn-mc-theme-diff__panel mksddn-mc-theme-diff__panel--merge"<?php echo ( $mksddn_mc_theme_added > 0 && 0 === $mksddn_mc_theme_overwrite ) ? ' open' : ''; ?>>
					<summary>
						<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files to add (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_added ) ); ?>
					</summary>
					<?php
					$mksddn_mc_render_path_list(
						$mksddn_mc_sample_added,
						$mksddn_mc_theme_added,
						$mksddn_mc_truncated_added,
						__( 'No new files — all archive paths already exist.', 'mksddn-migrate-content' ),
						$mksddn_mc_counts_only
					);
					?>
				</details>

				<details class="mksddn-mc-theme-diff__panel mksddn-mc-theme-diff__panel--merge"<?php echo ( $mksddn_mc_theme_overwrite > 0 ) ? ' open' : ''; ?>>
					<summary>
						<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Files with changed content (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_overwrite ) ); ?>
					</summary>
					<?php
					$mksddn_mc_render_path_list(
						$mksddn_mc_sample_overwrite,
						$mksddn_mc_theme_overwrite,
						$mksddn_mc_truncated_overwrite,
						__( 'No changed files — theme is new, paths do not collide, or content matches.', 'mksddn-migrate-content' ),
						$mksddn_mc_counts_only
					);
					?>
				</details>

				<?php if ( $mksddn_mc_theme_unverified > 0 ) : ?>
					<details class="mksddn-mc-theme-diff__panel mksddn-mc-theme-diff__panel--merge">
						<summary>
							<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Same size, content not verified (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_unverified ) ); ?>
						</summary>
						<p class="description">
							<?php esc_html_e( 'Preflight skipped a byte-level check (file over 256 KiB and/or hash budget). Merge still writes these paths over existing files.', 'mksddn-migrate-content' ); ?>
						</p>
						<?php
						$mksddn_mc_render_path_list(
							$mksddn_mc_sample_unverified,
							$mksddn_mc_theme_unverified,
							$mksddn_mc_truncated_unverified,
							__( 'No unverified files.', 'mksddn-migrate-content' ),
							$mksddn_mc_counts_only
						);
						?>
					</details>
				<?php endif; ?>

				<?php if ( $mksddn_mc_theme_identical > 0 ) : ?>
					<details class="mksddn-mc-theme-diff__panel mksddn-mc-theme-diff__panel--merge">
						<summary>
							<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Identical files (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_identical ) ); ?>
						</summary>
						<?php
						$mksddn_mc_render_path_list(
							$mksddn_mc_sample_identical,
							$mksddn_mc_theme_identical,
							$mksddn_mc_truncated_identical,
							__( 'No identical files detected.', 'mksddn-migrate-content' ),
							$mksddn_mc_counts_only
						);
						?>
					</details>
				<?php endif; ?>

				<?php if ( $mksddn_mc_theme_exists ) : ?>
					<details class="mksddn-mc-theme-diff__panel mksddn-mc-theme-diff__panel--replace">
						<summary>
							<?php echo esc_html( sprintf( /* translators: %d: file count */ __( 'Local-only files removed on Replace (%d)', 'mksddn-migrate-content' ), $mksddn_mc_theme_delete ) ); ?>
						</summary>
						<?php
						$mksddn_mc_render_path_list(
							$mksddn_mc_sample_delete,
							$mksddn_mc_theme_delete,
							$mksddn_mc_truncated_delete,
							__( 'No local-only files — Replace would not delete extra paths.', 'mksddn-migrate-content' ),
							$mksddn_mc_counts_only
						);
						?>
					</details>
				<?php endif; ?>
			</div>
		</article>
	<?php endforeach; ?>
</div>
