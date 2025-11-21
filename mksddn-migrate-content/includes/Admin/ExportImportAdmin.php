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

	/**
	 * Hook admin actions.
	 */
	public function __construct( ?Extractor $extractor = null ) {
		$this->extractor = $extractor ?? new Extractor();

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_post_export_single_page', array( $this, 'handle_export' ) );
		add_action( 'admin_post_mksddn_mc_export_full', array( $this, 'handle_full_export' ) );
		add_action( 'admin_post_mksddn_mc_import_full', array( $this, 'handle_full_import' ) );
	}

	/**
	 * Register admin menu page.
	 */
	public function add_admin_menu(): void {
		add_menu_page(
			__( 'Export and Import', 'mksddn-migrate-content' ),
			__( 'Export & Import', 'mksddn-migrate-content' ),
			'manage_options',
			MKSDDN_MC_TEXT_DOMAIN,
			array( $this, 'render_admin_page' ),
			'dashicons-download',
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
		echo '<h1>' . esc_html__( 'Export & Import', 'mksddn-migrate-content' ) . '</h1>';
		$this->render_status_notices();
		$this->render_progress_container();

		$this->render_export_form();
		$this->render_import_form();
		$this->render_full_site_cards();
		$this->handle_import();

		echo '</div>';
		$this->render_javascript();
	}
	/**
	 * Display status notices after redirects.
	 */
	private function render_status_notices(): void {
		if ( empty( $_GET['mksddn_mc_full_status'] ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['mksddn_mc_full_status'] ) );
		if ( 'success' === $status ) {
			$this->show_success( __( 'Full site operation completed successfully.', 'mksddn-migrate-content' ) );
			return;
		}

		if ( 'error' === $status && ! empty( $_GET['mksddn_mc_full_error'] ) ) {
			$this->show_error( sanitize_text_field( wp_unslash( $_GET['mksddn_mc_full_error'] ) ) );
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
		.mksddn-mc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.5rem;margin-top:1rem;}
		.mksddn-mc-card{background:#fff;border:1px solid #e1e1e1;border-radius:8px;padding:1rem;box-shadow:0 1px 2px rgba(0,0,0,.04);}
		.mksddn-mc-card h3{margin-top:0;}
		.mksddn-mc-multiselect{width:100%;}
		.mksddn-mc-format-selector select{width:100%;}
		</style>';

		echo '<div id="mksddn-mc-progress" class="mksddn-mc-progress" aria-hidden="true">';
		echo '<div class="mksddn-mc-progress__bar"><span></span></div>';
		echo '<p class="mksddn-mc-progress__label"></p>';
		echo '</div>';
	}

	/**
	 * Render export form.
	 */
	private function render_export_form(): void {
		echo '<h2>' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'export_single_page_nonce' );

		echo '<input type="hidden" name="action" value="export_single_page">';
		echo '<div class="mksddn-mc-grid">';
		echo '<div class="mksddn-mc-card">';
		$this->render_type_selector();
		$this->render_selection_fields();
		echo '</div>';

		echo '<div class="mksddn-mc-card">';
		$this->render_format_selector();
		echo '</div>';
		echo '</div>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render export type selector.
	 */
	private function render_type_selector(): void {
		echo '<h3>' . esc_html__( 'Single item export', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Select a content type to export.', 'mksddn-migrate-content' ) . '</p>';
		echo '<label for="export_type">' . esc_html__( 'Content type:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_type" name="export_type" onchange="mksddnToggleType()" required>';
		$first = true;
		foreach ( $this->get_exportable_post_types() as $type => $label ) {
			$selected = $first ? ' selected' : '';
			echo '<option value="' . esc_attr( $type ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
			$first = false;
		}
		echo '</select><br><br>';
	}

	/**
	 * Render selection fields.
	 */
	private function render_selection_fields(): void {
		echo '<div class="mksddn-mc-basic-selection">';
		foreach ( $this->get_exportable_post_types() as $type => $label ) {
			$default = ( 'page' === $type );
			$this->render_single_select(
				$type,
				sprintf(
					/* translators: %s type label */
					__( 'Select %s:', 'mksddn-migrate-content' ),
					strtolower( $label )
				),
				$this->get_items_for_type( $type ),
				! $default
			);
		}
		echo '</div>';
	}

	/**
	 * Render a select for a specific post type.
	 *
	 * @param string  $type   Post type slug.
	 * @param string  $label  Label text.
	 * @param WP_Post[] $items Items to populate.
	 * @param bool    $hidden Hide by default.
	 */
	private function render_single_select( string $type, string $label, array $items, bool $hidden = false ): void {
		$style = $hidden ? 'style="display:none;"' : '';
		echo '<div class="mksddn-mc-type-select mksddn-mc-type-' . esc_attr( $type ) . '" ' . $style . '>';
		echo '<label for="export_' . esc_attr( $type ) . '_id">' . esc_html( $label ) . '</label><br>';
		echo '<select id="export_' . esc_attr( $type ) . '_id" name="' . esc_attr( $type ) . '_id">';
		echo '<option value="">' . esc_html__( 'Select item...', 'mksddn-migrate-content' ) . '</option>';
		foreach ( $items as $item ) {
			echo '<option value="' . esc_attr( $item->ID ) . '">' . esc_html( $item->post_title ?: $item->ID ) . '</option>';
		}
		echo '</select><br><br>';
		echo '</div>';
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
		echo '<h3>' . esc_html__( 'Export format', 'mksddn-migrate-content' ) . '</h3>';
		echo '<label for="export_format">' . esc_html__( 'Choose file format:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_format" name="export_format">';
		echo '<option value="archive" selected>' . esc_html__( '.wpbkp (archive with manifest)', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="json">' . esc_html__( '.json (content only, editable)', 'mksddn-migrate-content' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( '.json skips media files and is best for quick edits. .wpbkp packs media + checksum.', 'mksddn-migrate-content' ) . '</p><br>';
		echo '</div>';
	}

	/**
	 * Render import form.
	 */
	private function render_import_form(): void {
		echo '<h2>' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data">';
		wp_nonce_field( 'import_single_page_nonce' );

		echo '<label for="import_file">' . esc_html__( 'Upload .wpbkp or .json file:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<input type="file" id="import_file" name="import_file" accept=".wpbkp,.json" required><br>';
		echo '<p class="description">' . esc_html__( 'Archives include media and integrity checks. JSON imports skip media restoration.', 'mksddn-migrate-content' ) . '</p><br>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render full-site action cards.
	 */
	private function render_full_site_cards(): void {
		echo '<div class="mksddn-mc-grid">';
		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Full Site Export', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Package uploads, plugins, and themes into a single archive. Large installs may take a while.', 'mksddn-migrate-content' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mksddn_mc_full_export' );
		echo '<input type="hidden" name="action" value="mksddn_mc_export_full">';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Export Full Site (.wpbkp)', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';

		echo '<div class="mksddn-mc-card">';
		echo '<h3>' . esc_html__( 'Full Site Import', 'mksddn-migrate-content' ) . '</h3>';
		echo '<p>' . esc_html__( 'Restore uploads, plugins, and themes from a .wpbkp archive. Existing files with matching paths will be overwritten.', 'mksddn-migrate-content' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mksddn_mc_full_import' );
		echo '<input type="hidden" name="action" value="mksddn_mc_import_full">';
		echo '<input type="file" name="full_import_file" accept=".wpbkp" required><br><br>';
		echo '<button type="submit" class="button button-secondary">' . esc_html__( 'Import Full Site', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Handle import POST.
	 */
	private function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $request_method || ! check_admin_referer( 'import_single_page_nonce' ) ) {
			return;
		}

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

		$payload            = $result['payload'];
		$payload_type       = $result['type'];
		$payload['type']    = $payload_type;
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
		} else {
			$this->progress_tick( 100, __( 'Import failed', 'mksddn-migrate-content' ) );
			$this->show_error( esc_html__( 'Failed to import content.', 'mksddn-migrate-content' ) );
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
		return $import_handler->import_single_page( $data );
	}

	/**
	 * Render success notice.
	 *
	 * @param string $message Message text.
	 */
	private function show_success( string $message ): void {
		echo '<div class="updated"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Render error notice.
	 *
	 * @param string $message Message text.
	 */
	private function show_error( string $message ): void {
		echo '<div class="error"><p>' . esc_html( $message ) . '</p></div>';
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
				}
			}
		})();

		window.mksddnToggleType = function(){
			const exportType = document.getElementById("export_type");
			if(!exportType){return;}
			const type = exportType.value;
			document.querySelectorAll(".mksddn-mc-type-select").forEach((el)=>{
				el.style.display = "none";
			});
			const target = document.querySelector(".mksddn-mc-type-" + type);
			if(target){
				target.style.display = "block";
			}
		};

		window.mksddnToggleType();
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
	 * Handle export POST.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export.', 'mksddn-migrate-content' ) );
		}

		if ( ! check_admin_referer( 'export_single_page_nonce' ) ) {
			wp_die( esc_html__( 'Invalid request', 'mksddn-migrate-content' ) );
		}

		$export_handler = new ExportHandler();
		$export_handler->export_single_page();
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

		$filename = 'full-site-' . gmdate( 'Ymd-His' ) . '.wpbkp';
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

		$importer = new FullContentImporter();
		$result   = $importer->import_from( $temp );
		unlink( $temp );

		if ( is_wp_error( $result ) ) {
			$this->redirect_full_status( 'error', $result->get_error_message() );
		}

		$this->redirect_full_status( 'success' );
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
}
