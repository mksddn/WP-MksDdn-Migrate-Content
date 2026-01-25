<?php
/**
 * Admin UI for export/import.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Admin;

use MksDdn\MigrateContent\Archive\Extractor;
use MksDdn\MigrateContent\Automation\ScheduleManager;
use MksDdn\MigrateContent\Export\ExportHandler;
use MksDdn\MigrateContent\Import\ImportHandler;
use MksDdn\MigrateContent\Options\OptionsHelper;
use MksDdn\MigrateContent\Filesystem\FullContentExporter;
use MksDdn\MigrateContent\Filesystem\FullContentImporter;
use MksDdn\MigrateContent\Chunking\ChunkJobRepository;
use MksDdn\MigrateContent\Selection\SelectionBuilder;
use MksDdn\MigrateContent\Recovery\SnapshotManager;
use MksDdn\MigrateContent\Recovery\HistoryRepository;
use MksDdn\MigrateContent\Recovery\JobLock;
use MksDdn\MigrateContent\Support\FilenameBuilder;
use MksDdn\MigrateContent\Support\FilesystemHelper;
use MksDdn\MigrateContent\Support\SiteUrlGuard;
use MksDdn\MigrateContent\Support\RedirectTrait;
use MksDdn\MigrateContent\Users\UserDiffBuilder;
use MksDdn\MigrateContent\Users\UserPreviewStore;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller and renderer.
 */
class ExportImportAdmin {

	use RedirectTrait;

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	private SnapshotManager $snapshot_manager;
	private HistoryRepository $history;
	private JobLock $job_lock;
	private ScheduleManager $schedule_manager;
	private UserPreviewStore $preview_store;
	private ?array $pending_user_preview = null;

	/**
	 * Hook admin actions.
	 */
	public function __construct( ?Extractor $extractor = null, ?SnapshotManager $snapshot_manager = null, ?HistoryRepository $history = null, ?JobLock $job_lock = null, ?ScheduleManager $schedule_manager = null ) {
		$this->extractor        = $extractor ?? new Extractor();
		$this->snapshot_manager = $snapshot_manager ?? new SnapshotManager();
		$this->history          = $history ?? new HistoryRepository();
		$this->job_lock         = $job_lock ?? new JobLock();
		$this->schedule_manager = $schedule_manager ?? new ScheduleManager();
		$this->preview_store    = new UserPreviewStore();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mksddn_mc_export_selected', array( $this, 'handle_selected_export' ) );
		add_action( 'admin_post_mksddn_mc_export_full', array( $this, 'handle_full_export' ) );
		add_action( 'admin_post_mksddn_mc_import_full', array( $this, 'handle_full_import' ) );
		add_action( 'admin_post_mksddn_mc_rollback_snapshot', array( $this, 'handle_snapshot_rollback' ) );
		add_action( 'admin_post_mksddn_mc_delete_snapshot', array( $this, 'handle_snapshot_delete' ) );
		add_action( 'admin_post_mksddn_mc_cancel_user_preview', array( $this, 'handle_cancel_user_preview' ) );
		add_action( 'admin_post_mksddn_mc_schedule_save', array( $this, 'handle_schedule_save' ) );
		add_action( 'admin_post_mksddn_mc_schedule_run', array( $this, 'handle_schedule_run_now' ) );
		add_action( 'admin_post_mksddn_mc_download_scheduled', array( $this, 'handle_download_scheduled_backup' ) );
		add_action( 'admin_post_mksddn_mc_delete_scheduled', array( $this, 'handle_delete_scheduled_backup' ) );
	}

