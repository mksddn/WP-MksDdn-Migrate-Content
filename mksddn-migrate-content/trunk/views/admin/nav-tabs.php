<?php
/**
 * Shared WordPress-style nav tabs.
 *
 * @package MksDdn\MigrateContent
 *
 * @var array  $mksddn_mc_tabs       Tab map: id => label.
 * @var string $mksddn_mc_active_tab Active tab id.
 * @var string $mksddn_mc_tabs_mode  link|button.
 * @var string $mksddn_mc_tabs_base  Base admin URL for link mode (optional).
 * @var string $mksddn_mc_tabs_class Extra CSS classes (optional).
 * @var string $mksddn_mc_tabs_param Query arg name for link mode (default: tab).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mksddn_mc_tabs       = isset( $mksddn_mc_tabs ) && is_array( $mksddn_mc_tabs ) ? $mksddn_mc_tabs : array();
$mksddn_mc_active_tab = isset( $mksddn_mc_active_tab ) ? (string) $mksddn_mc_active_tab : '';
$mksddn_mc_tabs_mode  = isset( $mksddn_mc_tabs_mode ) ? (string) $mksddn_mc_tabs_mode : 'link';
$mksddn_mc_tabs_base  = isset( $mksddn_mc_tabs_base ) ? (string) $mksddn_mc_tabs_base : '';
$mksddn_mc_tabs_class = isset( $mksddn_mc_tabs_class ) ? (string) $mksddn_mc_tabs_class : '';
$mksddn_mc_tabs_param = isset( $mksddn_mc_tabs_param ) ? (string) $mksddn_mc_tabs_param : 'tab';

if ( empty( $mksddn_mc_tabs ) ) {
	return;
}

$mksddn_mc_wrapper_class = trim( 'nav-tab-wrapper mksddn-mc-nav-tabs ' . $mksddn_mc_tabs_class );
?>
<nav class="<?php echo esc_attr( $mksddn_mc_wrapper_class ); ?>" aria-label="<?php esc_attr_e( 'Section tabs', 'mksddn-migrate-content' ); ?>">
	<?php foreach ( $mksddn_mc_tabs as $mksddn_mc_tab_id => $mksddn_mc_tab_label ) : ?>
		<?php
		$mksddn_mc_tab_id    = (string) $mksddn_mc_tab_id;
		$mksddn_mc_is_active = ( $mksddn_mc_tab_id === $mksddn_mc_active_tab );
		$mksddn_mc_tab_class = 'nav-tab' . ( $mksddn_mc_is_active ? ' nav-tab-active' : '' );
		?>
		<?php if ( 'button' === $mksddn_mc_tabs_mode ) : ?>
			<button
				type="button"
				class="<?php echo esc_attr( $mksddn_mc_tab_class ); ?>"
				data-mksddn-tab="<?php echo esc_attr( $mksddn_mc_tab_id ); ?>"
				aria-selected="<?php echo $mksddn_mc_is_active ? 'true' : 'false'; ?>"
				role="tab"
			>
				<?php echo esc_html( (string) $mksddn_mc_tab_label ); ?>
			</button>
		<?php else : ?>
			<?php
			$mksddn_mc_tab_url = add_query_arg(
				$mksddn_mc_tabs_param,
				$mksddn_mc_tab_id,
				$mksddn_mc_tabs_base
			);
			?>
			<a
				href="<?php echo esc_url( $mksddn_mc_tab_url ); ?>"
				class="<?php echo esc_attr( $mksddn_mc_tab_class ); ?>"
				<?php echo $mksddn_mc_is_active ? 'aria-current="page"' : ''; ?>
			>
				<?php echo esc_html( (string) $mksddn_mc_tab_label ); ?>
			</a>
		<?php endif; ?>
	<?php endforeach; ?>
</nav>
