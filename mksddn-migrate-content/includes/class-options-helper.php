<?php

class Options_Helper {


    /**
     * Function to get all Options Pages through ACF.
     * @return mixed[]
     */
    public function get_all_options_pages(): array {
        $options_pages = [];

        // Through ACF Options Page API.
        if (function_exists('acf_options_page')) {
            try {
                $acf_pages = acf_options_page()->get_pages();
                if (is_array($acf_pages) && $acf_pages !== []) {
                    $options_pages = $acf_pages;
                }
            } catch (Exception $e) {
                error_log('ACF Options Page API error: ' . $e->getMessage());
            }
        }

        // Through acf_get_options_pages (alternative method).
        if ($options_pages === [] && function_exists('acf_get_options_pages')) {
            $acf_pages = acf_get_options_pages();
            if (is_array($acf_pages) && $acf_pages !== []) {
                $options_pages = $acf_pages;
            }
        }

        return $options_pages;
    }


    /**
     * Function to format Options Page data.
     *
     * @param array $page Options Page data.
     */
    public function format_options_page_data($page): array {
        if (!is_array($page)) {
            return [];
        }

        return [
            'menu_slug'  => $page['menu_slug'] ?? '',
            'page_title' => $page['page_title'] ?? '',
            'menu_title' => $page['menu_title'] ?? '',
            'post_id'    => $page['post_id'] ?? '',
            'data'       => get_fields($page['post_id'] ?? '') ?: [],
        ];
    }


}
