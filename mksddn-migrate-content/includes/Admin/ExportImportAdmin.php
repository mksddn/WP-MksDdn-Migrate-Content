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

		$this->render_export_form();
		$this->render_import_form();
		$this->handle_import();

		echo '</div>';

		$this->render_javascript();
	}

	/**
	 * Render export form.
	 */
	private function render_export_form(): void {
		echo '<h2>' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'export_single_page_nonce' );

		echo '<input type="hidden" name="action" value="export_single_page">';
		$this->render_type_selector();
		$this->render_selection_fields();
		$this->render_format_selector();

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Export', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Render export type selector.
	 */
	private function render_type_selector(): void {
		echo '<label for="export_type">' . esc_html__( 'Select type to export:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_type" name="export_type" onchange="toggleExportOptions()" required>';
		echo '<option value="">' . esc_html__( 'Select type...', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="page">' . esc_html__( 'Page', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="options_page">' . esc_html__( 'Options Page', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="forms">' . esc_html__( 'Form', 'mksddn-migrate-content' ) . '</option>';
		echo '</select><br><br>';
	}

	/**
	 * Render selection fields.
	 */
	private function render_selection_fields(): void {
		// Page selection.
		echo '<div id="page_selection" style="display:none;">';
		echo '<label for="export_page_id">' . esc_html__( 'Select a page to export:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_page_id" name="page_id">';
		echo '<option value="">' . esc_html__( 'Select page...', 'mksddn-migrate-content' ) . '</option>';
		foreach ( get_pages() as $page ) {
			echo '<option value="' . esc_attr( $page->ID ) . '">' . esc_html( $page->post_title ) . '</option>';
		}

		echo '</select><br><br>';
		echo '</div>';

		// Options Page selection.
		echo '<div id="options_page_selection" style="display:none;">';
		echo '<label for="export_options_page_slug">' . esc_html__( 'Select an options page to export:', 'mksddn-migrate-content' ) . '</label><br>';
		$options_helper = new OptionsHelper();
		echo '<select id="export_options_page_slug" name="options_page_slug">';
		echo '<option value="">' . esc_html__( 'Select options page...', 'mksddn-migrate-content' ) . '</option>';
		foreach ( $options_helper->get_all_options_pages() as $page ) {
			$title = $page['page_title'] ?? $page['menu_title'] ?? ucfirst( str_replace( '-', ' ', $page['menu_slug'] ) );
			echo '<option value="' . esc_attr( $page['menu_slug'] ) . '">' . esc_html( $title ) . '</option>';
		}

		echo '</select><br><br>';
		echo '</div>';

		// Forms selection.
		echo '<div id="forms_selection" style="display:none;">';
		echo '<label for="export_form_id">' . esc_html__( 'Select a form to export:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_form_id" name="form_id">';
		echo '<option value="">' . esc_html__( 'Select form...', 'mksddn-migrate-content' ) . '</option>';
		foreach ( $this->get_forms() as $form ) {
			echo '<option value="' . esc_attr( $form->ID ) . '">' . esc_html( $form->post_title ) . '</option>';
		}

		echo '</select><br><br>';
		echo '</div>';
	}

	/**
	 * Render file format selector.
	 */
	private function render_format_selector(): void {
		echo '<div class="mksddn-mc-format-selector">';
		echo '<label for="export_format">' . esc_html__( 'Choose file format:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<select id="export_format" name="export_format">';
		echo '<option value="archive" selected>' . esc_html__( '.wpbkp (archive with manifest)', 'mksddn-migrate-content' ) . '</option>';
		echo '<option value="json">' . esc_html__( '.json (content only, editable)', 'mksddn-migrate-content' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Use .json for fast edits of single items. Use .wpbkp for integrity-checked archives.', 'mksddn-migrate-content' ) . '</p><br>';
		echo '</div>';
	}

	/**
	 * Get forms list.
	 *
	 * @return WP_Post[]
	 */
	private function get_forms(): array {
		return get_posts(
			array(
				'post_type'      => 'forms',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);
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
		echo '<p class="description">' . esc_html__( 'Archives keep checksum verification. JSON fits manual edits for single items.', 'mksddn-migrate-content' ) . '</p><br>';

		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Import', 'mksddn-migrate-content' ) . '</button>';
		echo '</form>';
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
			return;
		}

		$file     = isset( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['import_file']['tmp_name'] ) : '';
		$filename = isset( $_FILES['import_file']['name'] ) ? sanitize_file_name( (string) $_FILES['import_file']['name'] ) : '';
		$size     = isset( $_FILES['import_file']['size'] ) ? (int) $_FILES['import_file']['size'] : 0;

		if ( 0 >= $size ) {
			$this->show_error( esc_html__( 'Invalid file size.', 'mksddn-migrate-content' ) );
			return;
		}

		$ext  = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime = function_exists( 'mime_content_type' ) && '' !== $file ? mime_content_type( $file ) : '';

		$result = $this->prepare_import_payload( $ext, $mime, $file );

		if ( is_wp_error( $result ) ) {
			$this->show_error( $result->get_error_message() );
			return;
		}

		$payload      = $result['payload'];
		$payload_type = $result['type'];
		$payload['type'] = $payload_type;

		$import_handler = new ImportHandler();
		$result         = $this->process_import( $import_handler, $payload_type, $payload );

		if ( $result ) {
			// translators: %s is imported item type.
			$this->show_success( sprintf( esc_html__( '%s imported successfully!', 'mksddn-migrate-content' ), ucfirst( (string) $payload_type ) ) );
		} else {
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
					'type'    => $data['type'] ?? 'page',
					'payload' => $data,
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
					'type'    => $extracted['type'],
					'payload' => $extracted['payload'],
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
		return match ( $type ) {
			'options_page' => $import_handler->import_options_page( $data ),
			'forms' => $import_handler->import_form( $data ),
			default => $import_handler->import_single_page( $data ),
		};
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
        function toggleExportOptions() {
            const exportType = document.getElementById("export_type").value;
            const pageSelection = document.getElementById("page_selection");
            const optionsPageSelection = document.getElementById("options_page_selection");
            const formsSelection = document.getElementById("forms_selection");
            
            [pageSelection, optionsPageSelection, formsSelection].forEach(el => el.style.display = "none");
            
            switch(exportType) {
                case "page":
                    pageSelection.style.display = "block";
                    break;
                case "options_page":
                    optionsPageSelection.style.display = "block";
                    break;
                case "forms":
                    formsSelection.style.display = "block";
                    break;
            }
        }
        </script>';
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
}
