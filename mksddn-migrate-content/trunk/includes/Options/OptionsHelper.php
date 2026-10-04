<?php
/**
 * Options helper.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Options;

use Exception;
use MksDdn\MigrateContent\Services\PluginLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options helper service for ACF Options Pages.
 */
class OptionsHelper {
	/**
	 * Get all Options Pages through ACF.
	 *
	 * @return array<string, array> Pages keyed by menu_slug.
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
				PluginLogger::log(
					'ACF Options Page API error: ' . $e->getMessage(),
					'OptionsHelper'
				);
			}
		}

		// Through acf_get_options_pages (alternative method).
		if ( array() === $options_pages && function_exists( 'acf_get_options_pages' ) ) {
			$acf_pages = acf_get_options_pages();
			if ( is_array( $acf_pages ) && array() !== $acf_pages ) {
				$options_pages = $acf_pages;
			}
		}

		return is_array( $options_pages ) ? $options_pages : array();
	}

	/**
	 * Find an ACF Options Page by menu_slug.
	 *
	 * @param string $menu_slug Options page menu_slug.
	 * @return array|null Page config or null.
	 */
	public function find_options_page_by_slug( string $menu_slug ): ?array {
		$slug = sanitize_key( $menu_slug );
		if ( '' === $slug ) {
			return null;
		}

		$pages = $this->get_all_options_pages();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$page_slug = sanitize_key( (string) ( $page['menu_slug'] ?? '' ) );
			if ( $slug === $page_slug ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Format Options Page data for admin UI listing.
	 *
	 * @param array $page Options Page data.
	 * @return array{menu_slug:string,page_title:string,menu_title:string,post_id:string}
	 */
	public function format_options_page_for_ui( array $page ): array {
		return array(
			'menu_slug'  => (string) ( $page['menu_slug'] ?? '' ),
			'page_title' => (string) ( $page['page_title'] ?? '' ),
			'menu_title' => (string) ( $page['menu_title'] ?? '' ),
			'post_id'    => (string) ( $page['post_id'] ?? '' ),
		);
	}

	/**
	 * Build export payload for an ACF Options Page.
	 *
	 * Uses unformatted ACF values so image/file fields keep attachment IDs
	 * regardless of the field return format (array/url/id).
	 *
	 * `acf_field_schema` records each field type (including repeater, group,
	 * and flexible content children) so media collection and import remap
	 * attachment IDs only on image, file, and gallery fields.
	 *
	 * Only fields from groups whose location matches this page's menu_slug
	 * are exported, so sibling Options Pages that share the same post_id
	 * are not leaked into the archive. An empty `acf_fields` array means no
	 * field group is located on this menu_slug.
	 *
	 * @param array $page Options Page registration data.
	 * @return array
	 */
	public function format_options_page_export( array $page ): array {
		$menu_slug = (string) ( $page['menu_slug'] ?? '' );
		$post_id   = $page['post_id'] ?? 'options';
		$export    = $this->get_acf_fields_for_options_page( $menu_slug, $post_id );

		return array(
			'type'             => 'options_page',
			'menu_slug'        => $menu_slug,
			'page_title'       => (string) ( $page['page_title'] ?? '' ),
			'menu_title'       => (string) ( $page['menu_title'] ?? '' ),
			'post_id'          => (string) ( $page['post_id'] ?? '' ),
			'acf_fields'       => $export['values'],
			'acf_field_schema' => $export['schema'],
		);
	}

	/**
	 * Format Options Page data (legacy shape with `data` key).
	 *
	 * @deprecated 2.8.0 Use format_options_page_export() or format_options_page_for_ui().
	 *
	 * @param array $page Options Page data.
	 * @return array
	 */
	public function format_options_page_data( $page ): array {
		if ( ! is_array( $page ) ) {
			return array();
		}

		$export = $this->format_options_page_export( $page );

		return array(
			'menu_slug'  => $export['menu_slug'],
			'page_title' => $export['page_title'],
			'menu_title' => $export['menu_title'],
			'post_id'    => $export['post_id'],
			'data'       => $export['acf_fields'],
		);
	}

	/**
	 * Whether ACF Options Pages API is available.
	 */
	public function is_acf_options_available(): bool {
		return function_exists( 'get_fields' )
			&& ( function_exists( 'acf_options_page' ) || function_exists( 'acf_get_options_pages' ) );
	}

	/**
	 * Load ACF field values and type schema for one Options Page.
	 *
	 * Does not fall back to get_fields( $post_id ): a shared post_id would
	 * include sibling Options Page values and overwrite them on import.
	 *
	 * @param string     $menu_slug Options page menu_slug.
	 * @param int|string $post_id   ACF post_id for the options page.
	 * @return array{values:array<string,mixed>,schema:array<string,array>}
	 */
	private function get_acf_fields_for_options_page( string $menu_slug, $post_id ): array {
		$empty = array(
			'values' => array(),
			'schema' => array(),
		);

		if ( ! function_exists( 'get_field' ) ) {
			return $empty;
		}

		$definitions = $this->get_field_definitions_for_options_page( $menu_slug );
		if ( array() === $definitions ) {
			return $empty;
		}

		$values = array();
		$schema = array();
		foreach ( $definitions as $field_name => $field ) {
			$values[ $field_name ] = get_field( $field_name, $post_id, false );
			$schema[ $field_name ] = $this->build_field_schema_node( $field );
		}

		return array(
			'values' => $values,
			'schema' => $schema,
		);
	}

	/**
	 * Top-level field definitions from groups located on the given Options Page.
	 *
	 * The first field of a given name wins, matching get_field() resolution.
	 *
	 * @param string $menu_slug Options page menu_slug.
	 * @return array<string, array> Field name => ACF field array.
	 */
	private function get_field_definitions_for_options_page( string $menu_slug ): array {
		$slug = sanitize_key( $menu_slug );
		if ( '' === $slug || ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array();
		}

		$groups = acf_get_field_groups(
			array(
				'options_page' => $slug,
			)
		);

		if ( ! is_array( $groups ) || array() === $groups ) {
			return array();
		}

		$definitions = array();
		foreach ( $groups as $group ) {
			$parent = null;
			if ( is_array( $group ) ) {
				if ( ! empty( $group['key'] ) ) {
					$parent = $group['key'];
				} elseif ( ! empty( $group['ID'] ) ) {
					$parent = (int) $group['ID'];
				}
			}

			if ( null === $parent ) {
				continue;
			}

			$fields = acf_get_fields( $parent );
			if ( ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $field ) {
				if ( ! is_array( $field ) || empty( $field['name'] ) ) {
					continue;
				}
				$name = (string) $field['name'];
				if ( '' === $name || isset( $definitions[ $name ] ) ) {
					continue;
				}
				$definitions[ $name ] = $field;
			}
		}

		return $definitions;
	}

	/**
	 * Compact type tree for one ACF field, including nested sub fields.
	 *
	 * @param array $field ACF field array.
	 * @return array{type:string,sub_fields?:array,layouts?:array}
	 */
	private function build_field_schema_node( array $field ): array {
		$type   = (string) ( $field['type'] ?? '' );
		$schema = array(
			'type' => $type,
		);

		if ( in_array( $type, array( 'group', 'repeater', 'clone' ), true ) ) {
			$schema['sub_fields'] = $this->index_field_schema( $field['sub_fields'] ?? array() );
		}

		if ( 'flexible_content' === $type && isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
			$layouts = array();
			foreach ( $field['layouts'] as $layout ) {
				if ( ! is_array( $layout ) ) {
					continue;
				}
				$layout_name = (string) ( $layout['name'] ?? '' );
				if ( '' === $layout_name || isset( $layouts[ $layout_name ] ) ) {
					continue;
				}
				$layouts[ $layout_name ] = array(
					'sub_fields' => $this->index_field_schema( $layout['sub_fields'] ?? array() ),
				);
			}
			$schema['layouts'] = $layouts;
		}

		return $schema;
	}

	/**
	 * Index nested field schemas by field name.
	 *
	 * @param mixed $fields ACF sub field list.
	 * @return array<string, array>
	 */
	private function index_field_schema( $fields ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$indexed = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) || empty( $field['name'] ) ) {
				continue;
			}
			$name = (string) $field['name'];
			if ( '' === $name || isset( $indexed[ $name ] ) ) {
				continue;
			}
			$indexed[ $name ] = $this->build_field_schema_node( $field );
		}

		return $indexed;
	}
}
