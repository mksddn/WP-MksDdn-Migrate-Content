<?php
/**
 * Export page template.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap mksddn-mc-export-page">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<div class="mksddn-mc-export-form">
		<h2><?php esc_html_e( 'Export Options', 'mksddn-migrate-content' ); ?></h2>

		<form id="mksddn-mc-export-form">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="export-type"><?php esc_html_e( 'Export Type', 'mksddn-migrate-content' ); ?></label>
						</th>
						<td>
							<select id="export-type" name="export_type">
								<option value="full"><?php esc_html_e( 'Full Site', 'mksddn-migrate-content' ); ?></option>
								<option value="selective"><?php esc_html_e( 'Selective (by slug)', 'mksddn-migrate-content' ); ?></option>
							</select>
						</td>
					</tr>
					<tr id="selective-options" style="display: none;">
						<th scope="row">
							<label><?php esc_html_e( 'Post Types', 'mksddn-migrate-content' ); ?></label>
						</th>
						<td>
							<?php
							$post_types = get_post_types( array( 'public' => true ), 'objects' );
							foreach ( $post_types as $post_type ) :
								?>
								<label>
									<input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $post_type->name ); ?>">
									<?php echo esc_html( $post_type->label ); ?>
								</label><br>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr id="selective-slugs" style="display: none;">
						<th scope="row">
							<label for="post-slugs"><?php esc_html_e( 'Post Slugs', 'mksddn-migrate-content' ); ?></label>
						</th>
						<td>
							<textarea id="post-slugs" name="slugs" rows="5" cols="50" placeholder="<?php esc_attr_e( 'Enter slugs separated by new lines', 'mksddn-migrate-content' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'Enter one slug per line', 'mksddn-migrate-content' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary" id="mksddn-mc-export-button">
					<?php esc_html_e( 'Export', 'mksddn-migrate-content' ); ?>
				</button>
			</p>
		</form>

		<div id="mksddn-mc-export-progress" style="display: none;">
			<div class="mksddn-mc-progress-bar">
				<div class="mksddn-mc-progress-fill"></div>
			</div>
			<p class="mksddn-mc-progress-text"></p>
		</div>

		<div id="mksddn-mc-export-result"></div>
	</div>
</div>

