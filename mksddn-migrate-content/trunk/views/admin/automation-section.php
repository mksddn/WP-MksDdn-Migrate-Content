<?php
/**
 * Automation section template.
 *
 * @package MksDdn\MigrateContent
 * @var array  $settings Settings data.
 * @var array  $runs     Recent runs.
 * @var array  $recurrences Available recurrences.
 * @var int|null $next_run Next run timestamp.
 * @var string $next_label Next run label.
 * @var string $last_run Last run label.
 * @var string $enabled_label Enabled label.
 * @var string $timezone Timezone label.
 * @var string $schedule_hint Schedule hint.
 * @var callable $format_history_date Function to format history date.
 * @var callable $format_status_badge Function to format status badge.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Automation & Scheduling', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Schedule automatic full-site backups and keep storage tidy with retention.', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<div class="mksddn-mc-card">
			<h3><?php esc_html_e( 'Schedule settings', 'mksddn-migrate-content' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mksddn_mc_schedule_save' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_schedule_save">

				<div class="mksddn-mc-field">
					<label><input type="checkbox" name="schedule_enabled" value="1"<?php checked( $settings['enabled'], true ); ?>> <?php esc_html_e( 'Enable automatic backups', 'mksddn-migrate-content' ); ?></label>
				</div>

				<p class="description"><strong><?php esc_html_e( 'Schedule preview:', 'mksddn-migrate-content' ); ?></strong> <?php echo wp_kses_post( $schedule_hint ); ?></p>

				<div class="mksddn-mc-field">
					<label for="mksddn-mc-schedule-recurrence"><?php esc_html_e( 'Run frequency', 'mksddn-migrate-content' ); ?></label>
					<select id="mksddn-mc-schedule-recurrence" name="schedule_recurrence">
						<?php
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template loop variables.
						foreach ( $recurrences as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $settings['recurrence'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="mksddn-mc-field">
					<label for="mksddn-mc-schedule-retention"><?php esc_html_e( 'Keep last N archives', 'mksddn-migrate-content' ); ?></label>
					<input type="number" min="1" id="mksddn-mc-schedule-retention" name="schedule_retention" value="<?php echo esc_attr( $settings['retention'] ); ?>">
					<p class="description"><?php esc_html_e( 'Older scheduled backups will be removed automatically.', 'mksddn-migrate-content' ); ?></p>
				</div>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save schedule', 'mksddn-migrate-content' ); ?></button>
			</form>
		</div>

		<div class="mksddn-mc-card">
			<h3><?php esc_html_e( 'Status & history', 'mksddn-migrate-content' ); ?></h3>
			<p><strong><?php esc_html_e( 'Current state:', 'mksddn-migrate-content' ); ?></strong> <?php echo esc_html( $enabled_label ); ?></p>
			<p><strong><?php esc_html_e( 'Last run:', 'mksddn-migrate-content' ); ?></strong> <?php echo esc_html( $last_run ); ?></p>
			<p><strong><?php esc_html_e( 'Next run:', 'mksddn-migrate-content' ); ?></strong> <?php echo esc_html( $next_label ); ?> <span class="description"><?php echo esc_html( $timezone ); ?></span></p>

			<?php if ( ! empty( $settings['last_message'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Last message:', 'mksddn-migrate-content' ); ?></strong> <?php echo wp_kses_post( $settings['last_message'] ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mksddn_mc_schedule_run' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_schedule_run">
				<button type="submit" class="button"><?php esc_html_e( 'Run backup now', 'mksddn-migrate-content' ); ?></button>
			</form>

			<?php \MksDdn\MigrateContent\Core\View\ViewRenderer::render_template( 'admin/schedule-runs-table.php', array( 'runs' => $runs ) ); ?>
		</div>
	</div>
</section>

