<?php
/**
 * @file: AdminPageView.php
 * @description: View class for rendering admin page sections
 * @dependencies: Recovery\HistoryRepository, Automation\ScheduleManager, Users\UserPreviewStore, Config\PluginConfig
 * @created: 2024-12-15
 */

namespace MksDdn\MigrateContent\Admin\Views;

use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Config\PluginConfig;
use MksDdn\MigrateContent\Core\View\ViewRenderer;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Users\UserPreviewStore;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * View class for rendering admin page sections.
 *
 * @since 1.0.0
 */
class AdminPageView {

	/**
	 * History repository.
	 *
	 * @var HistoryRepository
	 */
	private HistoryRepository $history;

	/**
	 * Schedule manager.
	 *
	 * @var ScheduleManager
	 */
	private ScheduleManager $schedule_manager;

	/**
	 * User preview store.
	 *
	 * @var UserPreviewStore
	 */
	private UserPreviewStore $preview_store;

	/**
	 * View renderer.
	 *
	 * @var ViewRenderer
	 */
	private ViewRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param HistoryRepository|null $history          History repository.
	 * @param ScheduleManager|null   $schedule_manager Schedule manager.
	 * @param UserPreviewStore|null  $preview_store    User preview store.
	 * @param ViewRenderer|null      $renderer         View renderer.
	 * @since 1.0.0
	 */
	public function __construct(
		?HistoryRepository $history = null,
		?ScheduleManager $schedule_manager = null,
		?UserPreviewStore $preview_store = null,
		?ViewRenderer $renderer = null
	) {
		$this->history          = $history ?? new HistoryRepository();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->preview_store    = $preview_store ?? new UserPreviewStore();
		$this->renderer         = $renderer ?? new ViewRenderer();
	}

	/**
	 * Render admin page styles.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_styles(): void {
		$this->renderer->render( 'admin/styles.php' );
	}

	/**
	 * Render full site section.
	 *
	 * @param array|null $pending_user_preview Pending user preview data.
	 * @return void
	 * @since 1.0.0
	 */
	public function render_full_site_section( ?array $pending_user_preview = null ): void {
		$this->renderer->render( 'admin/full-site-section.php', array( 'pending_user_preview' => $pending_user_preview ) );
	}

	/**
	 * Render selected content section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_selected_content_section(): void {
		$exportable_types = $this->get_exportable_post_types();
		$items_by_type    = array();

		foreach ( $exportable_types as $type => $label ) {
			$items_by_type[ $type ] = $this->get_items_for_type( $type );
		}

		$this->renderer->render(
			'admin/selected-content-section.php',
			array(
				'exportable_types' => $exportable_types,
				'items_by_type'    => $items_by_type,
			)
		);
	}

	/**
	 * Render history section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_history_section(): void {
		$entries = $this->history->all( 10 );

		// Pre-process entries to format data.
		$processed_entries = array();
		foreach ( $entries as $entry ) {
			$context     = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			$snapshot_id = $context['snapshot_id'] ?? '';
			$snapshot_label = $context['snapshot_label'] ?? $snapshot_id;

			$processed_entries[] = array(
				'type'           => $this->describe_history_type( $entry ),
				'status_badge'  => $this->format_status_badge( $entry['status'] ?? '' ),
				'started_at'    => $this->format_history_date( $entry['started_at'] ?? '' ),
				'finished_at'   => $this->format_history_date( $entry['finished_at'] ?? '' ),
				'snapshot_id'    => $snapshot_id,
				'snapshot_label' => $snapshot_label,
				'user_label'     => $this->format_user_label( (int) ( $entry['user_id'] ?? 0 ) ),
				'actions'        => $this->render_history_actions( $entry ),
			);
		}

		$this->renderer->render(
			'admin/history-section.php',
			array(
				'entries' => $processed_entries,
			)
		);
	}

	/**
	 * Get exportable post types.
	 *
	 * @return array
	 * @since 1.0.0
	 */
	private function get_exportable_post_types(): array {
		$objects = get_post_types(
			array(
				'show_ui' => true,
				'public'  => true,
			),
			'objects'
		);

		$types = array();
		foreach ( $objects as $type => $object ) {
			if ( in_array( $type, array( 'attachment', 'revision', 'nav_menu_item' ), true ) ) {
				continue;
			}
			$types[ $type ] = $object->labels->singular_name ?? $object->label ?? ucfirst( $type );
		}

		if ( ! isset( $types['page'] ) ) {
			$types = array( 'page' => __( 'Page', 'mksddn-migrate-content' ) ) + $types;
		}

		return $types;
	}

