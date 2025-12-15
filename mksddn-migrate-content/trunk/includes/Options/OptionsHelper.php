<?php
/**
 * Options helper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Options;

use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options helper service.
 */
class OptionsHelper {
	/**
	 * Function to get all Options Pages through ACF.
	 *
	 * @return mixed[]
	 */
	public function get_all_options_pages(): array {
		$options_pages = array();

		// Through ACF Options Page API.
		if ( function_exists( 'acf_options_page' ) ) {
			try {
				$acf_pages = acf_options_page()->get_pages();
				if ( is_array( $acf_pages ) && array() !== $acf_pages ) {
					$options_pages = $acf_pages;
				}
			} catch ( Exception $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Log is acceptable for admin-only context troubleshooting.
				error_log( 'ACF Options Page API error: ' . $e->getMessage() );
			}
		}

		// Through acf_get_options_pages (alternative method).
		if ( array() === $options_pages && function_exists( 'acf_get_options_pages' ) ) {
			$acf_pages = acf_get_options_pages();
			if ( is_array( $acf_pages ) && array() !== $acf_pages ) {
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
	public function format_options_page_data( $page ): array {
		if ( ! is_array( $page ) ) {
			return array();
		}

		$fields = array();
		if ( function_exists( 'get_fields' ) ) {
			$fields_raw = get_fields( $page['post_id'] ?? '' );
			$fields     = is_array( $fields_raw ) ? $fields_raw : array();
		}

		return array(
			'menu_slug'  => $page['menu_slug'] ?? '',
			'page_title' => $page['page_title'] ?? '',
			'menu_title' => $page['menu_title'] ?? '',
			'post_id'    => $page['post_id'] ?? '',
			'data'       => $fields,
		);
	}
}
