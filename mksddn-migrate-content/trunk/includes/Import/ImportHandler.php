<?php
/**
 * Import handler.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Import;

use MksDdn\MigrateContent\Contracts\ImporterInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapperInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapperInterface;
use MksDdn\MigrateContent\Media\AttachmentRestorer;
use MksDdn\MigrateContent\Options\OptionsImporter;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing pages, options pages, and forms.
 *
 * @since 1.0.0
 */
class ImportHandler implements ImporterInterface {

	/**
	 * Attachment restorer.
	 */
	private AttachmentRestorer $media_restorer;

	private OptionsImporter $options_importer;

	/**
	 * WordPress functions wrapper.
	 *
	 * @var WpFunctionsWrapperInterface
	 */
	private WpFunctionsWrapperInterface $wp_functions;

	/**
	 * WordPress user functions wrapper.
	 *
	 * @var WpUserFunctionsWrapperInterface
	 */
	private WpUserFunctionsWrapperInterface $wp_user_functions;

	/**
	 * Loader callback for media files (archives only).
	 *
	 * @var callable|null
	 */
	private $media_file_loader = null;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRestorer|null            $media_restorer   Optional media restorer.
	 * @param OptionsImporter|null               $options_importer Optional options importer.
	 * @param WpFunctionsWrapperInterface|null   $wp_functions     Optional WordPress functions wrapper.
	 * @param WpUserFunctionsWrapperInterface|null $wp_user_functions Optional WordPress user functions wrapper.
	 * @since 1.0.0
	 */
	public function __construct(
		?AttachmentRestorer $media_restorer = null,
		?OptionsImporter $options_importer = null,
		?WpFunctionsWrapperInterface $wp_functions = null,
		?WpUserFunctionsWrapperInterface $wp_user_functions = null
	) {
		$this->media_restorer   = $media_restorer ?? new AttachmentRestorer();
		$this->options_importer = $options_importer ?? new OptionsImporter();
		$this->wp_functions      = $wp_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapper();
		$this->wp_user_functions = $wp_user_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapper();
	}

