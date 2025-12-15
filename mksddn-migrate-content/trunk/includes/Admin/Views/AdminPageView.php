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
	 * Constructor.
	 *
	 * @param HistoryRepository|null $history          History repository.
	 * @param ScheduleManager|null   $schedule_manager Schedule manager.
	 * @param UserPreviewStore|null  $preview_store    User preview store.
	 * @since 1.0.0
	 */
	public function __construct(
		?HistoryRepository $history = null,
		?ScheduleManager $schedule_manager = null,
		?UserPreviewStore $preview_store = null
	) {
		$this->history          = $history ?? new HistoryRepository();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->preview_store    = $preview_store ?? new UserPreviewStore();
	}

	/**
	 * Render admin page styles.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_styles(): void {
		echo '<style>
		.mksddn-mc-section{margin-top:2rem;padding:1.5rem;border:1px solid #e5e7eb;border-radius:12px;background:#fff;}
		.mksddn-mc-section:first-of-type{margin-top:1rem;}
		.mksddn-mc-section h2{margin-top:0;margin-bottom:.25rem;}
		.mksddn-mc-section p{margin-top:0;color:#4b5563;}
		.mksddn-mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;margin-top:1.5rem;}
		.mksddn-mc-card{background:#fdfdfd;border:1px solid #e1e1e1;border-radius:10px;padding:1.25rem;box-shadow:0 1px 2px rgba(15,23,42,0.05);}
		.mksddn-mc-card h3{margin-top:0;}
		.mksddn-mc-card--muted{background:#fafafa;opacity:.75;}
		.mksddn-mc-field,
		.mksddn-mc-basic-selection{margin-bottom:1.25rem;}
		.mksddn-mc-field h4,
		.mksddn-mc-basic-selection h4{margin:0 0 .35rem;font-size:14px;color:#111827;}
		.mksddn-mc-field label,
		.mksddn-mc-basic-selection label{display:block;font-weight:500;margin-bottom:.25rem;}
		.mksddn-mc-basic-selection select,
		.mksddn-mc-field select,
		.mksddn-mc-field input[type="file"],
		.mksddn-mc-format-selector select{width:100%;}
		.mksddn-mc-selection-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
		.mksddn-mc-selection-grid select{min-height:140px;}
		.mksddn-mc-history table{width:100%;border-collapse:collapse;margin-top:1rem;}
		.mksddn-mc-history table th,
		.mksddn-mc-history table td{padding:.5rem .75rem;border-bottom:1px solid #e5e7eb;text-align:left;font-size:13px;}
		.mksddn-mc-history table th{background:#f9fafb;font-weight:600;color:#111827;}
		.mksddn-mc-badge{display:inline-flex;align-items:center;padding:0.1rem 0.55rem;border-radius:999px;font-size:12px;line-height:1.4;}
		.mksddn-mc-badge--success{background:#e6f4ea;color:#1f7a3f;}
		.mksddn-mc-badge--error{background:#fdecea;color:#b42318;}
		.mksddn-mc-badge--running{background:#e0ecff;color:#1d4ed8;}
		.mksddn-mc-history__actions form{display:inline-block;margin-right:.5rem;}
		.mksddn-mc-history__actions button{margin-top:0;}
		.mksddn-mc-user-table-wrapper{max-height:320px;overflow:auto;margin-top:1rem;}
		.mksddn-mc-user-table td select{width:100%;}
		.mksddn-mc-user-actions{margin-top:1rem;}
		.mksddn-mc-inline-form{margin-top:0.75rem;}
		</style>';
	}

	/**
	 * Render full site section.
	 *
	 * @param array|null $pending_user_preview Pending user preview data.
	 * @return void
	 * @since 1.0.0
	 */
	public function render_full_site_section( ?array $pending_user_preview = null ): void {
		echo '<section class="mksddn-mc-section">';
		echo '<h2>' . esc_html__( 'Full Site Backup', 'mksddn-migrate-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Export or import everything (database + wp-content) via chunked transfer.', 'mksddn-migrate-content' ) . '</p>';
		echo '<div class="mksddn-mc-grid">';

		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-mksddn-full-export="true">';
		wp_nonce_field( 'mksddn_mc_full_export' );
		echo '<input type="hidden" name="action" value="mksddn_mc_export_full">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Export Full Site (.wpbkp)', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="mksddn-mc-card">';
		if ( $pending_user_preview ) {
			$this->render_user_preview_card( $pending_user_preview );
		} else {
			$this->render_full_import_form();
		}
		echo '</div>';

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render full import form.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_full_import_form(): void {
		echo '<h3>' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="mksddn-mc-full-import-form" data-mksddn-full-import="true">';
		wp_nonce_field( 'mksddn_mc_full_import' );
		echo '<input type="hidden" name="action" value="mksddn_mc_import_full">';
		echo '<p>' . esc_html__( 'Upload a .wpbkp archive generated by this plugin. Large files will switch to chunked upload automatically.', 'mksddn-migrate-content' ) . '</p>';
		echo '<input type="file" name="full_import_file" accept=".wpbkp" required><br><br>';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Import Full Site', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render user preview card.
	 *
	 * @param array $preview Preview data.
	 * @return void
	 * @since 1.0.0
	 */
	private function render_user_preview_card( array $preview ): void {
		$summary = $preview['summary'] ?? array();
		$incoming = $summary['incoming'] ?? array();
		$counts   = $summary['counts'] ?? array();
		$total    = (int) ( $counts['incoming'] ?? count( $incoming ) );
		$conflict = (int) ( $counts['conflicts'] ?? 0 );

		echo '<h3>' . esc_html__( 'Review users before import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Pick which users from the archive should be added or overwrite existing accounts on this site.', 'mksddn-migrate-content' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Archive', 'mksddn-migrate-content' ) . ':</strong> ' . esc_html( $preview['original_name'] ?: __( 'uploaded file', 'mksddn-migrate-content' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Users detected', 'mksddn-migrate-content' ) . ':</strong> ' . esc_html( $total ) . ' &middot; <strong>' . esc_html__( 'Conflicts', 'mksddn-migrate-content' ) . ':</strong> ' . esc_html( $conflict ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mksddn-mc-user-plan">';
		wp_nonce_field( 'mksddn_mc_full_import' );
		echo '<input type="hidden" name="action" value="mksddn_mc_import_full">';
		echo '<input type="hidden" name="preview_id" value="' . esc_attr( $preview['id'] ) . '">';

		echo '<div class="mksddn-mc-user-table-wrapper">';
		echo '<table class="widefat striped mksddn-mc-user-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Include', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Email', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Archive role', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Current role', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Conflict handling', 'mksddn-migrate-content' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $incoming as $entry ) {
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

			echo '<tr>';
			echo '<td>';
			echo '<input type="hidden" name="user_plan[' . esc_attr( $key ) . '][email]" value="' . esc_attr( $email ) . '">';
			echo '<input type="hidden" name="user_plan[' . esc_attr( $key ) . '][import]" value="0">';
			/* translators: %s: user email. */
			echo '<label class="screen-reader-text" for="' . esc_attr( $checkbox ) . '">' . sprintf( esc_html__( 'Include %s', 'mksddn-migrate-content' ), esc_html( $email ) ) . '</label>';
			echo '<input type="checkbox" id="' . esc_attr( $checkbox ) . '" name="user_plan[' . esc_attr( $key ) . '][import]" value="1" checked>';
			echo '</td>';
			echo '<td><strong>' . esc_html( $email ) . '</strong><br><span class="description">' . esc_html( $entry['login'] ?? '' ) . '</span></td>';
			echo '<td>' . esc_html( $role ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $local ?: '—' ) . '</td>';
			echo '<td>' . esc_html( $status_label ) . '</td>';
			echo '<td>';
			if ( 'conflict' === $status ) {
				$select_id = 'mksddn-mc-user-mode-' . $key;
				echo '<label class="screen-reader-text" for="' . esc_attr( $select_id ) . '">' . esc_html__( 'Conflict handling', 'mksddn-migrate-content' ) . '</label>';
				echo '<select id="' . esc_attr( $select_id ) . '" name="user_plan[' . esc_attr( $key ) . '][mode]">';
				echo '<option value="keep">' . esc_html__( 'Keep current user', 'mksddn-migrate-content' ) . '</option>';
				echo '<option value="replace">' . esc_html__( 'Replace with archive', 'mksddn-migrate-content' ) . '</option>';
				echo '</select>';
			} else {
				echo '<input type="hidden" name="user_plan[' . esc_attr( $key ) . '][mode]" value="replace">';
				echo '<span class="description">' . esc_html__( 'Will be created', 'mksddn-migrate-content' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}

		if ( empty( $incoming ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No users detected inside the archive.', 'mksddn-migrate-content' ) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

		echo '<div class="mksddn-mc-user-actions">';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Apply selection and import', 'mksddn-migrate-content' ) . '</button>';
		echo '</div>';
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mksddn-mc-inline-form">';
		wp_nonce_field( 'mksddn_mc_cancel_preview_' . $preview['id'] );
		echo '<input type="hidden" name="action" value="mksddn_mc_cancel_user_preview">';
		echo '<input type="hidden" name="preview_id" value="' . esc_attr( $preview['id'] ) . '">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Cancel user selection', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render selected content section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_selected_content_section(): void {
		echo '<section class="mksddn-mc-section">';
		echo '<h2>' . esc_html__( 'Selected Content', 'mksddn-migrate-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pick one or many entries (pages, posts, CPT) and export them with or without media.', 'mksddn-migrate-content' ) . '</p>';
		echo '<div class="mksddn-mc-grid">';
		$this->render_selected_export_card();
		$this->render_selected_import_card();
		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render selected export card.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_selected_export_card(): void {
		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mksddn_mc_selected_export' );

		echo '<input type="hidden" name="action" value="mksddn_mc_export_selected">';
		echo '<div class="mksddn-mc-field">';
		echo '<h4>' . esc_html__( 'Choose content', 'mksddn-migrate-content' ) . '</h4>';
		echo '<p class="description">' . esc_html__( 'Hold Cmd/Ctrl to pick multiple entries inside each list.', 'mksddn-migrate-content' ) . '</p>';
		$this->render_selection_fields();
		echo '</div>';

		echo '<div class="mksddn-mc-field">';
		echo '<h4>' . esc_html__( 'File format', 'mksddn-migrate-content' ) . '</h4>';
		$this->render_format_selector();
		echo '</div>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Export selected', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render selection fields.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_selection_fields(): void {
		echo '<div class="mksddn-mc-selection-grid">';
		foreach ( $this->get_exportable_post_types() as $type => $label ) {
			$this->render_multi_select(
				$type,
				sprintf(
					/* translators: %s type label */
					__( '%s entries', 'mksddn-migrate-content' ),
					$label
				),
				$this->get_items_for_type( $type )
			);
		}
		echo '</div>';
	}

	/**
	 * Render multi-select for post type.
	 *
	 * @param string    $type  Post type slug.
	 * @param string    $label Label text.
	 * @param WP_Post[] $items Items to populate.
	 * @return void
	 * @since 1.0.0
	 */
	private function render_multi_select( string $type, string $label, array $items ): void {
		echo '<div class="mksddn-mc-basic-selection">';
		echo '<label for="selected_' . esc_attr( $type ) . '_ids">' . esc_html( $label ) . '</label>';
		$size = $this->determine_select_size( $items );
		$name = 'selected_' . $type . '_ids[]';
		echo '<select id="selected_' . esc_attr( $type ) . '_ids" name="' . esc_attr( $name ) . '" multiple size="' . esc_attr( $size ) . '">';

		if ( empty( $items ) ) {
			echo '<option value="" disabled>' . esc_html__( 'No entries found', 'mksddn-migrate-content' ) . '</option>';
		} else {
			foreach ( $items as $item ) {
				$label_text = $item->post_title ?: ( '#' . $item->ID );
				echo '<option value="' . esc_attr( $item->ID ) . '">' . esc_html( $label_text ) . '</option>';
			}
		}

		echo '</select>';
		echo '</div>';
	}

	/**
	 * Determine select box size.
	 *
	 * @param array $items Items list.
	 * @return int
	 * @since 1.0.0
	 */
	private function determine_select_size( array $items ): int {
		$count = count( $items );
		$count = max( 4, $count );
		$count = min( $count, 12 );

		return $count;
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
	 * Render format selector.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_format_selector(): void {
		echo '<div class="mksddn-mc-format-selector">';
		echo '<div class="mksddn-mc-basic-selection">';
		echo '<label for="export_format">' . esc_html__( 'Choose file format:', 'mksddn-migrate-content' ) . '</label>';
		echo '<select id="export_format" name="export_format">';
		echo '<option value="archive" selected>' . esc_html__( '.wpbkp (archive with manifest)', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="json">' . esc_html__( '.json (content only, editable)', 'mksddn-migrate-content' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( '.json skips media files and is best for quick edits. .wpbkp packs media + checksum.', 'mksddn-migrate-content' ) . '</p><br>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render selected import card.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_selected_import_card(): void {
		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'import_single_page_nonce' );

		echo '<div class="mksddn-mc-field">';
		echo '<h4>' . esc_html__( 'Choose File', 'mksddn-migrate-content' ) . '</h4>';
		echo '<label for="import_file" class="screen-reader-text">' . esc_html__( 'Upload .wpbkp or .json file:', 'mksddn-migrate-content' ) . '</label>';
		echo '<input type="file" id="import_file" name="import_file" accept=".wpbkp,.json" required><br>';
		echo '<p class="description">' . esc_html__( 'Archives include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ) . '</p><br>';
		echo '</div>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Import selected file', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render history section.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_history_section(): void {
		echo '<section class="mksddn-mc-section mksddn-mc-history">';
		echo '<h2>' . esc_html__( 'History & Recovery', 'mksddn-migrate-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Recent imports, rollbacks, and available snapshots.', 'mksddn-migrate-content' ) . '</p>';

		$entries = $this->history->all( 10 );
		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'History is empty for now.', 'mksddn-migrate-content' ) . '</p>';
			echo '</section>';
			return;
		}

		echo '<div class="mksddn-mc-history__table">';
		echo '<table>';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Type', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Finished', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Snapshot', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mksddn-migrate-content' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$context     = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();
			$snapshot_id = $context['snapshot_id'] ?? '';
			$snapshot_label = $context['snapshot_label'] ?? $snapshot_id;
			$user_label  = $this->format_user_label( (int) ( $entry['user_id'] ?? 0 ) );

			echo '<tr>';
			echo '<td>' . esc_html( $this->describe_history_type( $entry ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->format_status_badge( $entry['status'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_history_date( $entry['started_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_history_date( $entry['finished_at'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $snapshot_id ? esc_html( $snapshot_label ) : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . wp_kses_post( $this->render_history_actions( $entry ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '</section>';
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

		echo '<section class="mksddn-mc-section">';
		echo '<h2>' . esc_html__( 'Automation & Scheduling', 'mksddn-migrate-content' ) . '</h2>';
		echo '<p>' . esc_html__( 'Schedule automatic full-site backups and keep storage tidy with retention.', 'mksddn-migrate-content' ) . '</p>';
		echo '<div class="mksddn-mc-grid">';

		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Schedule settings', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mksddn_mc_schedule_save' );
		echo '<input type="hidden" name="action" value="mksddn_mc_schedule_save">';

		echo '<div class="mksddn-mc-field">';
		echo '<label><input type="checkbox" name="schedule_enabled" value="1"' . checked( $settings['enabled'], true, false ) . '> ' . esc_html__( 'Enable automatic backups', 'mksddn-migrate-content' ) . '</label>';
		echo '</div>';

		echo '<p class="description"><strong>' . esc_html__( 'Schedule preview:', 'mksddn-migrate-content' ) . '</strong> ' . wp_kses_post( $schedule_hint ) . '</p>';

		echo '<div class="mksddn-mc-field">';
		echo '<label for="mksddn-mc-schedule-recurrence">' . esc_html__( 'Run frequency', 'mksddn-migrate-content' ) . '</label>';
		echo '<select id="mksddn-mc-schedule-recurrence" name="schedule_recurrence">';
		foreach ( $recurrences as $slug => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $slug ),
				selected( $settings['recurrence'], $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '</div>';

		echo '<div class="mksddn-mc-field">';
		echo '<label for="mksddn-mc-schedule-retention">' . esc_html__( 'Keep last N archives', 'mksddn-migrate-content' ) . '</label>';
		echo '<input type="number" min="1" id="mksddn-mc-schedule-retention" name="schedule_retention" value="' . esc_attr( $settings['retention'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Older scheduled backups will be removed automatically.', 'mksddn-migrate-content' ) . '</p>';
		echo '</div>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Save schedule', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Status & history', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p><strong>' . esc_html__( 'Current state:', 'mksddn-migrate-content' ) . '</strong> ' . esc_html( $enabled_label ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Last run:', 'mksddn-migrate-content' ) . '</strong> ' . esc_html( $last_run ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Next run:', 'mksddn-migrate-content' ) . '</strong> ' . esc_html( $next_label ) . ' <span class="description">' . esc_html( $timezone ) . '</span></p>';

		if ( ! empty( $settings['last_message'] ) ) {
			echo '<p><strong>' . esc_html__( 'Last message:', 'mksddn-migrate-content' ) . '</strong> ' . wp_kses_post( $settings['last_message'] ) . '</p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mksddn_mc_schedule_run' );
		echo '<input type="hidden" name="action" value="mksddn_mc_schedule_run">';
		echo '<button type="submit" class="button">' . esc_html__( 'Run backup now', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';

		$this->render_schedule_runs_table( $runs );

		echo '</div>';

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render schedule runs table.
	 *
	 * @param array $runs Run entries.
	 * @return void
	 * @since 1.0.0
	 */
	private function render_schedule_runs_table( array $runs ): void {
		if ( empty( $runs ) ) {
			echo '<p>' . esc_html__( 'No scheduled backups have been created yet.', 'mksddn-migrate-content' ) . '</p>';
			return;
		}

		echo '<div class="mksddn-mc-user-table-wrapper">';
		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Run time', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Archive', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'mksddn-migrate-content' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'mksddn-migrate-content' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $runs as $run ) {
			$filename = isset( $run['file']['name'] ) ? sanitize_file_name( $run['file']['name'] ) : '';
			$size     = isset( $run['file']['size'] ) ? size_format( (int) $run['file']['size'] ) : '—';
			$download = '';

			if ( $filename ) {
				$download_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'mksddn_mc_download_scheduled',
							'file'   => $filename,
						),
						admin_url( 'admin-post.php' )
					),
					'mksddn_mc_download_scheduled_' . $filename
				);

				$delete_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'mksddn_mc_delete_scheduled',
							'file'   => $filename,
						),
						admin_url( 'admin-post.php' )
					),
					'mksddn_mc_delete_scheduled_' . $filename
				);

				$download = '<a class="button button-small" href="' . esc_url( $download_url ) . '">' . esc_html__( 'Download', 'mksddn-migrate-content' ) . '</a>';
				$download .= ' <a class="button button-small button-secondary" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this scheduled backup?', 'mksddn-migrate-content' ) ) . '\');">' . esc_html__( 'Delete', 'mksddn-migrate-content' ) . '</a>';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $this->format_history_date( $run['created_at'] ?? '' ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->format_status_badge( $run['status'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $filename ? esc_html( $filename ) . '<br><span class="description">' . esc_html( $size ) . '</span>' : '&mdash;' ) . '</td>';
			echo '<td>' . ( isset( $run['message'] ) && '' !== $run['message'] ? wp_kses_post( $run['message'] ) : '&mdash;' ) . '</td>';
			echo '<td>' . wp_kses_post( $download ?: '&mdash;' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
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

