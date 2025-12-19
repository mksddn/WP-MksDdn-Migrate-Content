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

		if ( 'post' === $post_type ) {
			$this->assign_taxonomies( $post_id, $data );
		}

		$media_maps = $this->restore_media( $data, $post_id );
		$id_map     = $media_maps['id_map'] ?? array();
		$url_map    = $media_maps['url_map'] ?? array();

		$this->import_acf_fields( $data, $post_id, $id_map, $url_map );

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
		return array(
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_excerpt' => sanitize_text_field( $data['excerpt'] ?? '' ),
			'post_name'    => sanitize_title( $data['slug'] ),
			'post_type'    => $post_type,
			'post_status'  => sanitize_key( $data['status'] ?? 'publish' ),
			'post_author'  => absint( $data['author'] ?? $this->wp_user_functions->get_current_user_id() ),
			'post_date_gmt'=> isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : current_time( 'mysql', true ),
		);
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
	 * Import ACF fields for a post.
	 *
	 * @param array      $data    Payload containing 'acf_fields'.
	 * @param int|string $post_id Target post ID.
	 * @param array      $id_map  Media ID mapping.
	 * @param array      $url_map Media URL mapping.
	 * @return void
	 */
	private function import_acf_fields( array $data, int|string $post_id, array $id_map = array(), array $url_map = array() ): void {
		if ( ! function_exists( 'update_field' ) || ! isset( $data['acf_fields'] ) || ! is_array( $data['acf_fields'] ) ) {
			return;
		}

			foreach ( $data['acf_fields'] as $field_name => $field_value ) {
				$value = $this->remap_media_values( $field_value, $id_map, $url_map );
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
	private function import_meta_data( array $data, int $post_id, array $id_map = array(), array $url_map = array() ): void {
		if ( ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return;
		}

		foreach ( $data['meta'] as $key => $values ) {
			$meta_key = sanitize_text_field( $key );
			delete_post_meta( $post_id, $meta_key );

			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					$value = $this->remap_media_values( $value, $id_map, $url_map );
					add_post_meta( $post_id, $meta_key, $value );
				}
			} else {
				// Single value.
				$value = maybe_unserialize( $values );
				$value = $this->remap_media_values( $value, $id_map, $url_map );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}


	private function remap_media_values( mixed $value, array $id_map, array $url_map ): mixed {
		if ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) ) {
				$old_id = (int) $value['ID'];
				if ( isset( $id_map[ $old_id ] ) ) {
					$new_id         = $id_map[ $old_id ];
					$value['ID']    = $new_id;
					$value['id']    = $new_id;
					$value['url']   = \wp_get_attachment_url( $new_id );
					$value['link']  = \get_permalink( $new_id );
					$value['sizes'] = $this->remap_media_values( $value['sizes'] ?? array(), $id_map, $url_map );
				}
			}

			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->remap_media_values( $child, $id_map, $url_map );
			}

			return $value;
		}

		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			if ( isset( $id_map[ $int ] ) ) {
				return $id_map[ $int ];
			}
		}

		if ( is_string( $value ) && isset( $url_map[ $value ] ) ) {
			return $url_map[ $value ];
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
