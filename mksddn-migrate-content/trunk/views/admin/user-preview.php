<?php
/**
 * User preview template.
 *
 * @package MksDdn\MigrateContent
 * @var array $preview Preview data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$summary = $preview['summary'] ?? array();
$incoming = $summary['incoming'] ?? array();
$counts   = $summary['counts'] ?? array();
$total    = (int) ( $counts['incoming'] ?? count( $incoming ) );
$conflict = (int) ( $counts['conflicts'] ?? 0 );
?>
<h3><?php esc_html_e( 'Review users before import', 'mksddn-migrate-content' ); ?></h3>
<p><?php esc_html_e( 'Pick which users from the archive should be added or overwrite existing accounts on this site.', 'mksddn-migrate-content' ); ?></p>
<p><strong><?php esc_html_e( 'Archive', 'mksddn-migrate-content' ); ?>:</strong> <?php echo esc_html( $preview['original_name'] ?: __( 'uploaded file', 'mksddn-migrate-content' ) ); ?></p>
<p><strong><?php esc_html_e( 'Users detected', 'mksddn-migrate-content' ); ?>:</strong> <?php echo esc_html( $total ); ?> &middot; <strong><?php esc_html_e( 'Conflicts', 'mksddn-migrate-content' ); ?>:</strong> <?php echo esc_html( $conflict ); ?></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-user-plan">
	<?php wp_nonce_field( 'mksddn_mc_full_import' ); ?>
	<input type="hidden" name="action" value="mksddn_mc_import_full">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">

	<div class="mksddn-mc-user-table-wrapper">
		<table class="widefat striped mksddn-mc-user-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Include', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Email', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Archive role', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Current role', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Status', 'mksddn-migrate-content' ); ?></th>
					<th><?php esc_html_e( 'Conflict handling', 'mksddn-migrate-content' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $incoming as $entry ) : ?>
					<?php
					$email = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
					if ( ! $email ) {
						continue;
					}

					$key       = md5( strtolower( $email ) );
					$checkbox  = 'mksddn-mc-user-' . $key;
					$status    = sanitize_text_field( $entry['status'] ?? 'new' );
					$local     = $entry['local_role'] ?? '';
					$role      = $entry['role'] ?? '';
					$status_label = 'conflict' === $status ? __( 'Existing user', 'mksddn-migrate-content' ) : __( 'New user', 'mksddn-migrate-content' );
					?>
					<tr>
						<td>
							<input type="hidden" name="user_plan[<?php echo esc_attr( $key ); ?>][email]" value="<?php echo esc_attr( $email ); ?>">
							<input type="hidden" name="user_plan[<?php echo esc_attr( $key ); ?>][import]" value="0">
							<?php
							/* translators: %s: user email. */
							$label_text = sprintf( esc_html__( 'Include %s', 'mksddn-migrate-content' ), esc_html( $email ) );
							?>
							<label class="screen-reader-text" for="<?php echo esc_attr( $checkbox ); ?>"><?php echo esc_html( $label_text ); ?></label>
							<input type="checkbox" id="<?php echo esc_attr( $checkbox ); ?>" name="user_plan[<?php echo esc_attr( $key ); ?>][import]" value="1" checked>
						</td>
						<td><strong><?php echo esc_html( $email ); ?></strong><br><span class="description"><?php echo esc_html( $entry['login'] ?? '' ); ?></span></td>
						<td><?php echo esc_html( $role ?: '—' ); ?></td>
						<td><?php echo esc_html( $local ?: '—' ); ?></td>
						<td><?php echo esc_html( $status_label ); ?></td>
						<td>
							<?php if ( 'conflict' === $status ) : ?>
								<?php
								$select_id = 'mksddn-mc-user-mode-' . $key;
								$label_text = esc_html__( 'Conflict handling', 'mksddn-migrate-content' );
								?>
								<label class="screen-reader-text" for="<?php echo esc_attr( $select_id ); ?>"><?php echo esc_html( $label_text ); ?></label>
								<select id="<?php echo esc_attr( $select_id ); ?>" name="user_plan[<?php echo esc_attr( $key ); ?>][mode]">
									<option value="keep"><?php esc_html_e( 'Keep current user', 'mksddn-migrate-content' ); ?></option>
									<option value="replace"><?php esc_html_e( 'Replace with archive', 'mksddn-migrate-content' ); ?></option>
								</select>
							<?php else : ?>
								<input type="hidden" name="user_plan[<?php echo esc_attr( $key ); ?>][mode]" value="replace">
								<span class="description"><?php esc_html_e( 'Will be created', 'mksddn-migrate-content' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>

				<?php if ( empty( $incoming ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No users detected inside the archive.', 'mksddn-migrate-content' ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<div class="mksddn-mc-user-actions">
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply selection and import', 'mksddn-migrate-content' ); ?></button>
	</div>
</form>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mksddn-mc-inline-form">
	<?php wp_nonce_field( 'mksddn_mc_cancel_preview_' . $preview['id'] ); ?>
	<input type="hidden" name="action" value="mksddn_mc_cancel_user_preview">
	<input type="hidden" name="preview_id" value="<?php echo esc_attr( $preview['id'] ); ?>">
	<button type="submit" class="button button-secondary"><?php esc_html_e( 'Cancel user selection', 'mksddn-migrate-content' ); ?></button>
</form>