	/**
	 * Get items for post type.
	 *
	 * @param string $type Post type.
	 * @return WP_Post[]
	 * @since 1.0.0
	 */
	private function get_items_for_type( string $type ): array {
		if ( 'page' === $type ) {
			return get_pages();
		}

		return get_posts(
			array(
				'post_type'      => $type,
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
	}


	/**
	 * Describe history entry type.
	 *
	 * @param array $entry Entry payload.
	 * @return string
	 * @since 1.0.0
	 */
	private function describe_history_type( array $entry ): string {
		$type   = sanitize_key( $entry['type'] ?? '' );
		$labels = array(
			'import'   => __( 'Import', 'mksddn-migrate-content' ),
			'export'   => __( 'Export', 'mksddn-migrate-content' ),
			'rollback' => __( 'Rollback', 'mksddn-migrate-content' ),
			'snapshot' => __( 'Snapshot', 'mksddn-migrate-content' ),
		);

		$label = $labels[ $type ] ?? ucfirst( $type );
		$mode  = $entry['context']['mode'] ?? '';
		if ( $mode ) {
			$label .= ' · ' . ucfirst( sanitize_text_field( $mode ) );
		}

		return $label;
	}

	/**
	 * Format status badge.
	 *
	 * @param string $status Status slug.
	 * @return string
	 * @since 1.0.0
	 */
	private function format_status_badge( string $status ): string {
		$status = sanitize_key( $status );
		$map    = array(
			'success'   => array( 'class' => 'success', 'label' => __( 'Success', 'mksddn-migrate-content' ) ),
			'running'   => array( 'class' => 'running', 'label' => __( 'Running', 'mksddn-migrate-content' ) ),
			'cancelled' => array( 'class' => 'error', 'label' => __( 'Cancelled', 'mksddn-migrate-content' ) ),
			'error'     => array( 'class' => 'error', 'label' => __( 'Error', 'mksddn-migrate-content' ) ),
		);

		$data = $map[ $status ] ?? $map['error'];

		return sprintf(
			'<span class="mksddn-mc-badge mksddn-mc-badge--%1$s">%2$s</span>',
			esc_attr( $data['class'] ),
			esc_html( $data['label'] )
		);
	}

	/**
	 * Format history date.
	 *
	 * @param string $date Date string.
	 * @return string
	 * @since 1.0.0
	 */
	private function format_history_date( string $date ): string {
		if ( empty( $date ) ) {
			return '—';
		}

		$timestamp = strtotime( $date );
		if ( false === $timestamp ) {
			return $date;
		}

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $format, $timestamp );
	}

	/**
	 * Format user label.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 * @since 1.0.0
	 */
	private function format_user_label( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return __( 'System', 'mksddn-migrate-content' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return sprintf(
				/* translators: %d is user ID */
				__( 'User #%d', 'mksddn-migrate-content' ),
				$user_id
			);
		}

		return $user->display_name ?: $user->user_login;
	}

	/**
	 * Render history actions.
	 *
	 * @param array $entry Entry payload.
	 * @return string
	 * @since 1.0.0
	 */
	private function render_history_actions( array $entry ): string {
		$context     = $entry['context'] ?? array();
		$snapshot_id = sanitize_text_field( $context['snapshot_id'] ?? '' );
		$history_id  = sanitize_text_field( $entry['id'] ?? '' );
		$actions     = array();

		if ( $this->can_rollback_entry( $entry ) ) {
			$nonce = wp_nonce_field( 'mksddn_mc_rollback_' . $history_id, '_wpnonce', true, false );
			$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$form .= $nonce;
			$form .= '<input type="hidden" name="action" value="mksddn_mc_rollback_snapshot">';
			$form .= '<input type="hidden" name="snapshot_id" value="' . esc_attr( $snapshot_id ) . '">';
			$form .= '<input type="hidden" name="history_id" value="' . esc_attr( $history_id ) . '">';
			$form .= '<button type="submit" class="button button-small">' . esc_html__( 'Rollback', 'mksddn-migrate-content' ) . '</button>';
			$form .= '</form>';
			$actions[] = $form;
		}

		if ( $snapshot_id ) {
			$nonce = wp_nonce_field( 'mksddn_mc_delete_snapshot_' . $history_id, '_wpnonce', true, false );
			$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			$form .= $nonce;
			$form .= '<input type="hidden" name="action" value="mksddn_mc_delete_snapshot">';
			$form .= '<input type="hidden" name="snapshot_id" value="' . esc_attr( $snapshot_id ) . '">';
			$form .= '<input type="hidden" name="history_id" value="' . esc_attr( $history_id ) . '">';
			$form .= '<button type="submit" class="button button-small button-secondary" onclick="return confirm(\'' . esc_js( __( 'Delete this backup permanently?', 'mksddn-migrate-content' ) ) . '\');">' . esc_html__( 'Delete backup', 'mksddn-migrate-content' ) . '</button>';
			$form .= '</form>';
			$actions[] = $form;
		}

		if ( empty( $actions ) ) {
			return '&mdash;';
		}

		return '<div class="mksddn-mc-history__actions">' . implode( '', $actions ) . '</div>';
	}

	/**
	 * Check if entry can be rolled back.
	 *
	 * @param array $entry Entry data.
	 * @return bool
	 * @since 1.0.0
	 */
	private function can_rollback_entry( array $entry ): bool {
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

		return (
			'import' === ( $entry['type'] ?? '' )
			&& 'success' === ( $entry['status'] ?? '' )
			&& ! empty( $context['snapshot_id'] )
		);
	}

	/**
	 * Render automation section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_automation_section(): void {
		$settings      = $this->schedule_manager->get_settings();
		$runs          = $this->schedule_manager->get_recent_runs();
		$recurrences   = $this->schedule_manager->get_available_recurrences();
		$next_run      = $this->schedule_manager->get_next_run_time();
		$next_label    = $next_run ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) : __( 'Not scheduled', 'mksddn-migrate-content' );
		$last_run      = ! empty( $settings['last_run'] ) ? $this->format_history_date( $settings['last_run'] ) : __( 'Never', 'mksddn-migrate-content' );
		$enabled_label = $settings['enabled'] ? __( 'Enabled', 'mksddn-migrate-content' ) : __( 'Disabled', 'mksddn-migrate-content' );
		$timezone      = $this->get_timezone_label();
		$schedule_hint = $settings['enabled']
			? sprintf(
				/* translators: 1 recurrence label, 2 next run timestamp, 3 timezone */
				__( '%1$s · next run %2$s (%3$s)', 'mksddn-migrate-content' ),
				esc_html( $recurrences[ $settings['recurrence'] ] ?? ucfirst( $settings['recurrence'] ) ),
				esc_html( $next_run ? $next_label : __( 'pending cron trigger', 'mksddn-migrate-content' ) ),
				esc_html( $timezone )
			)
			: __( 'Schedule disabled', 'mksddn-migrate-content' );

