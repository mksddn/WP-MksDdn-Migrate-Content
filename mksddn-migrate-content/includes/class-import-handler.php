<?php

class Import_Handler {


    /**
     * Imports a single page with ACF fields.
     *
     * @param array $data Data array containing page information.
     * @return bool True on success, false on failure.
     */
    public function import_single_page(array $data): bool {
        if (!$this->validate_page_data($data)) {
            return false;
        }

        $existing_page = get_page_by_path($data['slug'], OBJECT, 'page');
        $page_data = $this->prepare_page_data($data);

        $page_id = $existing_page ? $this->update_page($existing_page, $page_data) : $this->create_page($page_data);

        if (is_wp_error($page_id)) {
            return false;
        }

        $this->import_acf_fields($data, $page_id);
        $this->import_meta_data($data, $page_id);

        return true;
    }


    /**
     * Imports ACF fields for an options page.
     *
     * @param array $data Data array containing 'menu_slug', 'acf_fields', and optionally 'post_id'.
     * @return bool True on success, false on failure.
     */
    public function import_options_page($data): bool {
        if (!$this->validate_options_page_data($data)) {
            return false;
        }

        $post_id = sanitize_text_field($data['post_id'] ?? 'option');
        $this->import_acf_fields($data, $post_id);

        return true;
    }


    /**
     * Imports a form with fields configuration.
     *
     * @param array $data Data array containing form information.
     * @return bool True on success, false on failure.
     */
    public function import_form(array $data): bool {
        if (!$this->validate_form_data($data)) {
            return false;
        }

        $existing_form = get_page_by_path($data['slug'], OBJECT, 'forms');
        $form_data = $this->prepare_form_data($data);

        $form_id = $existing_form ? $this->update_form($existing_form, $form_data) : $this->create_form($form_data);

        if (is_wp_error($form_id)) {
            return false;
        }

        $this->import_fields_config($data, $form_id);
        $this->import_acf_fields($data, $form_id);
        $this->import_meta_data($data, $form_id);

        // Force refresh and clear caches
        clean_post_cache($form_id);
        wp_cache_flush();

        return true;
    }


    private function validate_page_data(array $data): bool {
        return isset($data['title'], $data['content'], $data['slug']);
    }


    private function validate_options_page_data(array $data): bool {
        return isset($data['menu_slug'], $data['acf_fields']);
    }


    private function validate_form_data(array $data): bool {
        return isset($data['title'], $data['slug']);
    }


    private function prepare_page_data(array $data): array {
        return [
            'post_title'   => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['content']),
            'post_excerpt' => sanitize_text_field($data['excerpt'] ?? ''),
            'post_name'    => sanitize_title($data['slug']),
            'post_type'    => 'page',
            'post_status'  => 'publish',
        ];
    }


    private function prepare_form_data(array $data): array {
        return [
            'post_title'   => sanitize_text_field($data['title']),
            'post_content' => wp_kses_post($data['content'] ?? ''),
            'post_excerpt' => sanitize_text_field($data['excerpt'] ?? ''),
            'post_name'    => sanitize_title($data['slug']),
            'post_type'    => 'forms',
            'post_status'  => 'publish',
        ];
    }


    private function update_page(WP_Post $existing_page, array $page_data): int|WP_Error {
        $page_data['ID'] = $existing_page->ID;
        return wp_update_post($page_data);
    }


    private function create_page(array $page_data): int|WP_Error {
        return wp_insert_post($page_data);
    }


    private function update_form(WP_Post $existing_form, array $form_data): int|WP_Error {
        $form_data['ID'] = $existing_form->ID;
        return wp_update_post($form_data);
    }


    private function create_form(array $form_data): int|WP_Error {
        return wp_insert_post($form_data);
    }


    private function import_fields_config(array $data, int $form_id): void {
        if (!isset($data['fields_config'])) {
            return;
        }

        delete_post_meta($form_id, '_fields_config');

        global $wpdb;
        $wpdb->insert(
            $wpdb->postmeta,
            [
                'post_id' => $form_id,
                'meta_key' => '_fields_config',
                'meta_value' => sanitize_textarea_field($data['fields_config'])
            ]
        );
    }


    private function import_acf_fields(array $data, int $post_id): void {
        if (!function_exists('update_field') || !isset($data['acf_fields'])) {
            return;
        }

        foreach ($data['acf_fields'] as $field_name => $field_value) {
            update_field(sanitize_text_field($field_name), $field_value, $post_id);
        }
    }


    private function import_meta_data(array $data, int $post_id): void {
        if (!isset($data['meta']) || !is_array($data['meta'])) {
            return;
        }

        foreach ($data['meta'] as $key => $values) {
            $meta_key = sanitize_text_field($key);
            delete_post_meta($post_id, $meta_key);

            if (is_array($values)) {
                foreach ($values as $value) {
                    add_post_meta($post_id, $meta_key, maybe_unserialize($value));
                }
            }
        }
    }


}