	/**
	 * Set loader used to fetch files from archive.
	 *
	 * @param callable|null $loader Loader callback.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_media_file_loader( ?callable $loader ): void {
		$this->media_file_loader = $loader;
	}

	/**
	 * Import bundle containing multiple posts/options.
	 *
	 * @param array $data Bundle payload.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_bundle( array $data ): bool {
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		// Sort items to import parent pages before child pages.
		$items = $this->sort_items_by_parent( $items );

		$post_id_map = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! $this->import_single_page( $item, $post_id_map ) ) {
				return false;
			}
		}

		$this->sync_polylang_translations_from_export( $items, $post_id_map );

		if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
			$this->import_bundle_options( $data['options'] );
		}

		return true;
	}

	/**
	 * Sort items to ensure parent pages are imported before child pages.
	 *
	 * @param array $items Array of items to sort.
	 * @return array Sorted items array.
	 */
	private function sort_items_by_parent( array $items ): array {
		// Build map: slug => item for quick lookup.
		$items_by_slug = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) ) {
				$items_by_slug[ $item['slug'] ] = $item;
			}
		}

		// Build dependency graph: child_slug => parent_slug.
		$dependencies = array();
		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['slug'], $item['parent_slug'] ) && ! empty( $item['parent_slug'] ) ) {
				$dependencies[ $item['slug'] ] = $item['parent_slug'];
			}
		}

		// Topological sort: items without parents first, then children.
		$sorted = array();
		$visited = array();

		// First pass: add items without parents.
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['slug'] ) ) {
				continue;
			}

			$slug = $item['slug'];
			if ( ! isset( $dependencies[ $slug ] ) || ! isset( $items_by_slug[ $dependencies[ $slug ] ] ) ) {
				$sorted[] = $item;
				$visited[ $slug ] = true;
			}
		}

		// Second pass: add children after their parents.
		$remaining = array_filter(
			$items,
			function( $item ) use ( $visited ) {
				return is_array( $item ) && isset( $item['slug'] ) && ! isset( $visited[ $item['slug'] ] );
			}
		);

		$max_iterations = count( $remaining ) * 2; // Prevent infinite loops.
		$iteration = 0;

		while ( ! empty( $remaining ) && $iteration < $max_iterations ) {
			$iteration++;
			$added = false;

			foreach ( $remaining as $key => $item ) {
				if ( ! is_array( $item ) || ! isset( $item['slug'] ) ) {
					unset( $remaining[ $key ] );
					continue;
				}

				$slug = $item['slug'];
				$parent_slug = $dependencies[ $slug ] ?? null;

				// If parent is already imported or doesn't exist in bundle, add this item.
				if ( ! $parent_slug || isset( $visited[ $parent_slug ] ) || ! isset( $items_by_slug[ $parent_slug ] ) ) {
					$sorted[] = $item;
					$visited[ $slug ] = true;
					unset( $remaining[ $key ] );
					$added = true;
				}
			}

			// If no items were added in this iteration, break to avoid infinite loop.
			if ( ! $added ) {
				break;
			}
		}

		// Add any remaining items (shouldn't happen in normal cases).
		foreach ( $remaining as $item ) {
			$sorted[] = $item;
		}

		return $sorted;
	}
	/**
	 * Imports a single page with ACF fields.
	 *
	 * @param array      $data           Data array containing page information.
	 * @param array|null $post_id_map    Optional. When provided, maps source post ID (payload `ID`) to imported post ID for bundle remapping (e.g. Polylang).
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_single_page( array $data, ?array &$post_id_map = null ): bool {
		if ( ! $this->validate_page_data( $data ) ) {
			return false;
		}

		$post_type = in_array( $data['type'] ?? 'page', array( 'post', 'page' ), true ) ? $data['type'] : 'page';
		$existing  = $this->wp_functions->get_page_by_path( $data['slug'], 'OBJECT', $post_type );
		$post_data = $this->prepare_post_data( $data, $post_type );

		$post_id = $existing ? $this->update_post( $existing, $post_data ) : $this->create_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		// Ensure Polylang language context is set before ACF updates.
		$this->set_polylang_language_from_payload( (int) $post_id, $data );

		// Assign taxonomies for all post types (including Polylang language taxonomy).
		$this->assign_taxonomies( $post_id, $data );

		$media_maps      = $this->restore_media( $data, $post_id );
		$media_id_map    = $media_maps['id_map'] ?? array();
		$url_map         = $media_maps['url_map'] ?? array();
		$url_to_new_id   = $this->build_url_to_new_id_map( $data, $media_id_map );

		$this->import_acf_fields( $data, $post_id, $media_id_map, $url_map, $url_to_new_id );

		if ( null !== $post_id_map && isset( $data['ID'] ) ) {
			$post_id_map[ (int) $data['ID'] ] = (int) $post_id;
		}

		return true;
	}

	/**
	 * Remap Polylang translation groups after bundle import using exported `_pll_translations`
	 * or `taxonomies.post_translations` term descriptions (Polylang 3.x), remapped to new post IDs.
	 *
	 * @param array $items      Bundle items (same order as import: parent-sorted within `import_bundle`).
	 * @param array $old_to_new Map of source post ID => imported post ID.
	 * @return void
	 */
	private function sync_polylang_translations_from_export( array $items, array $old_to_new ): void {
		if ( ! function_exists( 'pll_save_post_translations' ) || empty( $old_to_new ) ) {
			return;
		}

		$seen = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$translations_old = $this->get_exported_polylang_old_id_map( $item );
			if ( empty( $translations_old ) ) {
				continue;
			}

			$new_map = $this->remap_polylang_old_map_to_new_ids( $translations_old, $old_to_new );

			// Polylang needs at least two posts in the group to link translations.
			if ( count( $new_map ) < 2 ) {
				continue;
			}

			ksort( $new_map );
			$sig = wp_json_encode( $new_map );
			if ( isset( $seen[ $sig ] ) ) {
				continue;
			}
			$seen[ $sig ] = true;

			pll_save_post_translations( $new_map );
		}
	}

	/**
	 * Build lang => source post ID map from meta._pll_translations or post_translations taxonomy terms.
	 *
	 * @param array $item Bundle item.
	 * @return array<string,int> Language slug => old post ID.
	 */
	private function get_exported_polylang_old_id_map( array $item ): array {
		if ( isset( $item['meta'] ) && is_array( $item['meta'] ) && array_key_exists( '_pll_translations', $item['meta'] ) ) {
			$raw = $item['meta']['_pll_translations'];
			if ( '' !== $raw && null !== $raw ) {
				$translations = is_array( $raw ) ? $raw : maybe_unserialize( $raw );
				if ( is_array( $translations ) && ! empty( $translations ) ) {
					return $this->normalize_polylang_lang_to_id_map( $translations );
				}
			}
		}

		if ( empty( $item['taxonomies']['post_translations'] ) || ! is_array( $item['taxonomies']['post_translations'] ) ) {
			return array();
		}

		$merged = array();
		foreach ( $item['taxonomies']['post_translations'] as $term_data ) {
			if ( ! is_array( $term_data ) || ! array_key_exists( 'description', $term_data ) ) {
				continue;
			}
			$desc = $term_data['description'];
			$parsed = is_array( $desc ) ? $desc : maybe_unserialize( (string) $desc );
			if ( ! is_array( $parsed ) ) {
				continue;
			}
			$merged = array_merge( $merged, $this->normalize_polylang_lang_to_id_map( $parsed ) );
		}

		return $merged;
	}

	/**
	 * Normalize Polylang translation map keys/values to lang string => positive int post ID.
	 *
	 * @param array $raw Raw map from meta or term description.
	 * @return array<string,int>
	 */
	private function normalize_polylang_lang_to_id_map( array $raw ): array {
		$out = array();
		foreach ( $raw as $lang => $old_id ) {
			$old_id = (int) $old_id;
			if ( $old_id <= 0 ) {
				continue;
			}
			$lang_key            = is_string( $lang ) ? $lang : (string) $lang;
			$out[ $lang_key ] = $old_id;
		}
		return $out;
	}

	/**
	 * Remap source post IDs to imported IDs for pll_save_post_translations().
	 *
	 * @param array<string,int> $translations_old Language => source post ID.
	 * @param array<int,int>    $old_to_new       Source post ID => imported post ID.
	 * @return array<string,int>
	 */
	private function remap_polylang_old_map_to_new_ids( array $translations_old, array $old_to_new ): array {
		$new_map = array();
		foreach ( $translations_old as $lang => $old_id ) {
			$old_id = (int) $old_id;
			if ( $old_id > 0 && isset( $old_to_new[ $old_id ] ) ) {
				$lang_key              = is_string( $lang ) ? $lang : (string) $lang;
				$new_map[ $lang_key ] = (int) $old_to_new[ $old_id ];
			}
		}
		return $new_map;
	}

	/**
	 * Set Polylang post language from exported taxonomy payload.
	 *
	 * @param int   $post_id Target post ID.
	 * @param array $data    Bundle item payload.
	 * @return void
	 */
	private function set_polylang_language_from_payload( int $post_id, array $data ): void {
		if ( $post_id <= 0 || ! function_exists( 'pll_set_post_language' ) ) {
			return;
		}

		$language_terms = $data['taxonomies']['language'] ?? array();
		if ( ! is_array( $language_terms ) ) {
			return;
		}

		foreach ( $language_terms as $term_data ) {
			if ( ! is_array( $term_data ) ) {
				continue;
			}

			$lang_slug = isset( $term_data['slug'] ) ? sanitize_key( (string) $term_data['slug'] ) : '';
			if ( '' === $lang_slug ) {
				continue;
			}

			pll_set_post_language( $post_id, $lang_slug );
			return;
		}
	}

	/**
	 * Restore media attachments for the entity.
	 *
	 * @param array $data    Payload.
	 * @param int   $post_id Target post ID.
	 */
	private function restore_media( array $data, int $post_id ): array {
		$entries = $data['_mksddn_media'] ?? array();

		if ( empty( $entries ) || ! is_callable( $this->media_file_loader ) ) {
			return array(
				'id_map'  => array(),
				'url_map' => array(),
			);
		}

		if ( isset( $data['featured_media'] ) ) {
			$this->wp_functions->update_post_meta( $post_id, '_mksddn_original_thumbnail', (int) $data['featured_media'] );
		}

		return $this->media_restorer->restore(
			$entries,
			$this->media_file_loader,
			$post_id
		);
	}

	/**
	 * Import option/widget bundle.
	 *
	 * @param array $data Payload.
	 */
	/**
	 * Validate page data.
	 *
	 * @param array $data Page payload.
	 * @return bool
	 */
	private function validate_page_data( array $data ): bool {
		return isset( $data['title'], $data['content'], $data['slug'] );
	}

	/**
	 * Validate options page data.
	 *
	 * @param array $data Options page payload.
	 * @return bool
	 */
	private function validate_options_page_data( array $data ): bool {
		return isset( $data['menu_slug'], $data['acf_fields'] );
	}

	/**
	 * Prepare page data for insert/update.
	 *
	 * @param array $data Page payload.
	 * @return array
	 */
	private function prepare_post_data( array $data, string $post_type ): array {
		$post_data = array(
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_excerpt' => sanitize_text_field( $data['excerpt'] ?? '' ),
			'post_name'    => sanitize_title( $data['slug'] ),
			'post_type'    => $post_type,
			'post_status'  => sanitize_key( $data['status'] ?? 'publish' ),
			'post_author'  => absint( $data['author'] ?? $this->wp_user_functions->get_current_user_id() ),
			'post_date_gmt'=> isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : current_time( 'mysql', true ),
		);

		// Set local date if provided.
		if ( isset( $data['date_local'] ) && ! empty( $data['date_local'] ) ) {
			$post_data['post_date'] = sanitize_text_field( $data['date_local'] );
		}

		// Set modified dates if provided.
		if ( isset( $data['modified'] ) && ! empty( $data['modified'] ) ) {
			$post_data['post_modified_gmt'] = sanitize_text_field( $data['modified'] );
		}
		if ( isset( $data['modified_local'] ) && ! empty( $data['modified_local'] ) ) {
			$post_data['post_modified'] = sanitize_text_field( $data['modified_local'] );
		}

		// Set menu order (important for page hierarchy).
		if ( isset( $data['menu_order'] ) ) {
			$post_data['menu_order'] = absint( $data['menu_order'] );
		}

		// Set comment and ping status.
		if ( isset( $data['comment_status'] ) ) {
			$post_data['comment_status'] = sanitize_key( $data['comment_status'] );
		}
		if ( isset( $data['ping_status'] ) ) {
			$post_data['ping_status'] = sanitize_key( $data['ping_status'] );
		}

		// Set parent page if parent_slug is provided.
		if ( isset( $data['parent_slug'] ) && ! empty( $data['parent_slug'] ) ) {
			$parent = $this->wp_functions->get_page_by_path( sanitize_title( $data['parent_slug'] ), 'OBJECT', $post_type );
			if ( $parent ) {
				$post_data['post_parent'] = $parent->ID;
			}
		}

		return $post_data;
	}

	/**
	 * Update an existing post.
	 *
	 * @param WP_Post $existing Existing post.
	 * @param array   $post_data     Data to update.
	 * @return int|WP_Error
	 */
	private function update_post( WP_Post $existing, array $post_data ): int|WP_Error {
		$post_data['ID'] = $existing->ID;
		return $this->wp_functions->update_post( $post_data );
	}

	/**
	 * Create a post/page.
	 *
	 * @param array $post_data Data to insert.
	 * @return int|WP_Error
	 */
	private function create_post( array $post_data ): int|WP_Error {
		return $this->wp_functions->insert_post( $post_data );
	}

	/**
	 * Assign taxonomy terms for posts.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Payload.
	 */
	private function assign_taxonomies( int $post_id, array $data ): void {
		if ( empty( $data['taxonomies'] ) || ! is_array( $data['taxonomies'] ) ) {
			return;
		}

		foreach ( $data['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
				continue;
			}

			// Polylang manages translation groups via pll_save_post_translations(); assigning
			// exported post_translations terms here would attach stale source IDs and break sync.
			if ( 'post_translations' === $taxonomy ) {
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $term_data ) {
				$term = wp_insert_term(
					$term_data['name'] ?? '',
					$taxonomy,
					array(
						'slug'        => $term_data['slug'] ?? '',
						'description' => $term_data['description'] ?? '',
					)
				);

				if ( is_wp_error( $term ) ) {
					$existing = get_term_by( 'slug', $term_data['slug'] ?? '', $taxonomy );
					if ( $existing ) {
						$term_ids[] = (int) $existing->term_id;
					}
				} else {
					$term_ids[] = (int) $term['term_id'];
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Import a fields configuration meta for a form.
	 *
	 * @param array $data    Payload containing 'fields_config'.
	 * @param int   $form_id Form ID.
	 * @return void
	 */
	private function import_fields_config( array $data, int $form_id ): void {
		if ( ! isset( $data['fields_config'] ) ) {
			return;
		}

		delete_post_meta( $form_id, '_fields_config' );

		$sanitized = sanitize_textarea_field( $data['fields_config'] );
		add_post_meta( $form_id, '_fields_config', $sanitized );
	}

	/**
	 * Build map: original media URL => new attachment ID (for ACF image fields).
	 *
	 * @param array $data   Payload with _mksddn_media.
	 * @param array $id_map Original ID => new ID.
	 * @return array<string,int>
	 */
	private function build_url_to_new_id_map( array $data, array $id_map ): array {
		$entries = $data['_mksddn_media'] ?? array();
		$result  = array();
		foreach ( $entries as $entry ) {
			$old_url = isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '';
			$old_id  = isset( $entry['original_id'] ) ? (int) $entry['original_id'] : 0;
			if ( '' !== $old_url && $old_id > 0 && isset( $id_map[ $old_id ] ) ) {
				$result[ $old_url ] = $id_map[ $old_id ];
			}
		}
		return $result;
	}

	/**
	 * Import ACF fields for a post.
	 *
	 * @param array      $data           Payload containing 'acf_fields'.
	 * @param int|string $post_id        Target post ID.
	 * @param array      $id_map         Media ID mapping.
	 * @param array      $url_map        Media URL mapping.
	 * @param array      $url_to_new_id  Original URL => new attachment ID (for image fields).
	 * @return void
	 */
	private function import_acf_fields( array $data, int|string $post_id, array $id_map = array(), array $url_map = array(), array $url_to_new_id = array() ): void {
		if ( ! function_exists( 'update_field' ) || ! isset( $data['acf_fields'] ) || ! is_array( $data['acf_fields'] ) ) {
			return;
		}

		$post_id_int = (int) $post_id;
		$top_level_field_names = array();
		foreach ( array_keys( $data['acf_fields'] ) as $acf_field_name ) {
			$top_level_field_names[] = sanitize_text_field( (string) $acf_field_name );
		}

		foreach ( $data['acf_fields'] as $field_name => $field_value ) {
			$name          = sanitize_text_field( (string) $field_name );
			$value         = $this->remap_media_values( $field_value, $id_map, $url_map, $url_to_new_id );
			$field_selector = $this->acf_resolve_field_selector( $name, $post_id_int );
			update_field( $field_selector, $value, $post_id_int );
			$this->acf_cleanup_leaked_group_subfield_meta( $name, $post_id_int, $top_level_field_names );

			if ( $this->acf_is_value_missing_after_import( $name, $value, $post_id_int ) ) {
				$this->acf_import_meta_fallback_from_payload( $data['meta'] ?? array(), $name, $post_id_int, $id_map, $url_map, $url_to_new_id );
			}
		}
	}

	/**
	 * Resolve ACF field selector (prefer field key when duplicate names or Polylang/SCF contexts).
	 *
	 * @param string $field_name Sanitized field name from payload.
	 * @param int    $post_id    Target post ID.
	 * @return string Field key or name for update_field().
	 */
	private function acf_resolve_field_selector( string $field_name, int $post_id ): string {
		if ( ! function_exists( 'get_field_object' ) || $post_id <= 0 ) {
			return $field_name;
		}

		$object = get_field_object( $field_name, $post_id, false, false );
		if ( is_array( $object ) && ! empty( $object['key'] ) && is_string( $object['key'] ) ) {
			return $object['key'];
		}

		return $field_name;
	}

	/**
	 * Remove leaked flat meta keys for group subfields from previous imports.
	 *
	 * @param string $field_name             Group field name.
	 * @param int    $post_id                Target post ID.
	 * @param array  $top_level_field_names  Root ACF field names from payload.
	 * @return void
	 */
	private function acf_cleanup_leaked_group_subfield_meta( string $field_name, int $post_id, array $top_level_field_names ): void {
		if ( $post_id <= 0 || ! function_exists( 'get_field_object' ) ) {
			return;
		}

		$object = get_field_object( $field_name, $post_id, false, false );
		if ( ! is_array( $object ) || ( $object['type'] ?? '' ) !== 'group' || empty( $object['sub_fields'] ) || ! is_array( $object['sub_fields'] ) ) {
			return;
		}

		foreach ( $object['sub_fields'] as $sub_field ) {
			if ( ! is_array( $sub_field ) || empty( $sub_field['name'] ) || ! is_string( $sub_field['name'] ) ) {
				continue;
			}

			$sub_name = sanitize_text_field( $sub_field['name'] );
			if ( '' === $sub_name || in_array( $sub_name, $top_level_field_names, true ) ) {
				continue;
			}

			delete_post_meta( $post_id, $sub_name );
			delete_post_meta( $post_id, '_' . $sub_name );
		}
	}

	/**
	 * Detect when ACF still returns an empty value after import.
	 *
	 * @param string $field_name Field name from payload.
	 * @param mixed  $expected   Imported payload value.
	 * @param int    $post_id    Target post ID.
	 * @return bool
	 */
	private function acf_is_value_missing_after_import( string $field_name, mixed $expected, int $post_id ): bool {
		if ( $post_id <= 0 || ! function_exists( 'get_field' ) ) {
			return false;
		}

		if ( ! $this->acf_has_meaningful_value( $expected ) ) {
			return false;
		}

		$stored = get_field( $field_name, $post_id, false );
		return ! $this->acf_has_meaningful_value( $stored );
	}

	/**
	 * Import ACF meta keys for a single field directly from payload meta.
	 *
	 * Used as a fallback when update_field() succeeds for some groups but one group remains empty.
	 *
	 * @param array  $meta          Full payload meta array.
	 * @param string $field_name    Target field/group name.
	 * @param int    $post_id       Target post ID.
	 * @param array  $id_map        Original attachment ID => new ID.
	 * @param array  $url_map       Original URL => new URL.
	 * @param array  $url_to_new_id Original URL => new attachment ID.
	 * @return void
	 */
	private function acf_import_meta_fallback_from_payload( array $meta, string $field_name, int $post_id, array $id_map, array $url_map, array $url_to_new_id ): void {
		if ( $post_id <= 0 || empty( $meta ) ) {
			return;
		}

		$prefix = $field_name . '_';

		foreach ( $meta as $raw_key => $values ) {
			$meta_key = sanitize_text_field( (string) $raw_key );

			$is_target_key = (
				$field_name === $meta_key
				|| '_' . $field_name === $meta_key
				|| str_starts_with( $meta_key, $prefix )
				|| str_starts_with( $meta_key, '_' . $prefix )
			);

			if ( ! $is_target_key ) {
				continue;
			}

			delete_post_meta( $post_id, $meta_key );

			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					$value = $this->remap_media_values( $value, $id_map, $url_map, $url_to_new_id );
					add_post_meta( $post_id, $meta_key, $value );
				}
				continue;
			}

			$value = maybe_unserialize( $values );
			$value = $this->remap_media_values( $value, $id_map, $url_map, $url_to_new_id );
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	/**
	 * Check whether a value is non-empty for import verification.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private function acf_has_meaningful_value( mixed $value ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->acf_has_meaningful_value( $item ) ) {
					return true;
				}
			}
			return false;
		}

		if ( is_string( $value ) ) {
			return '' !== trim( $value );
		}

		return null !== $value && false !== $value && '' !== $value;
	}

	/**
	 * Import meta for a post.
	 *
	 * @param array $data    Payload containing 'meta'.
	 * @param int   $post_id Target post ID.
	 * @return void
	 */
	private function import_meta_data( array $data, int $post_id, array $id_map = array(), array $url_map = array(), array $url_to_new_id = array() ): void {
		if ( ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return;
		}

		foreach ( $data['meta'] as $key => $values ) {
			$meta_key = sanitize_text_field( $key );
			delete_post_meta( $post_id, $meta_key );

			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					$value = $this->remap_media_values( $value, $id_map, $url_map, $url_to_new_id );
					add_post_meta( $post_id, $meta_key, $value );
				}
			} else {
				$value = maybe_unserialize( $values );
				$value = $this->remap_media_values( $value, $id_map, $url_map, $url_to_new_id );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Remap media IDs and URLs in values (ACF/meta). Prefers new attachment ID for URLs so ACF image fields get ID.
	 *
	 * @param mixed $value         Value to remap.
	 * @param array $id_map        Original attachment ID => new ID.
	 * @param array $url_map       Original URL => new URL.
	 * @param array $url_to_new_id Original URL => new attachment ID (optional).
	 * @return mixed
	 */
	private function remap_media_values( mixed $value, array $id_map, array $url_map, array $url_to_new_id = array() ): mixed {
		if ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) ) {
				$old_id = (int) $value['ID'];
				if ( isset( $id_map[ $old_id ] ) ) {
					$new_id         = $id_map[ $old_id ];
					$value['ID']    = $new_id;
					$value['id']    = $new_id;
					$value['url']   = \wp_get_attachment_url( $new_id );
					$value['link']  = \get_permalink( $new_id );
					$value['sizes'] = $this->remap_media_values( $value['sizes'] ?? array(), $id_map, $url_map, $url_to_new_id );
				}
			}

			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->remap_media_values( $child, $id_map, $url_map, $url_to_new_id );
			}

			return $value;
		}

		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			if ( isset( $id_map[ $int ] ) ) {
				return $id_map[ $int ];
			}
		}

		if ( is_string( $value ) ) {
			if ( isset( $url_to_new_id[ $value ] ) ) {
				return $url_to_new_id[ $value ];
			}
			if ( isset( $url_map[ $value ] ) ) {
				return $url_map[ $value ];
			}
		}

		return $value;
	}

	/**
	 * Import options/widgets portion of a bundle.
	 *
	 * @param array $options_bundle Bundle options structure.
	 * @return void
	 */
	private function import_bundle_options( array $options_bundle ): void {
		if ( isset( $options_bundle['options'] ) && is_array( $options_bundle['options'] ) ) {
			$this->options_importer->import_options( $options_bundle['options'] );
		}

		if ( isset( $options_bundle['widgets'] ) && is_array( $options_bundle['widgets'] ) ) {
			$this->options_importer->import_widgets( $options_bundle['widgets'] );
		}
	}

}