		// Pre-process runs to format data.
		$processed_runs = array();
		foreach ( $runs as $run ) {
			$processed_runs[] = array(
				'created_at'   => $this->format_history_date( $run['created_at'] ?? '' ),
				'status_badge' => $this->format_status_badge( $run['status'] ?? '' ),
				'filename'     => isset( $run['file']['name'] ) ? basename( $run['file']['name'] ) : '',
				'size'         => isset( $run['file']['size'] ) ? size_format( (int) $run['file']['size'] ) : '—',
				'message'      => isset( $run['message'] ) && '' !== $run['message'] ? $run['message'] : '',
			);
		}

		$this->renderer->render(
			'admin/automation-section.php',
			array(
				'settings'     => $settings,
				'runs'         => $processed_runs,
				'recurrences'  => $recurrences,
				'next_run'     => $next_run,
				'next_label'   => $next_label,
				'last_run'     => $last_run,
				'enabled_label' => $enabled_label,
				'timezone'     => $timezone,
				'schedule_hint' => $schedule_hint,
			)
		);
	}

	/**
	 * Get timezone label.
	 *
	 * @return string
	 * @since 1.0.0
	 */
	private function get_timezone_label(): string {
		$timezone = get_option( 'timezone_string' );
		if ( $timezone ) {
			return $timezone;
		}

		$offset = (float) get_option( 'gmt_offset', 0 );
		if ( 0 === $offset ) {
			return 'UTC';
		}

		$hours   = (int) $offset;
		$minutes = abs( $offset - $hours ) * 60;

		return sprintf( 'UTC%+d:%02d', $hours, $minutes );
	}
}

