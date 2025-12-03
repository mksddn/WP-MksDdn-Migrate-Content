<?php
/**
 * Admin UI for export/import.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Admin;

use Mksddn_MC\Archive\Extractor;
use Mksddn_MC\Export\ExportHandler;
use Mksddn_MC\Import\ImportHandler;
use Mksddn_MC\Options\OptionsHelper;
use Mksddn_MC\Filesystem\FullContentExporter;
use Mksddn_MC\Filesystem\FullContentImporter;
use Mksddn_MC\Chunking\ChunkJobRepository;
use Mksddn_MC\Selection\SelectionBuilder;
use Mksddn_MC\Recovery\SnapshotManager;
use Mksddn_MC\Recovery\HistoryRepository;
use Mksddn_MC\Recovery\JobLock;
use Mksddn_MC\Support\FilenameBuilder;
use Mksddn_MC\Support\SiteUrlGuard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller and renderer.
 */
class ExportImportAdmin {

	/**
	 * Archive extractor.
	 *
	 * @var Extractor
	 */
	private Extractor $extractor;

	private SnapshotManager $snapshot_manager;
	private HistoryRepository $history;
	private JobLock $job_lock;

	/**
	 * Hook admin actions.
	 */
	public function __construct( ?Extractor $extractor = null, ?SnapshotManager $snapshot_manager = null, ?HistoryRepository $history = null, ?JobLock $job_lock = null ) {
		$this->extractor        = $extractor ?? new Extractor();
		$this->snapshot_manager = $snapshot_manager ?? new SnapshotManager();
		$this->history          = $history ?? new HistoryRepository();
		$this->job_lock         = $job_lock ?? new JobLock();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_mksddn_mc_export_selected', array( $this, 'handle_selected_export' ) );
		add_action( 'admin_post_mksddn_mc_export_full', array( $this, 'handle_full_export' ) );
		add_action( 'admin_post_mksddn_mc_import_full', array( $this, 'handle_full_import' ) );
		add_action( 'admin_post_mksddn_mc_rollback_snapshot', array( $this, 'handle_snapshot_rollback' ) );
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
		$this->render_status_notices();
		$this->render_progress_container();

		$this->render_full_site_section();
		$this->render_selected_content_section();
		$this->render_history_section();
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
					'uploading'          => __( 'Uploading chunks… %d%', 'mksddn-migrate-content' ),
					'uploadError'        => __( 'Chunked upload failed. Please try again.', 'mksddn-migrate-content' ),
					'importSelected'     => __( 'Selected %1$s (planned chunk %2$s).', 'mksddn-migrate-content' ),
					'importBusy'         => __( 'Uploading archive… %d%', 'mksddn-migrate-content' ),
					'importDone'         => __( 'Upload finished. Processing…', 'mksddn-migrate-content' ),
					'importProcessing'   => __( 'Server is processing the archive…', 'mksddn-migrate-content' ),
					'importError'        => __( 'Upload failed. Please retry.', 'mksddn-migrate-content' ),
					'chunkInfo'          => __( '· %s chunks', 'mksddn-migrate-content' ),
					'preparing'        => __( 'Preparing download…', 'mksddn-migrate-content' ),
					'downloading'      => __( 'Downloading chunks… %d%', 'mksddn-migrate-content' ),
					'downloadComplete' => __( 'Download complete.', 'mksddn-migrate-content' ),
					'downloadError'    => __( 'Chunked download failed. Falling back to direct download.', 'mksddn-migrate-content' ),
					'exportReady'      => __( 'Ready for full export.', 'mksddn-migrate-content' ),
					'exportBusy'       => __( 'Preparing archive…', 'mksddn-migrate-content' ),
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
		if ( ! empty( $_GET['mksddn_mc_full_status'] ) ) {
		$status = sanitize_key( wp_unslash( $_GET['mksddn_mc_full_status'] ) );
		if ( 'success' === $status ) {
			$this->show_success( __( 'Full site operation completed successfully.', 'mksddn-migrate-content' ) );
			} elseif ( 'error' === $status && ! empty( $_GET['mksddn_mc_full_error'] ) ) {
				$this->show_error( sanitize_text_field( wp_unslash( $_GET['mksddn_mc_full_error'] ) ) );
			}
		}

		if ( empty( $_GET['mksddn_mc_notice'] ) ) {
			return;
		}

		$notice_status = sanitize_key( wp_unslash( $_GET['mksddn_mc_notice'] ) );
		$message       = isset( $_GET['mksddn_mc_notice_message'] ) ? sanitize_text_field( wp_unslash( $_GET['mksddn_mc_notice_message'] ) ) : '';

		if ( 'success' === $notice_status ) {
			$this->show_success( $message ?: __( 'Operation completed successfully.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( 'error' === $notice_status ) {
			$this->show_error( $message ?: __( 'Operation failed. Check logs for details.', 'mksddn-migrate-content' ) );
		}
	}


	/**
	 * Render progress container.
	 */
	private function render_progress_container(): void {
		echo '<style>
		#mksddn-mc-progress{margin:1rem 0;padding:1rem;border:1px solid #ddd;border-radius:6px;background:#fff;display:none;}
		#mksddn-mc-progress[aria-hidden="false"]{display:block;}
		#mksddn-mc-progress .mksddn-mc-progress__bar{width:100%;height:12px;background:#f0f0f0;border-radius:999px;overflow:hidden;margin-bottom:0.5rem;}
		#mksddn-mc-progress .mksddn-mc-progress__bar span{display:block;height:100%;width:0%;background:#2c7be5;transition:width .3s ease;}
		#mksddn-mc-progress .mksddn-mc-progress__label{margin:0;font-size:13px;color:#444;}
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
		.mksddn-mc-field input[type=\"file\"],
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
		</style>';

		echo '<div id="mksddn-mc-progress" class="mksddn-mc-progress" aria-hidden="true">';
		echo '<div class="mksddn-mc-progress__bar"><span></span></div>';
		echo '<p class="mksddn-mc-progress__label"></p>';
		echo '</div>';
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
			echo '<td>' . $this->format_status_badge( $entry['status'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $this->format_history_date( $entry['started_at'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_history_date( $entry['finished_at'] ?? '' ) ) . '</td>';
			echo '<td>' . ( $snapshot_id ? esc_html( $snapshot_label ) : '&mdash;' ) . '</td>';
			echo '<td>' . esc_html( $user_label ) . '</td>';
			echo '<td>' . $this->render_history_actions( $entry ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
		echo '</section>';
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
		if ( ! $this->can_rollback_entry( $entry ) ) {
			return '&mdash;';
		}

		$context     = $entry['context'] ?? array();
		$snapshot_id = sanitize_text_field( $context['snapshot_id'] ?? '' );
		$history_id  = sanitize_text_field( $entry['id'] ?? '' );
		$nonce       = wp_nonce_field( 'mksddn_mc_rollback_' . $history_id, '_wpnonce', true, false );

		$form  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$form .= $nonce;
		$form .= '<input type="hidden" name="action" value="mksddn_mc_rollback_snapshot">';
		$form .= '<input type="hidden" name="snapshot_id" value="' . esc_attr( $snapshot_id ) . '">';
		$form .= '<input type="hidden" name="history_id" value="' . esc_attr( $history_id ) . '">';
		$form .= '<button type="submit" class="button button-small">' . esc_html__( 'Rollback', 'mksddn-migrate-content' ) . '</button>';
		$form .= '</form>';

		return '<div class="mksddn-mc-history__actions">' . $form . '</div>';
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
		echo '<h3>' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="mksddn-mc-full-import-form" data-mksddn-full-import="true">';
		wp_nonce_field( 'mksddn_mc_full_import' );
		echo '<input type="hidden" name="action" value="mksddn_mc_import_full">';
		echo '<input type="file" name="full_import_file" accept=".wpbkp" required><br><br>';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Import Full Site', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '</div>';
		echo '</section>';
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
		echo '<script>window.mksddnMcProgress && window.mksddnMcProgress.hide && window.mksddnMcProgress.hide();</script>';
	}

	/**
	 * Render error notice.
	 *
	 * @param string $message Message text.
	 */
	private function show_error( string $message ): void {
		echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
		echo '<script>window.mksddnMcProgress && window.mksddnMcProgress.hide && window.mksddnMcProgress.hide();</script>';
	}

	/**
	 * Render helper script.
	 */
	private function render_javascript(): void {
		echo '<script>
		window.mksddnMcProgress = (function(){
			const container = document.getElementById("mksddn-mc-progress");
			if(!container){return null;}
			const bar = container.querySelector(".mksddn-mc-progress__bar span");
			const label = container.querySelector(".mksddn-mc-progress__label");
			return {
				set(percent, text){
					if(!bar){return;}
					container.setAttribute("aria-hidden","false");
					const clamped = Math.max(0, Math.min(100, percent));
					bar.style.width = clamped + "%";
					if(label){ label.textContent = text || ""; }
				},
				hide(){
					if(!bar){return;}
					container.setAttribute("aria-hidden","true");
					bar.style.width = "0%";
					if(label){ label.textContent = ""; }
				}
			}
		})();

        </script>';
	}

	/**
	 * Output inline progress update.
	 *
	 * @param int    $percent Percent value.
	 * @param string $message Label.
	 */
	private function progress_tick( int $percent, string $message ): void {
		printf(
			'<script>window.mksddnMcProgress && window.mksddnMcProgress.set(%1$d, %2$s);</script>',
			absint( $percent ),
			wp_json_encode( $message )
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

		$builder    = new SelectionBuilder();
		$selection  = $builder->from_request( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- already verified above.
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

		$temp = wp_tempnam( 'mksddn-full-' );
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

		$chunk_job_id  = isset( $_POST['chunk_job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chunk_job_id'] ) ) : '';
		$temp          = '';
		$cleanup       = false;
		$job           = null;
		$original_name = '';

		if ( ! MKSDDN_MC_DISABLE_CHUNKED ) {
		if ( $chunk_job_id ) {
			$repo = new ChunkJobRepository();
			$job  = $repo->get( $chunk_job_id );
			$temp = $job->get_file_path();

			if ( ! file_exists( $temp ) ) {
				$this->redirect_full_status( 'error', __( 'Chunked upload is incomplete.', 'mksddn-migrate-content' ) );
			}

				$original_name = sprintf( 'chunk:%s', $chunk_job_id );
			}
		}

		if ( ! $temp ) {
			if ( ! isset( $_FILES['full_import_file'], $_FILES['full_import_file']['tmp_name'] ) ) {
				$this->redirect_full_status( 'error', __( 'No file uploaded.', 'mksddn-migrate-content' ) );
			}

			$file     = $_FILES['full_import_file'];
			$tmp_name = sanitize_text_field( (string) $file['tmp_name'] );
			$name     = sanitize_file_name( (string) $file['name'] );
			$size     = isset( $file['size'] ) ? (int) $file['size'] : 0;

			if ( 0 >= $size ) {
				$this->redirect_full_status( 'error', __( 'Invalid file size.', 'mksddn-migrate-content' ) );
			}

			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( 'wpbkp' !== $ext ) {
				$this->redirect_full_status( 'error', __( 'Please upload a .wpbkp archive.', 'mksddn-migrate-content' ) );
			}

			$temp = wp_tempnam( 'mksddn-full-import-' );
			if ( ! $temp ) {
				$this->redirect_full_status( 'error', __( 'Unable to allocate temp file for import.', 'mksddn-migrate-content' ) );
			}

			if ( ! move_uploaded_file( $tmp_name, $temp ) ) {
				$this->redirect_full_status( 'error', __( 'Failed to move uploaded file.', 'mksddn-migrate-content' ) );
			}

			$cleanup       = true;
			$original_name = $name;
		}

		if ( $chunk_job_id && ( defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) && MKSDDN_MC_DISABLE_CHUNKED ) ) {
			$this->redirect_full_status( 'error', __( 'Chunked uploads are disabled.', 'mksddn-migrate-content' ) );
		}

		if ( ( ! defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) || ! MKSDDN_MC_DISABLE_CHUNKED ) && $chunk_job_id ) {
			$repo = new ChunkJobRepository();
			$job  = $repo->get( $chunk_job_id );
			$temp = $job->get_file_path();

			if ( ! file_exists( $temp ) ) {
				$this->redirect_full_status( 'error', __( 'Chunked upload is incomplete.', 'mksddn-migrate-content' ) );
			}

			$original_name = sprintf( 'chunk:%s', $chunk_job_id );
		} else {
			if ( defined( 'MKSDDN_MC_DISABLE_CHUNKED' ) && MKSDDN_MC_DISABLE_CHUNKED && $chunk_job_id ) {
				$this->redirect_full_status( 'error', __( 'Chunked uploads are disabled.', 'mksddn-migrate-content' ) );
			}
		}

		$lock_id = $this->job_lock->acquire( 'full-import' );
		if ( is_wp_error( $lock_id ) ) {
			$this->cleanup_full_import( $temp, $cleanup, $job );
			$this->redirect_full_status( 'error', $lock_id->get_error_message() );
		}

		$snapshot = $this->snapshot_manager->create(
			array(
				'label'            => 'pre-import-full',
				'include_plugins'  => true,
				'include_themes'   => true,
				'meta'             => array( 'file' => $original_name ),
			)
		);

		if ( is_wp_error( $snapshot ) ) {
			$this->job_lock->release( $lock_id );
			$this->cleanup_full_import( $temp, $cleanup, $job );
			$this->redirect_full_status( 'error', $snapshot->get_error_message() );
		}

		$history_id = $this->history->start(
			'import',
			array(
				'mode'           => 'full',
				'file'           => $original_name,
				'snapshot_id'    => $snapshot['id'],
				'snapshot_label' => $snapshot['label'] ?? $snapshot['id'],
			)
		);

		$site_guard = new SiteUrlGuard();
		$importer   = new FullContentImporter();
		$result     = $importer->import_from( $temp, $site_guard );

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
			$site_guard->restore();
			$this->normalize_plugin_storage();
			$this->history->finish( $history_id, 'success' );
		}

		$this->job_lock->release( $lock_id );
		$this->cleanup_full_import( $temp, $cleanup, $job );
		$this->redirect_full_status( $status, $message );
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
		update_option( 'ai1wm_storage_path', $target );
	}

	/**
	 * Output file download headers and contents.
	 *
	 * @param string $path     Absolute file path.
	 * @param string $filename Download filename.
	 */
	private function stream_file_download( string $path, string $filename ): void {
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

		$handle = fopen( $path, 'rb' );
		if ( $handle ) {
			fpassthru( $handle );
			fclose( $handle );
		}

		unlink( $path );
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
			unlink( $temp );
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
}
