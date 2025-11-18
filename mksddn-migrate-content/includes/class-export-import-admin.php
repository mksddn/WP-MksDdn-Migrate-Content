<?php
/**
 * Admin UI for export/import.
 *
 * @package MksDdn_Migrate_Content
 */

/**
 * Admin controller and renderer.
 */
class Export_Import_Admin {

	/**
	 * Hook admin actions.
	 */
	public function __construct() {
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
		$options_helper = new Options_Helper();
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

		echo '<label for="import_file">' . esc_html__( 'Upload JSON file:', 'mksddn-migrate-content' ) . '</label><br>';
		echo '<input type="file" id="import_file" name="import_file" accept=".json" required><br><br>';

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

		if ( 0 >= $size || ( 10 * 1024 * 1024 ) < $size ) {
			$this->show_error( esc_html__( 'Invalid file size. Max 10MB.', 'mksddn-migrate-content' ) );
			return;
		}

		$ext           = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$mime          = function_exists( 'mime_content_type' ) && '' !== $file ? mime_content_type( $file ) : '';
		$allowed_mimes = array( 'application/json', 'text/plain' );

		if ( 'json' !== $ext || ( '' !== $mime && ! in_array( $mime, $allowed_mimes, true ) ) ) {
			$this->show_error( esc_html__( 'Invalid file type.', 'mksddn-migrate-content' ) );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file -- Local uploaded file path validated above.
		$json = file_get_contents( $file );
		$data = json_decode( $json, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$this->show_error( esc_html__( 'Invalid JSON file.', 'mksddn-migrate-content' ) );
			return;
		}

		$import_handler = new Import_Handler();
		$result         = $this->process_import( $import_handler, $data );

		if ( $result ) {
			$type = $data['type'] ?? 'page';
			// translators: %s is imported item type.
			$this->show_success( sprintf( esc_html__( '%s imported successfully!', 'mksddn-migrate-content' ), ucfirst( (string) $type ) ) );
		} else {
			$this->show_error( esc_html__( 'Failed to import content.', 'mksddn-migrate-content' ) );
		}
	}

	/**
	 * Dispatch import by type.
	 *
	 * @param Import_Handler $import_handler Handler instance.
	 * @param array          $data           Payload.
	 * @return bool
	 */
	private function process_import( Import_Handler $import_handler, array $data ): bool {
		if ( ! isset( $data['type'] ) ) {
			return $import_handler->import_single_page( $data );
		}

		return match ( $data['type'] ) {
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

		$export_handler = new Export_Handler();
		$export_handler->export_single_page();
	}
}