	/**
	 * Register admin menu page.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			__( 'Migrate Content', 'mksddn-migrate-content' ),
			'manage_options',
			MKSDDN_MC_TEXT_DOMAIN,
			array( $this, 'render_admin_page' ),
			'dashicons-migrate',
			20
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Migrate Content', 'mksddn-migrate-content' ) . '</h1>';
		$this->maybe_load_user_preview();
		$this->render_status_notices();

		$this->render_full_site_section();
		$this->render_selected_content_section();
		$this->render_history_section();
		$this->render_automation_section();
		$this->handle_selected_import();

		echo '</div>';
		$this->render_javascript();
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_' . MKSDDN_MC_TEXT_DOMAIN !== $hook ) {
			return;
		}

		if ( ! defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) ) {
			define( 'MKSDDN_MC_DISABLE_CHUNKED', true );
		}

		if ( ! MKSDDN_MC_DISABLE_CHUNKED ) {
		wp_enqueue_script(
			'mksddn-chunk-transfer',
			MKSDDN_MC_URL . 'assets/js/chunk-transfer.js',
			array(),
			MKSDDN_MC_VERSION,
			true
		);

		$default_chunk = 1024 * 1024;
		wp_localize_script(
			'mksddn-chunk-transfer',
			'mksddnChunk',
			array(
				'restUrl'                => esc_url_raw( rest_url( 'mksddn/v1/' ) ),
				'nonce'                  => wp_create_nonce( 'wp_rest' ),
				'chunkSize'              => 5 * 1024 * 1024,
				'uploadChunkSize'        => $default_chunk,
					'downloadFilename'       => FilenameBuilder::build( 'full-site', 'wpbkp' ),
				'defaultChunkSizeLabel'  => size_format( $default_chunk, 2 ),
					'i18n'                   => array(
						/* translators: %d: upload progress percent. */
					'uploading'          => __( 'Uploading chunks… %d%', 'mksddn-migrate-content' ),
					'uploadError'        => __( 'Chunked upload failed. Please try again.', 'mksddn-migrate-content' ),
						/* translators: 1: selected label, 2: chunk size label. */
					'importSelected'     => __( 'Selected %1$s (planned chunk %2$s).', 'mksddn-migrate-content' ),
						/* translators: %d: upload progress percent. */
					'importBusy'         => __( 'Uploading archive… %d%', 'mksddn-migrate-content' ),
					'importDone'         => __( 'Upload finished. Processing…', 'mksddn-migrate-content' ),
					'importProcessing'   => __( 'Server is processing the archive…', 'mksddn-migrate-content' ),
					'importError'        => __( 'Upload failed. Please retry.', 'mksddn-migrate-content' ),
						/* translators: %s: chunk size label. */
					'chunkInfo'          => __( '· %s chunks', 'mksddn-migrate-content' ),
					'preparing'        => __( 'Preparing download…', 'mksddn-migrate-content' ),
						/* translators: %d: download progress percent. */
					'downloading'      => __( 'Downloading chunks… %d%', 'mksddn-migrate-content' ),
					'downloadComplete' => __( 'Download complete.', 'mksddn-migrate-content' ),
					'downloadError'    => __( 'Chunked download failed. Falling back to direct download.', 'mksddn-migrate-content' ),
					'exportReady'      => __( 'Ready for full export.', 'mksddn-migrate-content' ),
					'exportBusy'       => __( 'Preparing archive…', 'mksddn-migrate-content' ),
						/* translators: %d: streaming progress percent. */
					'exportTransfer'   => __( 'Streaming archive… %d%', 'mksddn-migrate-content' ),
					'exportDone'       => __( 'Archive downloaded.', 'mksddn-migrate-content' ),
					'exportFallback'   => __( 'Falling back to classic download…', 'mksddn-migrate-content' ),
				),
			)
		);
		}
	}
	/**
	 * Display status notices after redirects.
	 */
	private function render_status_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag after redirect.
		if ( ! empty( $_GET['mksddn_mc_full_status'] ) ) {
			$status = sanitize_key( wp_unslash( $_GET['mksddn_mc_full_status'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'success' === $status ) {
			$this->show_success( __( 'Full site operation completed successfully.', 'mksddn-migrate-content' ) );
			} elseif ( 'error' === $status && ! empty( $_GET['mksddn_mc_full_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only after redirect
				$this->show_error( sanitize_text_field( wp_unslash( $_GET['mksddn_mc_full_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice flag after redirect.
		if ( empty( $_GET['mksddn_mc_notice'] ) ) {
			return;
		}

		$notice_status = sanitize_key( wp_unslash( $_GET['mksddn_mc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message       = isset( $_GET['mksddn_mc_notice_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mksddn_mc_notice_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'success' === $notice_status ) {
			$this->show_success( $message ?: __( 'Operation completed successfully.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( 'error' === $notice_status ) {
			$this->show_error( $message ?: __( 'Operation failed. Check logs for details.', 'mksddn-migrate-content' ) );
		}
	}

	/**
	 * Load preview context when user selection query is present.
	 */
	private function maybe_load_user_preview(): void {
		if ( empty( $_GET['mksddn_mc_user_review'] ) ) {
			return;
		}

		// Check nonce.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'mksddn_mc_user_preview' ) ) {
			$this->render_inline_notice( 'error', __( 'Security check failed.', 'mksddn-migrate-content' ) );
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->render_inline_notice( 'error', __( 'You do not have permission to access this page.', 'mksddn-migrate-content' ) );
			return;
		}

		$preview_id = sanitize_text_field( wp_unslash( $_GET['mksddn_mc_user_review'] ) );
		$preview    = $this->preview_store->get( $preview_id );

		if ( ! $preview ) {
			$this->render_inline_notice( 'error', __( 'User selection session expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->render_inline_notice( 'error', __( 'You are not allowed to continue this user selection.', 'mksddn-migrate-content' ) );
			return;
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		if ( empty( $summary['incoming'] ) ) {
			$this->render_inline_notice( 'error', __( 'User selection data is empty. Restart the import.', 'mksddn-migrate-content' ) );
			return;
		}

		$this->pending_user_preview = array(
			'id'            => $preview_id,
			'original_name' => $preview['original_name'] ?? '',
			'summary'       => $summary,
		);
	}

	/**
	 * Render inline admin notice.
	 *
	 * @param string $type    notice|error|warning|success.
	 * @param string $message Message.
	 */
	private function render_inline_notice( string $type, string $message ): void {
		$map = array(
			'error'   => 'notice notice-error',
			'success' => 'notice notice-success',
			'warning' => 'notice notice-warning',
			'info'    => 'notice notice-info',
		);

		$class = $map[ $type ] ?? $map['info'];
		printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}



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
	 * Render a multi-select for specific post type.
	 *
	 * @param string    $type  Post type slug.
	 * @param string    $label Label text.
	 * @param WP_Post[] $items Items to populate.
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
	 * Determine select box size based on available items.
	 *
	 * @param array $items Items list.
	 * @return int
	 */
	private function determine_select_size( array $items ): int {
		$count = count( $items );
		$count = max( 4, $count );
		$count = min( $count, 12 );

		return $count;
	}

	/**
	 * Render CPT multi-select builder.
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
	 * Fetch items for select.
	 *
	 * @param string $type Post type.
	 * @return WP_Post[]
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
	 * Render file format selector.
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
	 * Render import form.
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

	private function render_selected_content_section(): void {
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
	 * Render job history and rollback actions.
	 */
	private function render_history_section(): void {
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
	 * Render automation/scheduling controls.
	 */
	private function render_automation_section(): void {
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
	 * Render recent scheduled backups table.
	 *
	 * @param array $runs Run entries.
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
			$filename = isset( $run['file']['name'] ) ? basename( $run['file']['name'] ) : '';
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
	 * Format history entry type label.
	 *
	 * @param array $entry Entry payload.
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
	 * Render status badge HTML.
	 *
	 * @param string $status Status slug.
	 * @return string
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
	 * Convert ISO date to WP formatted string.
	 *
	 * @param string $date Date string.
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
	 * Render action buttons for entry.
	 *
	 * @param array $entry Entry payload.
	 * @return string
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
	 * Determine if entry can be rolled back.
	 *
	 * @param array $entry Entry data.
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
	 * Format user label for history table.
	 *
	 * @param int $user_id User ID.
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

	private function render_full_site_section(): void {
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
		if ( $this->pending_user_preview ) {
			$this->render_user_preview_card();
		} else {
			$this->render_full_import_form();
		}
		echo '</div>';

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render default full import form (file upload or chunked).
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
	 * Render user preview card when archive contains user data.
	 */
	private function render_user_preview_card(): void {
		$preview = $this->pending_user_preview;
		$summary = $preview['summary'] ?? array();
		$incoming = $summary['incoming'] ?? array();
		$counts   = $summary['counts'] ?? array();
		$total    = (int) ( $counts['incoming'] ?? count( $incoming ) );
		$conflict = (int) ( $counts['conflicts'] ?? 0 );

		echo '<h3>' . esc_html__( 'Review users before import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Pick which users from the archive should be added or overwrite existing accounts on this site.', 'mksddn-migrate-content' ) . '</p>';
		
		// Warning about deselecting all users.
		$current_user = wp_get_current_user();
		echo '<div class="notice notice-warning inline" style="margin: 10px 0;">';
		echo '<p><strong>' . esc_html__( '⚠️ Important:', 'mksddn-migrate-content' ) . '</strong> ';
		echo esc_html__( 'If you deselect all users, your current login will be preserved to prevent lockout.', 'mksddn-migrate-content' );
		if ( $current_user && $current_user->exists() ) {
			echo ' ' . sprintf(
				/* translators: %s: current user email. */
				esc_html__( 'You are logged in as: %s', 'mksddn-migrate-content' ),
				'<code>' . esc_html( $current_user->user_email ) . '</code>'
			);
		}
		echo '</p></div>';
		
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
	 * Handle selected content import.
	 */
	private function handle_selected_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method || ! check_admin_referer( 'import_single_page_nonce' ) ) {
			return;
		}

		$lock_id = $this->job_lock->acquire( 'selected-import' );
		if ( is_wp_error( $lock_id ) ) {
			$this->show_error( esc_html( $lock_id->get_error_message() ) );
			return;
		}

		$history_id = null;

		try {
		if ( ! isset( $_FILES['import_file'], $_FILES['import_file']['error'] ) || UPLOAD_ERR_OK !== (int) $_FILES['import_file']['error'] ) {
			$this->show_error( esc_html__( 'Failed to upload file.', 'mksddn-migrate-content' ) );
			$this->progress_tick( 100, __( 'Upload failed', 'mksddn-migrate-content' ) );
			return;
		}

		$file     = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['import_file']['tmp_name'] ) : '';
		$filename = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( (string) $_FILES['import_file']['name'] ) : '';
		$size     = isset( $_FILES['import_file']['size'] ) ? (int) $_FILES['import_file']['size'] : 0;

		if ( 0 >= $size ) {
			$this->show_error( esc_html__( 'Invalid file size.', 'mksddn-migrate-content' ) );
			return;
		}

		$this->progress_tick( 10, __( 'Validating file…', 'mksddn-migrate-content' ) );

		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime = function_exists( 'mime_content_type' ) && '' !== $file ? mime_content_type( $file ) : '';

		$result = $this->prepare_import_payload( $ext, $mime, $file );

		if ( is_wp_error( $result ) ) {
			$this->show_error( $result->get_error_message() );
			$this->progress_tick( 100, __( 'Import aborted', 'mksddn-migrate-content' ) );
			return;
		}

		$this->progress_tick( 40, __( 'Parsing content…', 'mksddn-migrate-content' ) );

			$snapshot = $this->snapshot_manager->create(
				array(
					'label' => 'pre-import-selected',
					'meta'  => array( 'file' => $filename ),
				)
			);

			if ( is_wp_error( $snapshot ) ) {
				$this->show_error( $snapshot->get_error_message() );
				return;
			}

			$history_id = $this->history->start(
				'import',
				array(
					'mode'           => 'selected',
					'file'           => $filename,
					'snapshot_id'    => $snapshot['id'],
					'snapshot_label' => $snapshot['label'] ?? $snapshot['id'],
				)
			);

			$payload                 = $result['payload'];
			$payload_type            = $result['type'];
			$payload['type']         = $payload_type;
		$payload['_mksddn_media'] = $payload['_mksddn_media'] ?? $result['media'];

		$import_handler = new ImportHandler();

		if ( 'archive' === $result['media_source'] ) {
			$import_handler->set_media_file_loader(
				function ( string $archive_path ) use ( $file ) {
					return $this->extractor->extract_media_file( $archive_path, $file );
				}
			);
		}

		$this->progress_tick( 70, __( 'Importing content…', 'mksddn-migrate-content' ) );

		$result = $this->process_import( $import_handler, $payload_type, $payload );

		if ( $result ) {
			$this->progress_tick( 100, __( 'Completed', 'mksddn-migrate-content' ) );
			// translators: %s is imported item type.
			$this->show_success( sprintf( esc_html__( '%s imported successfully!', 'mksddn-migrate-content' ), ucfirst( (string) $payload_type ) ) );
				if ( $history_id ) {
					$this->history->finish( $history_id, 'success' );
				}
		} else {
			$this->progress_tick( 100, __( 'Import failed', 'mksddn-migrate-content' ) );
			$this->show_error( esc_html__( 'Failed to import content.', 'mksddn-migrate-content' ) );
				if ( $history_id ) {
					$this->history->finish(
						$history_id,
						'error',
						array( 'message' => __( 'Selected import failed.', 'mksddn-migrate-content' ) )
					);
				}
			}
		} finally {
			$this->job_lock->release( $lock_id );
		}
	}

	/**
	 * Normalize uploaded file into payload and type.
	 *
	 * @param string $extension File extension (lowercase).
	 * @param string $mime      Detected mime type.
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error
	 */
	private function prepare_import_payload( string $extension, string $mime, string $file_path ): array|WP_Error {
		switch ( $extension ) {
			case 'json':
				$json_mimes = array( 'application/json', 'text/plain', 'application/octet-stream' );
				if ( '' !== $mime && ! in_array( $mime, $json_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a JSON export created by this plugin.', 'mksddn-migrate-content' ) );
				}

				$data = $this->read_json_payload( $file_path );
				if ( is_wp_error( $data ) ) {
					return $data;
				}

				return array(
					'type'         => $data['type'] ?? 'page',
					'payload'      => $data,
					'media'        => $data['_mksddn_media'] ?? array(),
					'media_source' => 'json',
				);

			case 'wpbkp':
				$archive_mimes = array( 'application/octet-stream', 'application/zip', 'application/x-zip-compressed' );
				if ( '' !== $mime && ! in_array( $mime, $archive_mimes, true ) ) {
					return new WP_Error( 'mksddn_mc_invalid_type', __( 'Invalid file type. Upload a .wpbkp archive created by this plugin.', 'mksddn-migrate-content' ) );
				}

				$extracted = $this->extractor->extract( $file_path );
				if ( is_wp_error( $extracted ) ) {
					return $extracted;
				}

				return array(
					'type'         => $extracted['type'],
					'payload'      => $extracted['payload'],
					'media'        => $extracted['media'] ?? array(),
					'media_source' => 'archive',
				);
		}

		return new WP_Error( 'mksddn_mc_invalid_type', __( 'Unsupported file extension. Use .wpbkp or .json.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Read and decode JSON payload.
	 *
	 * @param string $file_path Uploaded file path.
	 * @return array|WP_Error
	 */
	private function read_json_payload( string $file_path ): array|WP_Error {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file -- Local temporary file validated earlier.
		$json = file_get_contents( $file_path );
		if ( false === $json ) {
			return new WP_Error( 'mksddn_mc_json_unreadable', __( 'Unable to read JSON file.', 'mksddn-migrate-content' ) );
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error( 'mksddn_mc_json_invalid', __( 'Invalid JSON structure.', 'mksddn-migrate-content' ) );
		}

		return $data;
	}

	/**
	 * Dispatch import by type.
	 *
	 * @param ImportHandler $import_handler Handler instance.
	 * @param string        $type           Payload type.
	 * @param array         $data           Payload.
	 * @return bool
	 */
	private function process_import( ImportHandler $import_handler, string $type, array $data ): bool {
		if ( 'bundle' === $type ) {
			return $import_handler->import_bundle( $data );
		}

		return $import_handler->import_single_page( $data );
	}

	/**
	 * Render success notice.
	 *
	 * @param string $message Message text.
	 */
	private function show_success( string $message ): void {
		echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
		);
	}

	/**
	 * Render error notice.
	 *
	 * @param string $message Message text.
	 */
	private function show_error( string $message ): void {
		echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			'if(window.mksddnMcProgress && window.mksddnMcProgress.hide){window.mksddnMcProgress.hide();}'
		);
	}

	/**
	 * Render helper script.
	 *
	 * @deprecated JavaScript is now enqueued via wp_enqueue_script() in AdminPageController::enqueue_assets().
	 */
	private function render_javascript(): void {
		// JavaScript is now enqueued via wp_enqueue_script() in AdminPageController::enqueue_assets().
		// This method is kept for backward compatibility but no longer renders scripts.
	}

	/**
	 * Output inline progress update.
	 *
	 * @param int    $percent Percent value.
	 * @param string $message Label.
	 */
	private function progress_tick( int $percent, string $message ): void {
		wp_add_inline_script(
			'mksddn-mc-admin-scripts',
			sprintf(
				'if(window.mksddnMcProgress){window.mksddnMcProgress.set(%1$d, %2$s);}',
				absint( $percent ),
				wp_json_encode( $message )
			)
		);

		$this->flush_buffers();
	}

	/**
	 * Flush buffers so progress scripts reach browser early.
	 */
	private function flush_buffers(): void {
		if ( function_exists( 'ob_flush' ) ) {
			@ob_flush();
		}

		if ( function_exists( 'flush' ) ) {
			@flush();
		}
	}

	/**
	 * Handle selected content export.
	 */
	public function handle_selected_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		if ( ! check_admin_referer( 'mksddn_mc_selected_export' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'mksddn-migrate-content' ) );
		}

		// Extract only necessary fields from $_POST.
		$allowed_fields = $this->extract_selection_fields( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified above.
		
		$builder    = new SelectionBuilder();
		$selection  = $builder->from_request( $allowed_fields );
		$format     = sanitize_key( $_POST['export_format'] ?? 'archive' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$with_media = ( 'archive' === $format );

		$export_handler = new ExportHandler();
		$export_handler->set_collect_media( $with_media );
		$export_handler->export_selected_content( $selection, $format );
	}

	/**
	 * Handle full site export action.
	 */
	public function handle_full_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_full_export' );

		$temp = \wp_tempnam( 'mksddn-full-' );
		if ( ! $temp ) {
			wp_die( esc_html__( 'Unable to create temporary file.', 'mksddn-migrate-content' ) );
		}

		$exporter = new FullContentExporter();
		$result   = $exporter->export_to( $temp );

		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}

		$filename = FilenameBuilder::build( 'full-site', 'wpbkp' );
		$this->stream_file_download( $temp, $filename );
	}

	/**
	 * Handle full site import action.
	 */
	public function handle_full_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to import.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_full_import' );

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( $preview_id ) {
			$this->finalize_full_import_from_preview( $preview_id );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_full_import.
		$chunk_job_id = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$upload       = $this->resolve_full_import_upload( $chunk_job_id );

		if ( is_wp_error( $upload ) ) {
			$this->redirect_full_status( 'error', $upload->get_error_message() );
		}

		$diff_builder = new UserDiffBuilder();
		$diff         = $diff_builder->build( $upload['temp'] );

		if ( is_wp_error( $diff ) ) {
			$this->cleanup_full_import( $upload['temp'], $upload['cleanup'], $upload['job'] );
			$this->redirect_full_status( 'error', $diff->get_error_message() );
		}

		if ( empty( $diff['incoming'] ) ) {
			$this->execute_full_import( $upload );
			return;
		}

		$preview_id = $this->preview_store->create(
			array(
				'file_path'     => $upload['temp'],
				'chunk_job_id'  => $upload['chunk_job_id'],
				'cleanup'       => $upload['cleanup'],
				'original_name' => $upload['original_name'],
				'summary'       => $diff,
			)
		);

		$this->redirect_user_preview( $preview_id );
	}

	/**
	 * Continue import using stored preview selection.
	 *
	 * @param string $preview_id Preview identifier.
	 */
	private function finalize_full_import_from_preview( string $preview_id ): void {
		$preview = $this->preview_store->get( $preview_id );
		if ( ! $preview ) {
			$this->redirect_full_status( 'error', __( 'User selection expired. Please upload the archive again.', 'mksddn-migrate-content' ) );
		}

		if ( (int) ( $preview['created_by'] ?? 0 ) !== get_current_user_id() ) {
			$this->redirect_full_status( 'error', __( 'You are not allowed to complete this user selection.', 'mksddn-migrate-content' ) );
		}

		$summary = isset( $preview['summary'] ) && is_array( $preview['summary'] ) ? $preview['summary'] : array();
		$plan    = $this->build_user_plan_from_request( $summary );

		if ( is_wp_error( $plan ) ) {
			$this->redirect_full_status( 'error', $plan->get_error_message() );
		}

		$upload = array(
			'temp'          => isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '',
			'cleanup'       => ! empty( $preview['cleanup'] ),
			'chunk_job_id'  => isset( $preview['chunk_job_id'] ) ? sanitize_text_field( (string) $preview['chunk_job_id'] ) : '',
			'original_name' => $preview['original_name'] ?? '',
			'job'           => null,
		);

		if ( $upload['chunk_job_id'] ) {
			$repo          = new ChunkJobRepository();
			$upload['job'] = $repo->get( $upload['chunk_job_id'] );
		}

		if ( empty( $upload['temp'] ) || ! file_exists( $upload['temp'] ) ) {
			$this->preview_store->delete( $preview_id );
			$this->redirect_full_status( 'error', __( 'Import file is missing. Restart the upload.', 'mksddn-migrate-content' ) );
		}

		$options = array(
			'user_merge' => array(
				'enabled' => true,
				'plan'    => $plan,
				'tables'  => $summary['tables'] ?? array(),
			),
		);

		$this->preview_store->delete( $preview_id );
		$this->execute_full_import( $upload, $options );
	}

	/**
	 * Normalize plan payload from request input.
	 *
	 * @param array $summary Preview summary.
	 * @return array|WP_Error
	 */
	private function build_user_plan_from_request( array $summary ) {
		$incoming = isset( $summary['incoming'] ) && is_array( $summary['incoming'] ) ? $summary['incoming'] : array();
		if ( empty( $incoming ) ) {
			return array();
		}

		$defaults = array();
		foreach ( $incoming as $entry ) {
			$email = isset( $entry['email'] ) ? sanitize_email( $entry['email'] ) : '';
			if ( ! $email ) {
				continue;
			}

			$defaults[ strtolower( $email ) ] = $email;
		}

		if ( empty( $defaults ) ) {
			return new WP_Error( 'mksddn_mc_user_plan_empty', __( 'No users available for selection.', 'mksddn-migrate-content' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle_full_import(); raw plan sanitized below.
		$raw_plan_input = isset( $_POST['user_plan'] ) && is_array( $_POST['user_plan'] ) ? wp_unslash( $_POST['user_plan'] ) : array();
		$raw_plan       = array();
		foreach ( $raw_plan_input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$raw_plan[] = array(
				'email'  => isset( $row['email'] ) ? sanitize_email( $row['email'] ) : '',
				'import' => ! empty( $row['import'] ),
				'mode'   => isset( $row['mode'] ) ? sanitize_text_field( $row['mode'] ) : '',
			);
		}

		$plan = array();

		foreach ( $defaults as $email ) {
			$plan[ $email ] = array(
				'import' => false,
				'mode'   => 'replace',
			);
		}

		foreach ( $raw_plan as $row ) {
			$email = $row['email'];
			if ( ! $email ) {
				continue;
			}

			$lookup = strtolower( $email );
			if ( ! isset( $defaults[ $lookup ] ) ) {
				continue;
			}

			$import = ! empty( $row['import'] );
			$mode   = 'keep' === $row['mode'] ? 'keep' : 'replace';

			$plan[ $email ] = array(
				'import' => $import,
				'mode'   => $mode,
			);
		}

		return $plan;
	}

	/**
	 * Resolve uploaded file or chunk job.
	 *
	 * @param string $chunk_job_id Chunk job identifier.
	 * @return array|WP_Error
	 */
	private function resolve_full_import_upload( string $chunk_job_id ) {
		$chunk_disabled = defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) && MKSDDN_MC_DISABLE_CHUNKED;
		$result         = array(
			'temp'          => '',
			'cleanup'       => false,
			'job'           => null,
			'chunk_job_id'  => $chunk_job_id,
			'original_name' => '',
		);

		if ( $chunk_job_id ) {
			if ( $chunk_disabled ) {
				return new WP_Error( 'mksddn_mc_chunk_disabled', __( 'Chunked uploads are disabled on this site.', 'mksddn-migrate-content' ) );
			}

			$repo = new ChunkJobRepository();
			$job  = $repo->get( $chunk_job_id );
			$path = $job->get_file_path();

			if ( ! file_exists( $path ) ) {
				return new WP_Error( 'mksddn_mc_chunk_missing', __( 'Chunked upload is incomplete. Please retry.', 'mksddn-migrate-content' ) );
			}

			$result['temp']          = $path;
			$result['job']           = $job;
			$result['original_name'] = sprintf( 'chunk:%s', $chunk_job_id );

			return $result;
		}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in handle_full_import().
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in handle_full_import().
			if ( ! isset( $_FILES['full_import_file'], $_FILES['full_import_file']['tmp_name'] ) ) {
			return new WP_Error( 'mksddn_mc_file_missing', __( 'No file uploaded.', 'mksddn-migrate-content' ) );
			}

		$file     = $_FILES['full_import_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- sanitized below, nonce verified upstream
		$tmp_name = isset( $file['tmp_name'] ) ? \sanitize_text_field( \wp_unslash( (string) $file['tmp_name'] ) ) : '';
		$name     = isset( $file['name'] ) ? \sanitize_file_name( \wp_unslash( (string) $file['name'] ) ) : '';
			$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

		if ( $size <= 0 ) {
			return new WP_Error( 'mksddn_mc_invalid_size', __( 'Invalid file size.', 'mksddn-migrate-content' ) );
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'wpbkp' !== $ext ) {
			return new WP_Error( 'mksddn_mc_invalid_type', __( 'Please upload a .wpbkp archive generated by this plugin.', 'mksddn-migrate-content' ) );
			}

		$temp = \wp_tempnam( 'mksddn-full-import-' );
		if ( ! $temp ) {
		return new WP_Error( 'mksddn_mc_temp_unavailable', __( 'Unable to allocate a temporary file for import.', 'mksddn-migrate-content' ) );
		}

		if ( ! is_uploaded_file( $tmp_name ) ) {
			return new WP_Error( 'mksddn_mc_move_failed', __( 'Uploaded file could not be verified.', 'mksddn-migrate-content' ) );
		}

			if ( ! FilesystemHelper::move( $tmp_name, $temp, true ) ) {
				return new WP_Error( 'mksddn_mc_move_failed', __( 'Failed to move uploaded file. Check permissions.', 'mksddn-migrate-content' ) );
			}

		$result['temp']          = $temp;
		$result['cleanup']       = true;
		$result['original_name'] = $name;

		return $result;
	}

	/**
	 * Execute full import with optional user merge options.
	 *
	 * @param array $upload  Upload data.
	 * @param array $options Import options.
	 */
	private function execute_full_import( array $upload, array $options = array() ): void {
		// Disable time limit for long-running import operations.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged
		}

		// Increase max execution time via ini_set as fallback.
		@ini_set( 'max_execution_time', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged

		// Continue execution even if client disconnects.
		@ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$temp = $upload['temp'] ?? '';
		if ( '' === $temp || ! file_exists( $temp ) ) {
			$this->redirect_full_status( 'error', __( 'Import file is missing on disk.', 'mksddn-migrate-content' ) );
			}

		$cleanup       = ! empty( $upload['cleanup'] );
		$job           = $upload['job'] ?? null;
		$original_name = $upload['original_name'] ?? '';

		// Create history entry early to get ID for response.
		$history_id = $this->history->start(
			'import',
			array(
				'mode' => 'full',
				'file' => $original_name,
			)
		);

		// Redirect to admin page with progress indicator IMMEDIATELY to prevent timeout.
		// This must happen before any long-running operations.
		$this->redirect_to_import_progress( $history_id );

		$lock_id = $this->job_lock->acquire( 'full-import' );
		if ( is_wp_error( $lock_id ) ) {
			$this->history->finish( $history_id, 'error', array( 'message' => $lock_id->get_error_message() ) );
			$this->cleanup_full_import( $temp, $cleanup, $job );
			return;
		}

		$snapshot = $this->snapshot_manager->create(
			array(
				'label'           => 'pre-import-full',
				'include_plugins' => true,
				'include_themes'  => true,
				'meta'            => array( 'file' => $original_name ),
			)
		);

		if ( is_wp_error( $snapshot ) ) {
			$this->history->finish( $history_id, 'error', array( 'message' => $snapshot->get_error_message() ) );
			$this->job_lock->release( $lock_id );
			$this->cleanup_full_import( $temp, $cleanup, $job );
			return;
		}

		// Update history with snapshot info.
		$this->history->update_context(
			$history_id,
			array(
				'snapshot_id'    => $snapshot['id'],
				'snapshot_label' => $snapshot['label'] ?? $snapshot['id'],
			)
		);

		$site_guard = new SiteUrlGuard();
		$importer   = new FullContentImporter();

		// Set progress callback to update history.
		$importer->set_progress_callback(
			function ( int $percent, string $message ) use ( $history_id ) {
				$this->history->update_progress( $history_id, $percent, $message );
			}
		);

		$result = $importer->import_from( $temp, $site_guard, $options );

		$status  = 'success';
		$message = null;

		if ( is_wp_error( $result ) ) {
			$status  = 'error';
			$message = $result->get_error_message();
			$this->history->finish(
				$history_id,
				'error',
				array( 'message' => $message )
			);

			$rollback = $this->restore_snapshot( $snapshot, 'auto' );
			if ( is_wp_error( $rollback ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s error message */
					__( 'Automatic rollback failed: %s', 'mksddn-migrate-content' ),
					$rollback->get_error_message()
				);
			} else {
				$message .= ' ' . __( 'Previous state was restored automatically.', 'mksddn-migrate-content' );
			}
		} else {
			// URL guard already restored by FullContentImporter::import_from().
			// No need to call $site_guard->restore() again here.
			$this->normalize_plugin_storage();

			$history_context = array();
			$merge_summary   = $importer->get_user_merge_summary();
			if ( ! empty( $merge_summary ) ) {
				$parts = array();
				if ( ! empty( $merge_summary['created'] ) ) {
					$parts[] = 'created:' . (int) $merge_summary['created'];
				}
				if ( ! empty( $merge_summary['updated'] ) ) {
					$parts[] = 'updated:' . (int) $merge_summary['updated'];
				}
				if ( ! empty( $merge_summary['skipped'] ) ) {
					$parts[] = 'skipped:' . (int) $merge_summary['skipped'];
				}
				if ( ! empty( $merge_summary['preserved'] ) ) {
					$parts[] = 'preserved:' . (int) $merge_summary['preserved'];
				}
				
				$history_context['user_selection'] = implode( ' ', $parts );
			}

			$this->history->finish( $history_id, 'success', $history_context );
		}

		$this->job_lock->release( $lock_id );
		$this->cleanup_full_import( $temp, $cleanup, $job );

		// Update history context with final status.
		if ( $message ) {
			$this->history->update_context( $history_id, array( 'message' => $message ) );
		}
	}

	/**
	 * Redirect back to admin page with preview query arg.
	 *
	 * @param string $preview_id Preview identifier.
	 */
	private function redirect_user_preview( string $preview_id ): void {
		$base = admin_url( 'admin.php?page=' . MKSDDN_MC_TEXT_DOMAIN );
		$url  = add_query_arg( array( 'mksddn_mc_user_review' => $preview_id ), $base );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Cancel stored user preview.
	 */
	public function handle_cancel_user_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$preview_id = isset( $_POST['preview_id'] ) ? sanitize_text_field( wp_unslash( $_POST['preview_id'] ) ) : '';
		if ( '' === $preview_id ) {
			$this->redirect_with_notice( 'error', __( 'Preview identifier is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_cancel_preview_' . $preview_id );

		$preview = $this->preview_store->get( $preview_id );
		if ( $preview ) {
			$this->cleanup_preview_resources( $preview );
			$this->preview_store->delete( $preview_id );
		}

		$this->redirect_with_notice( 'success', __( 'User selection cancelled.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Delete stored snapshot archive.
	 */
	public function handle_snapshot_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$history_id  = isset( $_POST['history_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_id'] ) ) : '';
		$snapshot_id = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : '';

		check_admin_referer( 'mksddn_mc_delete_snapshot_' . $history_id );

		if ( '' === $snapshot_id ) {
			$this->redirect_with_notice( 'error', __( 'Snapshot identifier is missing.', 'mksddn-migrate-content' ) );
		}

		$snapshot = $this->snapshot_manager->get( $snapshot_id );
		if ( $snapshot ) {
			$this->snapshot_manager->delete( $snapshot_id );
		}

		if ( $history_id ) {
			$this->history->update_context(
				$history_id,
				array(
					'snapshot_id'    => '',
					'snapshot_label' => '',
					'action'         => 'snapshot_deleted',
				)
			);
		}

		$this->redirect_with_notice( 'success', __( 'Backup deleted successfully.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Cleanup temp resources associated with preview.
	 *
	 * @param array $preview Preview payload.
	 * @return void
	 */
	private function cleanup_preview_resources( array $preview ): void {
		$temp    = isset( $preview['file_path'] ) ? (string) $preview['file_path'] : '';
		$cleanup = ! empty( $preview['cleanup'] );
		$job     = null;

		if ( ! empty( $preview['chunk_job_id'] ) ) {
			$repo = new ChunkJobRepository();
			$job  = $repo->get( sanitize_text_field( (string) $preview['chunk_job_id'] ) );
		}

		$this->cleanup_full_import( $temp, $cleanup, $job );
	}

	/**
	 * Handle rollback action from history table.
	 */
	public function handle_snapshot_rollback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$history_id  = isset( $_POST['history_id'] ) ? sanitize_text_field( wp_unslash( $_POST['history_id'] ) ) : '';
		$snapshot_id = isset( $_POST['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $_POST['snapshot_id'] ) ) : '';

		check_admin_referer( 'mksddn_mc_rollback_' . $history_id );

		if ( '' === $snapshot_id ) {
			$this->redirect_with_notice( 'error', __( 'Snapshot is missing.', 'mksddn-migrate-content' ) );
		}

		$snapshot = $this->snapshot_manager->get( $snapshot_id );
		if ( ! $snapshot ) {
			$this->redirect_with_notice( 'error', __( 'Snapshot not found on disk.', 'mksddn-migrate-content' ) );
		}

		$lock_id = $this->job_lock->acquire( 'rollback' );
		if ( is_wp_error( $lock_id ) ) {
			$this->redirect_with_notice( 'error', $lock_id->get_error_message() );
		}

		$result = $this->restore_snapshot( $snapshot, 'manual' );

		if ( is_wp_error( $result ) ) {
			$this->job_lock->release( $lock_id );
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$this->job_lock->release( $lock_id );
		$this->redirect_with_notice( 'success', __( 'Snapshot restored successfully.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Save schedule settings.
	 */
	public function handle_schedule_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_schedule_save' );

		$payload = array(
			'enabled'   => isset( $_POST['schedule_enabled'] ),
			'recurrence'=> sanitize_key( $_POST['schedule_recurrence'] ?? 'daily' ),
			'retention' => absint( $_POST['schedule_retention'] ?? 5 ),
		);

		$this->schedule_manager->update_settings( $payload );
		$this->redirect_with_notice( 'success', __( 'Schedule settings updated.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Run scheduled export immediately.
	 */
	public function handle_schedule_run_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_schedule_run' );

		$result = $this->schedule_manager->run_manually();
		if ( is_wp_error( $result ) ) {
			$this->redirect_with_notice( 'error', $result->get_error_message() );
		}

		$filename = $result['file']['name'] ?? '';
		$message  = $filename
			? sprintf(
				/* translators: %s archive filename */
				__( 'Scheduled backup %s created.', 'mksddn-migrate-content' ),
				$filename
			)
			: __( 'Scheduled backup completed.', 'mksddn-migrate-content' );

		$this->redirect_with_notice( 'success', $message );
	}

	/**
	 * Download stored scheduled backup.
	 */
	public function handle_download_scheduled_backup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( '' === $filename ) {
			$this->redirect_with_notice( 'error', __( 'Backup file is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_download_scheduled_' . $filename );

		$path = $this->schedule_manager->resolve_backup_path( $filename );
		if ( ! $path ) {
			$this->redirect_with_notice( 'error', __( 'Backup file was not found on disk.', 'mksddn-migrate-content' ) );
		}

		$this->stream_file_download( $path, $filename, false );
	}

	/**
	 * Delete stored scheduled backup.
	 */
	public function handle_delete_scheduled_backup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to perform this action.', 'mksddn-migrate-content' ) );
		}

		$filename = isset( $_GET['file'] ) ? sanitize_file_name( wp_unslash( $_GET['file'] ) ) : '';
		if ( '' === $filename ) {
			$this->redirect_with_notice( 'error', __( 'Backup file is missing.', 'mksddn-migrate-content' ) );
		}

		check_admin_referer( 'mksddn_mc_delete_scheduled_' . $filename );

		$this->schedule_manager->delete_backup( $filename );

		$this->redirect_with_notice( 'success', __( 'Scheduled backup deleted.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Restore snapshot either manually or automatically.
	 *
	 * @param array  $snapshot Snapshot metadata.
	 * @param string $action   manual|auto.
	 * @return true|WP_Error
	 */
	private function restore_snapshot( array $snapshot, string $action = 'manual' ) {
		if ( empty( $snapshot['path'] ) || ! file_exists( $snapshot['path'] ) ) {
			return new WP_Error( 'mksddn_snapshot_missing', __( 'Snapshot archive is missing on disk.', 'mksddn-migrate-content' ) );
		}

		$history_entry = $this->history->start(
			'rollback',
			array(
				'snapshot_id'    => $snapshot['id'] ?? '',
				'snapshot_label' => $snapshot['label'] ?? '',
				'action'         => $action,
			)
		);

		$guard    = new SiteUrlGuard();
		$importer = new FullContentImporter();
		$result   = $importer->import_from( $snapshot['path'], $guard );

		if ( is_wp_error( $result ) ) {
			$this->history->finish(
				$history_entry,
				'error',
				array( 'message' => $result->get_error_message() )
			);

			return $result;
		}

		$guard->restore();
		$this->history->finish( $history_entry, 'success' );

		$guard->restore();
		$this->normalize_plugin_storage();
		return true;
	}

	/**
	 * Normalize storage paths for known plugins (AI1WM etc).
	 */
	private function normalize_plugin_storage(): void {
		$target = trailingslashit( WP_CONTENT_DIR ) . 'ai1wm-backups';
		wp_mkdir_p( $target );
		update_option( 'mksddn_mc_storage_path', $target );
	}

	/**
	 * Output file download headers and contents.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename Download filename.
	 */
	private function stream_file_download( string $path, string $filename, bool $delete_after = true ): void {
		if ( ! file_exists( $path ) ) {
			wp_die( esc_html__( 'Export file not found.', 'mksddn-migrate-content' ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		$filesize = filesize( $path );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		if ( false !== $filesize ) {
			header( 'Content-Length: ' . $filesize );
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming large archives requires native handle
		if ( $handle ) {
			fpassthru( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen for streaming
		}

		if ( $delete_after && file_exists( $path ) ) {
			FilesystemHelper::delete( $path );
		}
		exit;
	}

	/**
	 * Cleanup temp files and chunk jobs.
	 *
	 * @param string     $temp    Temp file path.
	 * @param bool       $cleanup Whether temp should be removed.
	 * @param object|null $job    Chunk job instance.
	 */
	private function cleanup_full_import( string $temp, bool $cleanup, $job ): void {
		if ( $cleanup && $temp && file_exists( $temp ) ) {
			FilesystemHelper::delete( $temp );
		}

		if ( $job && method_exists( $job, 'delete' ) ) {
			$job->delete();
		}
	}

	/**
	 * Redirect back with status parameters.
	 *
	 * @param string      $status success|error.
	 * @param string|null $message Optional message.
	 */
	private function redirect_full_status( string $status, ?string $message = null ): void {
		$base = admin_url( 'admin.php?page=' . MKSDDN_MC_TEXT_DOMAIN );
		$url  = add_query_arg(
			array(
				'mksddn_mc_full_status' => $status,
				'mksddn_mc_full_error'  => $message,
			),
			$base
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Redirect back to plugin page with generic notice.
	 *
	 * @param string      $status  success|error.
	 * @param string|null $message Optional message.
	 */
	private function redirect_with_notice( string $status, ?string $message = null ): void {
		$base = admin_url( 'admin.php?page=' . MKSDDN_MC_TEXT_DOMAIN );
		$args = array(
			'mksddn_mc_notice' => $status,
		);

		if ( $message ) {
			$args['mksddn_mc_notice_message'] = $message;
		}

		wp_safe_redirect( add_query_arg( $args, $base ) );
		exit;
	}

	/**
	 * Return human-readable timezone label.
	 *
	 * @return string
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

	/**
	 * Extract only necessary fields for selection from POST data.
	 *
	 * @param array $post_data POST data.
	 * @return array Filtered and sanitized array with only selection-related fields.
	 */
	private function extract_selection_fields( array $post_data ): array {
		$allowed = array();

		// Extract and sanitize fields matching pattern selected_*_ids.
		foreach ( $post_data as $key => $value ) {
			if ( preg_match( '/^selected_(.+)_ids$/', sanitize_key( $key ) ) ) {
				$allowed[ sanitize_key( $key ) ] = is_array( $value ) ? array_map( 'absint', $value ) : array();
			}
		}

		// Extract and sanitize options_keys if present.
		if ( isset( $post_data['options_keys'] ) ) {
			$allowed['options_keys'] = is_array( $post_data['options_keys'] )
				? array_map( 'sanitize_text_field', $post_data['options_keys'] )
				: array();
		}

		// Extract and sanitize widget_groups if present.
		if ( isset( $post_data['widget_groups'] ) ) {
			$allowed['widget_groups'] = is_array( $post_data['widget_groups'] )
				? array_map( 'sanitize_text_field', $post_data['widget_groups'] )
				: array();
		}

		return $allowed;
	}

	/**
	 * Redirect to admin page with import progress indicator.
	 *
	 * Sends redirect to browser then continues execution in background.
	 * Uses fastcgi_finish_request() if available to close connection while
	 * allowing PHP to continue processing the import.
	 *
	 * @param string $history_id History entry ID for status polling.
	 * @return void
	 * @since 1.0.0
	 */
}
