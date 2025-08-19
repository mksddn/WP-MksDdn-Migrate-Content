<?php

class Export_Import_Admin {


    public function __construct() {
        add_action('admin_menu', $this->add_admin_menu(...));
        add_action('admin_post_export_single_page', $this->handle_export(...));
    }


    public function add_admin_menu(): void {
        add_menu_page(
            'Export and Import',
            'Export & Import',
            'manage_options',
            'export-import-single-page',
            $this->render_admin_page(...),
            'dashicons-download',
            20
        );
    }


    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Export & Import</h1>';

        $this->render_export_form();
        $this->render_import_form();
        $this->handle_import();

        echo '</div>';

        $this->render_javascript();
    }


    private function render_export_form(): void {
        echo '<h2>Export</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('export_single_page_nonce');

        echo '<input type="hidden" name="action" value="export_single_page">';
        $this->render_type_selector();
        $this->render_selection_fields();

        echo '<button type="submit" class="button button-primary">Export</button>';
        echo '</form>';
    }


    private function render_type_selector(): void {
        echo '<label for="export_type">Select type to export:</label><br>';
        echo '<select id="export_type" name="export_type" onchange="toggleExportOptions()" required>';
        echo '<option value="">Select type...</option>';
        echo '<option value="page">Page</option>';
        echo '<option value="options_page">Options Page</option>';
        echo '<option value="forms">Form</option>';
        echo '</select><br><br>';
    }


    private function render_selection_fields(): void {
        // Page selection
        echo '<div id="page_selection" style="display:none;">';
        echo '<label for="export_page_id">Select a page to export:</label><br>';
        echo '<select id="export_page_id" name="page_id">';
        echo '<option value="">Select page...</option>';
        foreach (get_pages() as $page) {
            echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
        }

        echo '</select><br><br>';
        echo '</div>';

        // Options Page selection
        echo '<div id="options_page_selection" style="display:none;">';
        echo '<label for="export_options_page_slug">Select an options page to export:</label><br>';
        $options_helper = new Options_Helper();
        echo '<select id="export_options_page_slug" name="options_page_slug">';
        echo '<option value="">Select options page...</option>';
        foreach ($options_helper->get_all_options_pages() as $page) {
            $title = $page['page_title'] ?? $page['menu_title'] ?? ucfirst(str_replace('-', ' ', $page['menu_slug']));
            echo '<option value="' . esc_attr($page['menu_slug']) . '">' . esc_html($title) . '</option>';
        }

        echo '</select><br><br>';
        echo '</div>';

        // Forms selection
        echo '<div id="forms_selection" style="display:none;">';
        echo '<label for="export_form_id">Select a form to export:</label><br>';
        echo '<select id="export_form_id" name="form_id">';
        echo '<option value="">Select form...</option>';
        foreach ($this->get_forms() as $form) {
            echo '<option value="' . esc_attr($form->ID) . '">' . esc_html($form->post_title) . '</option>';
        }

        echo '</select><br><br>';
        echo '</div>';
    }


    private function get_forms(): array {
        return get_posts([
            'post_type' => 'forms',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);
    }


    private function render_import_form(): void {
        echo '<h2>Import</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('import_single_page_nonce');

        echo '<label for="import_file">Upload JSON file:</label><br>';
        echo '<input type="file" id="import_file" name="import_file" accept=".json" required><br><br>';

        echo '<button type="submit" class="button button-primary">Import</button>';
        echo '</form>';
    }


    private function handle_import(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_admin_referer('import_single_page_nonce')) {
            return;
        }

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->show_error('Failed to upload file.');
            return;
        }

        $file = $_FILES['import_file']['tmp_name'];
        if (mime_content_type($file) !== 'application/json') {
            $this->show_error('Invalid file type.');
            return;
        }

        $json = file_get_contents($file);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->show_error('Invalid JSON file.');
            return;
        }

        $import_handler = new Import_Handler();
        $result = $this->process_import($import_handler, $data);

        if ($result) {
            $type = $data['type'] ?? 'page';
            $this->show_success(sprintf('%s imported successfully!', ucfirst((string) $type)));
        } else {
            $this->show_error('Failed to import content.');
        }
    }


    private function process_import(Import_Handler $import_handler, array $data): bool {
        if (!isset($data['type'])) {
            return $import_handler->import_single_page($data);
        }

        return match ($data['type']) {
            'options_page' => $import_handler->import_options_page($data),
            'forms' => $import_handler->import_form($data),
            default => $import_handler->import_single_page($data),
        };
    }


    private function show_success(string $message): void {
        echo '<div class="updated"><p>' . esc_html__($message, 'export-import-single-page') . '</p></div>';
    }


    private function show_error(string $message): void {
        echo '<div class="error"><p>' . esc_html__($message, 'export-import-single-page') . '</p></div>';
    }


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


    public function handle_export(): void {
        if (!check_admin_referer('export_single_page_nonce')) {
            wp_die('Invalid request');
        }

        $export_handler = new Export_Handler();
        $export_handler->export_single_page();
    }


}
