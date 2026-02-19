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

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! $this->import_single_page( $item ) ) {
				return false;
			}
		}

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
	 * @param array $data Data array containing page information.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_single_page( array $data ): bool {
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

		// Assign taxonomies for all post types (including Polylang language taxonomy).
		$this->assign_taxonomies( $post_id, $data );

		$media_maps  = $this->restore_media( $data, $post_id );
		$id_map      = $media_maps['id_map'] ?? array();
		$url_map     = $media_maps['url_map'] ?? array();
		$url_to_new_id = $this->build_url_to_new_id_map( $data, $id_map );

		$this->import_acf_fields( $data, $post_id, $id_map, $url_map, $url_to_new_id );

		return true;
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

		foreach ( $data['acf_fields'] as $field_name => $field_value ) {
			$value = $this->remap_media_values( $field_value, $id_map, $url_map, $url_to_new_id );
			update_field( sanitize_text_field( $field_name ), $value, $post_id );
		}
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
