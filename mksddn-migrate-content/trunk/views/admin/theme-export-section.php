<?php
/**
 * Theme export section template.
 *
 * @package MksDdn\MigrateContent
 * @var array $available_themes Available themes for export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="mksddn-mc-section">
	<h2><?php esc_html_e( 'Theme Export', 'mksddn-migrate-content' ); ?></h2>
	<p><?php esc_html_e( 'Export selected themes (active and parent themes are pre-selected).', 'mksddn-migrate-content' ); ?></p>
	<div class="mksddn-mc-grid">
		<div class="mksddn-mc-card">
			<h3><?php esc_html_e( 'Select Themes', 'mksddn-migrate-content' ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mksddn-theme-export="true">
				<?php wp_nonce_field( 'mksddn_mc_theme_export' ); ?>
				<input type="hidden" name="action" value="mksddn_mc_export_themes">
				
				<div class="mksddn-mc-theme-selection">
					<?php if ( ! empty( $available_themes ) ) : ?>
						<?php foreach ( $available_themes as $mksddn_mc_theme_slug => $mksddn_mc_theme_data ) : ?>
							<label class="mksddn-mc-theme-item">
								<input 
									type="checkbox" 
									name="selected_themes[]" 
									value="<?php echo esc_attr( $mksddn_mc_theme_slug ); ?>"
									<?php checked( $mksddn_mc_theme_data['is_active'] || $mksddn_mc_theme_data['is_parent'] ); ?>
								>
								<strong><?php echo esc_html( $mksddn_mc_theme_data['name'] ); ?></strong>
								<?php if ( $mksddn_mc_theme_data['is_active'] ) : ?>
									<span class="mksddn-mc-theme-status"><?php esc_html_e( '(Active)', 'mksddn-migrate-content' ); ?></span>
								<?php elseif ( $mksddn_mc_theme_data['is_parent'] ) : ?>
									<span class="mksddn-mc-theme-status"><?php esc_html_e( '(Parent)', 'mksddn-migrate-content' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $mksddn_mc_theme_data['version'] ) ) : ?>
									<span class="mksddn-mc-theme-version">v<?php echo esc_html( $mksddn_mc_theme_data['version'] ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					<?php else : ?>
						<p><?php esc_html_e( 'No themes found.', 'mksddn-migrate-content' ); ?></p>
					<?php endif; ?>
				</div>
				
				<button type="submit" class="button button-secondary" <?php echo empty( $available_themes ) ? 'disabled' : ''; ?>>
					<?php esc_html_e( 'Export Selected Themes (.wpbkp)', 'mksddn-migrate-content' ); ?>
				</button>
			</form>
		</div>
	</div>
</section>
