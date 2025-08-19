<?php

class Export_Handler {

    private const EXPORT_TYPES = [
        'page' => 'export_page',
        'options_page' => 'export_options_page',
        'forms' => 'export_form'
    ];


    public function export_single_page(): void {
        $export_type = sanitize_text_field($_POST['export_type'] ?? '');

        if (!isset(self::EXPORT_TYPES[$export_type])) {
            wp_die(esc_html__('Invalid export type.', 'export-import-single-page'));
        }

        $method = self::EXPORT_TYPES[$export_type];
        $this->$method();
    }


    private function export_options_page(): void {
        $options_page_slug = sanitize_text_field($_POST['options_page_slug'] ?? '');
        if (empty($options_page_slug)) {
            wp_die(esc_html__('Invalid options page slug.', 'export-import-single-page'));
        }

        $options_helper = new Options_Helper();
        $options_pages = $options_helper->get_all_options_pages();
        $target_page = $this->find_options_page($options_pages, $options_page_slug);

        if (!$target_page) {
            wp_die(esc_html__('Invalid options page slug.', 'export-import-single-page'));
        }

        $data = $this->prepare_options_page_data($target_page);
        $filename = 'options-page-' . $options_page_slug . '.json';
        $this->download_json($data, $filename);
    }


    private function export_page(): void {
        $page_id = intval($_POST['page_id'] ?? 0);
        if ($page_id === 0) {
            wp_die(esc_html__('Invalid request', 'export-import-single-page'));
        }

        $page = get_post($page_id);
        if (!$page || $page->post_type !== 'page') {
            wp_die(esc_html__('Invalid page ID.', 'export-import-single-page'));
        }

        $data = $this->prepare_page_data($page);
        $filename = 'page-' . $page_id . '.json';
        $this->download_json($data, $filename);
    }


    private function export_form(): void {
        $form_id = intval($_POST['form_id'] ?? 0);
        if ($form_id === 0) {
            wp_die(esc_html__('Invalid request', 'export-import-single-page'));
        }

        $form = get_post($form_id);
        if (!$form || $form->post_type !== 'forms') {
            wp_die(esc_html__('Invalid form ID.', 'export-import-single-page'));
        }

        $data = $this->prepare_form_data($form);
        $filename = 'form-' . $form_id . '.json';
        $this->download_json($data, $filename);
    }


    private function find_options_page(array $options_pages, string $slug): ?array {
        foreach ($options_pages as $page) {
            if ($page['menu_slug'] === $slug) {
                return $page;
            }
        }

        return null;
    }


    private function prepare_options_page_data(array $target_page): array {
        return [
            'type'       => 'options_page',
            'menu_slug'  => $target_page['menu_slug'],
            'page_title' => $target_page['page_title'] ?? '',
            'menu_title' => $target_page['menu_title'] ?? '',
            'post_id'    => $target_page['post_id'] ?? '',
            'acf_fields' => function_exists('get_fields') ? get_fields($target_page['post_id']) : [],
        ];
    }


    private function prepare_page_data(WP_Post $page): array {
        return [
            'type'       => 'page',
            'ID'         => $page->ID,
            'title'      => $page->post_title,
            'content'    => $page->post_content,
            'excerpt'    => $page->post_excerpt,
            'slug'       => $page->post_name,
            'acf_fields' => function_exists('get_fields') ? get_fields($page->ID) : [],
            'meta'       => get_post_meta($page->ID),
        ];
    }


    private function prepare_form_data(WP_Post $form): array {
        $fields_config = get_post_meta($form->ID, '_fields_config', true);

        return [
            'type'           => 'forms',
            'ID'             => $form->ID,
            'title'          => $form->post_title,
            'content'        => $form->post_content,
            'excerpt'        => $form->post_excerpt,
            'slug'           => $form->post_name,
            'fields_config'  => $fields_config,
            'fields'         => json_decode($fields_config, true),
            'acf_fields'     => function_exists('get_fields') ? get_fields($form->ID) : [],
            'meta'           => get_post_meta($form->ID),
        ];
    }


    private function download_json(array $data, string $filename): void {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Clear all output buffering levels
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for file download
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));

        echo $json;
        exit;
    }


}
