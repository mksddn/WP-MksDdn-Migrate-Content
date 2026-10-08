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
use MksDdn\MigrateContent\Options\OptionsHelper;
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

	private OptionsHelper $options_helper;

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
	 * Post IDs imported or updated in the current request (for selected import cache flush).
	 *
	 * @var int[]
	 */
	private array $imported_post_ids = array();

	/**
	 * Last import failure message for admin UI.
	 *
	 * @var string
	 */
	private string $last_error = '';

	/**
	 * Whether the current Options Page import already wrote media or field values.
	 *
	 * @var bool
	 */
	private bool $options_page_mutation_started = false;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRestorer|null              $media_restorer     Optional media restorer.
	 * @param OptionsImporter|null                 $options_importer   Optional options importer.
	 * @param WpFunctionsWrapperInterface|null     $wp_functions       Optional WordPress functions wrapper.
	 * @param WpUserFunctionsWrapperInterface|null $wp_user_functions  Optional WordPress user functions wrapper.
	 * @param OptionsHelper|null                   $options_helper     Optional ACF options helper.
	 * @since 1.0.0
	 */
	public function __construct(
		?AttachmentRestorer $media_restorer = null,
		?OptionsImporter $options_importer = null,
		?WpFunctionsWrapperInterface $wp_functions = null,
		?WpUserFunctionsWrapperInterface $wp_user_functions = null,
		?OptionsHelper $options_helper = null
	) {
		$this->media_restorer    = $media_restorer ?? new AttachmentRestorer();
		$this->options_importer  = $options_importer ?? new OptionsImporter();
		$this->wp_functions      = $wp_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapper();
		$this->wp_user_functions = $wp_user_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapper();
		$this->options_helper    = $options_helper ?? new OptionsHelper();
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
	 * Last failure message from import_bundle / selected import.
	 *
	 * @since 2.8.0
	 */
	public function get_last_error(): string {
		return $this->last_error;
	}

	/**
	 * Import bundle containing multiple posts/options.
	 *
	 * @param array $data Bundle payload.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_bundle( array $data ): bool {
		$this->imported_post_ids = array();
		$this->last_error        = '';

		$options_pages = isset( $data['options_pages'] ) && is_array( $data['options_pages'] )
			? $data['options_pages']
			: array();

		// Fail before any writes when Options Pages cannot be applied.
		if ( array() !== $options_pages && false === $this->validate_options_pages_for_import( $options_pages ) ) {
			return false;
		}

		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		// Sort items to import parent pages before child pages.
		$items = $this->sort_items_by_parent( $items );

		$post_id_map       = array();
		$content_imported  = false;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( false === $this->import_single_page( $item, $post_id_map ) ) {
				if ( '' === $this->last_error ) {
					$this->last_error = __( 'Failed to import one or more content items.', 'mksddn-migrate-content' );
				}
				return false;
			}
			$content_imported = true;
		}

		$this->sync_polylang_translations_from_export( $items, $post_id_map );

		// Apply Options Pages after content so a content failure does not leave
		// site settings half-overwritten. Validate-above still blocks missing pages.
		if ( array() !== $options_pages ) {
			if ( false === $this->import_options_pages( $options_pages ) ) {
				if ( $content_imported ) {
					$this->last_error = trim(
						$this->last_error . ' ' . __( 'Some content items from this archive may already have been imported.', 'mksddn-migrate-content' )
					);
				}
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
	 * Imports a single post-like entity with ACF fields.
	 *
	 * @param array      $data           Data array containing post information.
	 * @param array|null $post_id_map    Optional. When provided, maps source post ID (payload `ID`) to imported post ID for bundle remapping (e.g. Polylang).
	 * @return int|false Post ID on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_single_page( array $data, ?array &$post_id_map = null ) {
		$this->last_error = '';

		if ( ! $this->validate_page_data( $data ) ) {
			$this->last_error = __( 'Invalid content payload in archive.', 'mksddn-migrate-content' );
			return false;
		}

		$post_type = $this->resolve_import_post_type( $data );
		if ( false === $post_type ) {
			$raw_type         = is_scalar( $data['type'] ?? $data['post_type'] ?? '' ) ? (string) ( $data['type'] ?? $data['post_type'] ?? '' ) : '';
			$this->last_error = sprintf(
				/* translators: %s: post type slug */
				__( 'Post type "%s" is missing or not allowed on this site.', 'mksddn-migrate-content' ),
				sanitize_key( $raw_type )
			);
			return false;
		}

		$existing  = $this->wp_functions->get_page_by_path( $data['slug'], 'OBJECT', $post_type );
		$post_data = $this->prepare_post_data( $data, $post_type );

		$post_id = $existing ? $this->update_post( $existing, $post_data ) : $this->create_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			$this->last_error = $post_id->get_error_message();
			return false;
		}

		$post_id = (int) $post_id;

		// Ensure Polylang language context is set before ACF updates.
		$this->set_polylang_language_from_payload( $post_id, $data );

		// Assign taxonomies for all post types (including Polylang language taxonomy).
		$this->assign_taxonomies( $post_id, $data );

		$media_maps      = $this->restore_media( $data, $post_id );
		$media_id_map    = $media_maps['id_map'] ?? array();
		$url_map         = $media_maps['url_map'] ?? array();
		$url_to_new_id   = $this->build_url_to_new_id_map( $data, $media_id_map );

		$this->import_acf_fields( $data, $post_id, $media_id_map, $url_map, $url_to_new_id );

		// Restore exported post meta (attachment IDs/URLs remapped via media maps).
		$this->import_meta_data( $data, $post_id, $media_id_map, $url_map, $url_to_new_id );

		if ( null !== $post_id_map && isset( $data['ID'] ) ) {
			$post_id_map[ (int) $data['ID'] ] = $post_id;
		}

		$this->imported_post_ids[] = $post_id;

		return $post_id;
	}

	/**
	 * Flush object cache entries for posts touched during selected import (no global flush).
	 *
	 * @return void
	 */
	public function purge_selected_import_caches(): void {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $this->imported_post_ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $post_id ) {
			if ( $post_id > 0 && function_exists( 'clean_post_cache' ) ) {
				clean_post_cache( $post_id );
			}
		}

		if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
			wp_cache_set_posts_last_changed();
		}

		/**
		 * Fires after selected content import flushed per-post caches.
		 *
		 * @since 2.2.2
		 *
		 * @param int[] $ids Imported or updated post IDs.
		 */
		do_action( 'mksddn_mc_selected_import_completed', $ids );

		$this->imported_post_ids = array();
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
	 * Resolve post type from export payload (`type` from exporter; `post_type` for compatibility).
	 *
	 * @param array $data Item payload.
	 * @return string|false Registered post type, or false if type is blocked or missing on site.
	 */
	private function resolve_import_post_type( array $data ) {
		$raw = $data['type'] ?? $data['post_type'] ?? '';
		$post_type = '';
		if ( is_scalar( $raw ) ) {
			$post_type = \sanitize_key( (string) $raw );
		}

		if ( '' === $post_type ) {
			$post_type = 'page';
		}

		$disallowed = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
		);

		if ( in_array( $post_type, $disallowed, true ) ) {
			return false;
		}

		if ( ! \post_type_exists( $post_type ) ) {
			return false;
		}

		return $post_type;
	}

	/**
	 * Validate options page data.
	 *
	 * @param array $data Options page payload.
	 * @return bool
	 */
	private function validate_options_page_data( array $data ): bool {
		$data = $this->normalize_options_page_payload( $data );

		if ( ! isset( $data['menu_slug'], $data['acf_fields'] ) || ! is_array( $data['acf_fields'] ) ) {
			return false;
		}

		return '' !== sanitize_key( (string) $data['menu_slug'] );
	}

	/**
	 * Normalize legacy Options Page payload keys (`data` → `acf_fields`).
	 *
	 * @param array $data Options page payload.
	 * @return array
	 */
	private function normalize_options_page_payload( array $data ): array {
		if ( ! isset( $data['acf_fields'] ) && isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$data['acf_fields'] = $data['data'];
		}

		return $data;
	}

	/**
	 * Validate all Options Pages can be imported before any writes.
	 *
	 * @param array $pages Options page payloads.
	 * @return bool
	 */
	private function validate_options_pages_for_import( array $pages ): bool {
		if ( ! function_exists( 'update_field' ) ) {
			$this->last_error = __( 'This archive includes ACF Options Pages, but Advanced Custom Fields is not available on this site.', 'mksddn-migrate-content' );
			return false;
		}

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				$this->last_error = __( 'Invalid ACF Options Page payload in archive.', 'mksddn-migrate-content' );
				return false;
			}

			$page = $this->normalize_options_page_payload( $page );

			if ( ! $this->validate_options_page_data( $page ) ) {
				$this->last_error = __( 'Invalid ACF Options Page payload in archive.', 'mksddn-migrate-content' );
				return false;
			}

			$menu_slug = sanitize_key( (string) $page['menu_slug'] );
			$local     = $this->options_helper->find_options_page_by_slug( $menu_slug );

			if ( ! is_array( $local ) || ! isset( $local['post_id'] ) || '' === (string) $local['post_id'] ) {
				$this->last_error = sprintf(
					/* translators: %s: ACF Options Page menu_slug */
					__( 'ACF Options Page "%s" was not found on this site. Register the same menu_slug before import.', 'mksddn-migrate-content' ),
					$menu_slug
				);
				return false;
			}

			$post_id = $this->normalize_acf_post_id( $local['post_id'] );
			if ( false === $this->validate_options_page_fields_ready( $page, $post_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Ensure Options Page ACF fields resolve to registered local field objects before any write.
	 *
	 * @param array      $page    Normalized options page payload.
	 * @param int|string $post_id Local ACF post_id.
	 * @return bool
	 */
	private function validate_options_page_fields_ready( array $page, $post_id ): bool {
		if ( ! isset( $page['acf_fields'] ) || ! is_array( $page['acf_fields'] ) || array() === $page['acf_fields'] ) {
			return true;
		}

		$missing = array();
		foreach ( array_keys( $page['acf_fields'] ) as $field_name ) {
			$name = sanitize_text_field( (string) $field_name );
			if ( '' === $name ) {
				continue;
			}
			if ( null === $this->acf_get_local_field_object( $name, $post_id ) ) {
				$missing[] = $name;
			}
		}

		if ( array() === $missing ) {
			return true;
		}

		$this->last_error = sprintf(
			/* translators: 1: Options Page menu_slug, 2: comma-separated field names */
			__( 'ACF Options Page "%1$s" cannot be imported until matching field groups are registered for: %2$s.', 'mksddn-migrate-content' ),
			sanitize_key( (string) ( $page['menu_slug'] ?? '' ) ),
			implode( ', ', $missing )
		);
		return false;
	}

	/**
	 * Import ACF Options Pages from a bundle.
	 *
	 * @param array $pages Options page payloads.
	 * @return bool True on success, false on failure.
	 */
	private function import_options_pages( array $pages ): bool {
		if ( ! function_exists( 'update_field' ) ) {
			$this->last_error = __( 'This archive includes ACF Options Pages, but Advanced Custom Fields is not available on this site.', 'mksddn-migrate-content' );
			return false;
		}

		$applied = array();

		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				$this->last_error = __( 'Invalid ACF Options Page payload in archive.', 'mksddn-migrate-content' );
				$this->append_options_pages_written_notice( $applied );
				return false;
			}

			$page      = $this->normalize_options_page_payload( $page );
			$menu_slug = sanitize_key( (string) ( $page['menu_slug'] ?? '' ) );

			if ( ! $this->validate_options_page_data( $page ) ) {
				$this->last_error = __( 'Invalid ACF Options Page payload in archive.', 'mksddn-migrate-content' );
				$this->append_options_pages_written_notice( $applied );
				return false;
			}

			if ( false === $this->import_single_options_page( $page ) ) {
				$written = $applied;
				if ( $this->options_page_mutation_started && '' !== $menu_slug ) {
					$written[] = $menu_slug;
				}
				$this->append_options_pages_written_notice( $written );
				return false;
			}

			if ( '' !== $menu_slug ) {
				$applied[] = $menu_slug;
			}
		}

		return true;
	}

	/**
	 * Note Options Pages whose values were already written when a later page fails.
	 *
	 * @param string[] $menu_slugs Menu slugs already mutated.
	 * @return void
	 */
	private function append_options_pages_written_notice( array $menu_slugs ): void {
		$menu_slugs = array_values( array_filter( array_unique( $menu_slugs ) ) );
		if ( array() === $menu_slugs ) {
			return;
		}

		$this->last_error = trim(
			$this->last_error . ' ' . sprintf(
				/* translators: %s: comma-separated ACF Options Page menu slugs */
				__( 'ACF Options Page field values were already written for: %s. There is no automatic rollback.', 'mksddn-migrate-content' ),
				implode( ', ', $menu_slugs )
			)
		);
	}

	/**
	 * Import a single ACF Options Page payload.
	 *
	 * @param array $data Options page payload.
	 * @return bool True on success, false on failure.
	 */
	private function import_single_options_page( array $data ): bool {
		$this->options_page_mutation_started = false;
		$data                                 = $this->normalize_options_page_payload( $data );
		$menu_slug                            = sanitize_key( (string) $data['menu_slug'] );
		$local                                = $this->options_helper->find_options_page_by_slug( $menu_slug );

		if ( ! is_array( $local ) || ! isset( $local['post_id'] ) || '' === (string) $local['post_id'] ) {
			$this->last_error = sprintf(
				/* translators: %s: ACF Options Page menu_slug */
				__( 'ACF Options Page "%s" was not found on this site. Register the same menu_slug before import.', 'mksddn-migrate-content' ),
				$menu_slug
			);
			return false;
		}

		$post_id = $this->normalize_acf_post_id( $local['post_id'] );

		$media_maps = $this->restore_media_for_options_page( $data );
		if ( false === $this->assert_options_page_media_remapped( $data, $media_maps ) ) {
			if ( ! empty( $media_maps['id_map'] ) && is_array( $media_maps['id_map'] ) ) {
				// Some attachments may already exist in the Media Library.
				$this->options_page_mutation_started = true;
			}
			return false;
		}

		$media_id_map  = $media_maps['id_map'] ?? array();
		$url_map       = $media_maps['url_map'] ?? array();
		$url_to_new_id = $this->build_url_to_new_id_map( $data, $media_id_map );

		return $this->import_acf_fields( $data, $post_id, $media_id_map, $url_map, $url_to_new_id, true );
	}

	/**
	 * Fail Options Page import when archive media entries were not fully remapped.
	 *
	 * Prevents writing source-site attachment IDs into options fields after a soft sideload failure.
	 *
	 * @param array $data       Options page payload.
	 * @param array $media_maps Restorer maps (id_map, url_map).
	 * @return bool True when remap is complete (or no media entries).
	 */
	private function assert_options_page_media_remapped( array $data, array $media_maps ): bool {
		$entries = isset( $data['_mksddn_media'] ) && is_array( $data['_mksddn_media'] ) ? $data['_mksddn_media'] : array();
		if ( array() === $entries ) {
			return true;
		}

		$id_map  = isset( $media_maps['id_map'] ) && is_array( $media_maps['id_map'] ) ? $media_maps['id_map'] : array();
		$missing = array();

		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['original_id'] ) || ! is_numeric( $entry['original_id'] ) ) {
				continue;
			}
			$old_id = (int) $entry['original_id'];
			if ( $old_id <= 0 ) {
				continue;
			}
			if ( ! isset( $id_map[ $old_id ] ) ) {
				$missing[] = $old_id;
			}
		}

		if ( array() === $missing ) {
			return true;
		}

		$this->last_error = sprintf(
			/* translators: 1: Options Page menu_slug, 2: comma-separated attachment IDs */
			__( 'ACF Options Page "%1$s" media restore failed for attachment ID(s): %2$s. Import aborted to avoid writing source-site IDs.', 'mksddn-migrate-content' ),
			sanitize_key( (string) ( $data['menu_slug'] ?? '' ) ),
			implode( ', ', array_unique( $missing ) )
		);
		return false;
	}

	/**
	 * Restore media for an options page (no post parent / featured image).
	 *
	 * @param array $data Options page payload.
	 * @return array{id_map:array,url_map:array}
	 */
	private function restore_media_for_options_page( array $data ): array {
		$entries = $data['_mksddn_media'] ?? array();

		if ( empty( $entries ) || ! is_callable( $this->media_file_loader ) ) {
			return array(
				'id_map'  => array(),
				'url_map' => array(),
			);
		}

		return $this->media_restorer->restore(
			$entries,
			$this->media_file_loader,
			0
		);
	}

	/**
	 * Normalize ACF post_id (numeric post ID or options string key).
	 *
	 * @param mixed $post_id Raw post_id.
	 * @return int|string
	 */
	private function normalize_acf_post_id( $post_id ) {
		if ( is_int( $post_id ) ) {
			return $post_id;
		}

		$as_string = (string) $post_id;
		if ( '' !== $as_string && ctype_digit( $as_string ) ) {
			return (int) $as_string;
		}

		return $as_string;
	}

	/**
	 * Whether the ACF post_id refers to a numeric WP post.
	 *
	 * @param int|string $post_id ACF post_id.
	 */
	private function is_numeric_acf_post_id( $post_id ): bool {
		return is_int( $post_id ) || ( is_string( $post_id ) && '' !== $post_id && ctype_digit( $post_id ) );
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
	private function update_post( WP_Post $existing, array $post_data ) {
		$post_data['ID'] = $existing->ID;
		return $this->wp_functions->update_post( $post_data );
	}

	/**
	 * Create a post/page.
	 *
	 * @param array $post_data Data to insert.
	 * @return int|WP_Error
	 */
	private function create_post( array $post_data ) {
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
	 * Import ACF field values onto a post or Options Page post_id.
	 *
	 * For numeric posts: when the local field object is missing (SCF/ACF group
	 * not attached yet), restore scoped postmeta from the payload instead of
	 * relying on update_field(). When the field object exists, update_field()
	 * runs first; meta fallback still runs if the value is missing or nested
	 * structural meta is incomplete (e.g. a non-expanded repeater blob).
	 *
	 * @param array      $data          Payload with acf_fields.
	 * @param int|string $post_id       Target post ID or ACF options post_id.
	 * @param array      $id_map        Media ID mapping.
	 * @param array      $url_map       Media URL mapping.
	 * @param array      $url_to_new_id Original URL => new attachment ID.
	 * @param bool       $strict        When true, fail if a meaningful value did not persist.
	 * @return bool True on success (or non-strict soft failure), false when strict and a field is missing.
	 */
	private function import_acf_fields( array $data, $post_id, array $id_map = array(), array $url_map = array(), array $url_to_new_id = array(), bool $strict = false ): bool {
		if ( ! isset( $data['acf_fields'] ) || ! is_array( $data['acf_fields'] ) ) {
			return true;
		}

		$acf_post_id = $this->normalize_acf_post_id( $post_id );
		if ( ( is_int( $acf_post_id ) && $acf_post_id <= 0 ) || ( is_string( $acf_post_id ) && '' === $acf_post_id ) ) {
			return true;
		}

		$failed_fields = array();
		$schema_map    = ( isset( $data['acf_field_schema'] ) && is_array( $data['acf_field_schema'] ) ) ? $data['acf_field_schema'] : array();
		$use_schema    = $strict || array() !== $schema_map;
		$payload_meta  = ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) ? $data['meta'] : array();
		$is_post       = $this->is_numeric_acf_post_id( $acf_post_id );
		$can_update    = function_exists( 'update_field' );

		// Posts can still restore scoped ACF meta when the ACF/SCF API is unavailable.
		if ( ! $can_update ) {
			if ( ! $is_post || array() === $payload_meta ) {
				return true;
			}
			foreach ( array_keys( $data['acf_fields'] ) as $field_name ) {
				$name = sanitize_text_field( (string) $field_name );
				if ( '' === $name ) {
					continue;
				}
				$this->acf_import_meta_fallback_from_payload( $payload_meta, $name, (int) $acf_post_id, $id_map, $url_map, $url_to_new_id );
			}
			return true;
		}

		foreach ( $data['acf_fields'] as $field_name => $field_value ) {
			$name = sanitize_text_field( (string) $field_name );
			if ( $use_schema ) {
				$field_schema = array();
				if ( isset( $schema_map[ $field_name ] ) && is_array( $schema_map[ $field_name ] ) ) {
					$field_schema = $schema_map[ $field_name ];
				} elseif ( isset( $schema_map[ $name ] ) && is_array( $schema_map[ $name ] ) ) {
					$field_schema = $schema_map[ $name ];
				}
				$value = $this->remap_options_field_value( $field_value, $field_schema, $id_map, $url_map, $url_to_new_id );
			} else {
				$value = $this->remap_media_values( $field_value, $id_map, $url_map, $url_to_new_id );
			}

			$field_object = $this->acf_get_local_field_object( $name, $acf_post_id );
			$has_object   = is_array( $field_object ) && ! empty( $field_object['key'] );

			// Without a local field definition, update_field() cannot expand repeaters/groups.
			if ( $is_post && ! $has_object && array() !== $payload_meta ) {
				$this->acf_import_meta_fallback_from_payload( $payload_meta, $name, (int) $acf_post_id, $id_map, $url_map, $url_to_new_id );
				continue;
			}

			$field_selector = ( $has_object && is_string( $field_object['key'] ) )
				? $field_object['key']
				: $name;
			update_field( $field_selector, $value, $acf_post_id );
			if ( $strict ) {
				$this->options_page_mutation_started = true;
			}

			// Meta fallback only applies to real WP posts (options pages use wp_options, not postmeta).
			if ( $is_post ) {
				$needs_fallback = $this->acf_is_value_missing_after_import( $name, $value, $acf_post_id )
					|| $this->acf_is_structural_meta_incomplete( $name, $value, (int) $acf_post_id, $payload_meta );
				if ( $needs_fallback ) {
					$this->acf_import_meta_fallback_from_payload( $payload_meta, $name, (int) $acf_post_id, $id_map, $url_map, $url_to_new_id );
				}
			}

			if ( $strict && $this->acf_is_value_missing_after_import( $name, $value, $acf_post_id ) ) {
				$failed_fields[] = $name;
			} elseif ( $strict && ! $is_post && $this->acf_is_options_structure_incomplete( $name, $value, $acf_post_id ) ) {
				$failed_fields[] = $name;
			}
		}

		if ( $strict && array() !== $failed_fields ) {
			$this->last_error = sprintf(
				/* translators: %s: comma-separated ACF field names */
				__( 'Failed to apply ACF Options Page field(s): %s. Ensure matching field groups are registered on this site.', 'mksddn-migrate-content' ),
				implode( ', ', $failed_fields )
			);
			return false;
		}

		return true;
	}

	/**
	 * Get the local ACF/SCF field object for a field name on a post_id, if registered.
	 *
	 * @param string     $field_name Sanitized field name from payload.
	 * @param int|string $post_id    Target post ID or ACF options post_id.
	 * @return array|null Field object array or null when unresolved.
	 */
	private function acf_get_local_field_object( string $field_name, $post_id ): ?array {
		if ( ! function_exists( 'get_field_object' ) ) {
			return null;
		}

		if ( $this->is_numeric_acf_post_id( $post_id ) && (int) $post_id <= 0 ) {
			return null;
		}

		if ( is_string( $post_id ) && '' === $post_id ) {
			return null;
		}

		$object = get_field_object( $field_name, $post_id, false, false );
		if ( ! is_array( $object ) || empty( $object['key'] ) ) {
			return null;
		}

		return $object;
	}

	/**
	 * Detect when ACF still returns an empty value after import.
	 *
	 * @param string     $field_name Field name from payload.
	 * @param mixed      $expected   Imported payload value.
	 * @param int|string $post_id    Target post ID or ACF options post_id.
	 * @return bool
	 */
	private function acf_is_value_missing_after_import( string $field_name, $expected, $post_id ): bool {
		if ( ! function_exists( 'get_field' ) ) {
			return false;
		}

		if ( $this->is_numeric_acf_post_id( $post_id ) && (int) $post_id <= 0 ) {
			return false;
		}

		if ( is_string( $post_id ) && '' === $post_id ) {
			return false;
		}

		if ( ! $this->acf_has_meaningful_value( $expected ) ) {
			return false;
		}

		$stored = get_field( $field_name, $post_id, false );
		return ! $this->acf_has_meaningful_value( $stored );
	}

	/**
	 * Whether complex Options Page field structures look incomplete after update_field().
	 *
	 * Options live in wp_options (not postmeta), so compare remapped payload shape vs get_field().
	 * Attachment-shaped payload arrays are compatible with scalar IDs from get_field(..., false).
	 *
	 * @param string     $field_name Field name from payload.
	 * @param mixed      $expected   Remapped payload value.
	 * @param int|string $post_id    ACF options post_id.
	 * @return bool
	 */
	private function acf_is_options_structure_incomplete( string $field_name, $expected, $post_id ): bool {
		if ( ! function_exists( 'get_field' ) || ! is_array( $expected ) || ! $this->acf_has_meaningful_value( $expected ) ) {
			return false;
		}

		$stored = get_field( $field_name, $post_id, false );

		if ( $this->acf_is_attachment_shaped_value( $expected ) ) {
			return ! $this->acf_stored_matches_attachment_payload( $stored );
		}

		if ( ! is_array( $stored ) ) {
			return true;
		}

		return $this->acf_array_structure_shallower( $expected, $stored );
	}

	/**
	 * Whether a value looks like an ACF attachment array (ID/id keys).
	 *
	 * @param mixed $value Value from payload or get_field().
	 * @return bool
	 */
	private function acf_is_attachment_shaped_value( $value ): bool {
		return is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['id'] ) );
	}

	/**
	 * Whether stored get_field() value matches an attachment-shaped payload.
	 *
	 * Unformatted image/file fields often return a positive attachment ID, while
	 * the remapped archive payload keeps the full attachment array.
	 *
	 * @param mixed $stored Value from get_field(..., false).
	 * @return bool
	 */
	private function acf_stored_matches_attachment_payload( $stored ): bool {
		if ( is_numeric( $stored ) ) {
			return (int) $stored > 0;
		}

		if ( $this->acf_is_attachment_shaped_value( $stored ) ) {
			$id = isset( $stored['ID'] ) ? (int) $stored['ID'] : (int) ( $stored['id'] ?? 0 );
			return $id > 0;
		}

		return false;
	}

	/**
	 * Whether stored array structure is shallower than expected (missing rows/keys).
	 *
	 * @param array $expected Expected remapped value.
	 * @param array $stored   Value returned by get_field().
	 * @return bool
	 */
	private function acf_array_structure_shallower( array $expected, array $stored ): bool {
		$expected_is_list = array() === $expected || array_keys( $expected ) === range( 0, count( $expected ) - 1 );
		$stored_is_list   = array() === $stored || array_keys( $stored ) === range( 0, count( $stored ) - 1 );

		if ( $expected_is_list ) {
			if ( ! $stored_is_list ) {
				return true;
			}
			if ( count( $stored ) < count( $expected ) ) {
				return true;
			}
			foreach ( $expected as $index => $expected_row ) {
				if ( ! array_key_exists( $index, $stored ) ) {
					return true;
				}
				if ( $this->acf_expected_child_incomplete( $expected_row, $stored[ $index ] ) ) {
					return true;
				}
			}
			return false;
		}

		foreach ( $expected as $key => $expected_child ) {
			if ( 'acf_fc_layout' === (string) $key ) {
				continue;
			}
			if ( ! array_key_exists( $key, $stored ) ) {
				return true;
			}
			if ( $this->acf_expected_child_incomplete( $expected_child, $stored[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether an expected nested value looks incomplete vs stored get_field() data.
	 *
	 * @param mixed $expected Expected remapped child value.
	 * @param mixed $stored   Stored child value.
	 * @return bool
	 */
	private function acf_expected_child_incomplete( $expected, $stored ): bool {
		if ( ! is_array( $expected ) ) {
			return false;
		}

		if ( $this->acf_is_attachment_shaped_value( $expected ) ) {
			return ! $this->acf_stored_matches_attachment_payload( $stored );
		}

		if ( ! is_array( $stored ) ) {
			return true;
		}

		return $this->acf_array_structure_shallower( $expected, $stored );
	}

	/**
	 * Whether complex field meta is incomplete after update_field().
	 *
	 * Catches the case where get_field() returns a non-empty blob under the root
	 * key but nested repeater/group leaf keys were never written.
	 *
	 * @param string $field_name   Field name from payload.
	 * @param mixed  $expected     Remapped payload value.
	 * @param int    $post_id      Target post ID.
	 * @param array  $payload_meta Full payload meta (used to know if source had leaves).
	 * @return bool
	 */
	private function acf_is_structural_meta_incomplete( string $field_name, $expected, int $post_id, array $payload_meta ): bool {
		if ( $post_id <= 0 || ! is_array( $expected ) || ! $this->acf_has_meaningful_value( $expected ) ) {
			return false;
		}

		if ( ! $this->acf_payload_meta_has_nested_leaves( $payload_meta, $field_name ) ) {
			return false;
		}

		return ! $this->acf_post_has_nested_leaf_meta( $post_id, $field_name );
	}

	/**
	 * Whether payload meta includes nested leaf keys for a field (repeater/group rows).
	 *
	 * @param array  $meta       Payload meta.
	 * @param string $field_name Field name.
	 * @return bool
	 */
	private function acf_payload_meta_has_nested_leaves( array $meta, string $field_name ): bool {
		$prefix_pattern = '/^' . preg_quote( $field_name, '/' ) . '_[^_]+_/';
		$ref_pattern    = '/^_' . preg_quote( $field_name, '/' ) . '_[^_]+_/';

		foreach ( $meta as $raw_key => $values ) {
			$mk = sanitize_text_field( (string) $raw_key );
			if ( ! preg_match( $prefix_pattern, $mk ) && ! preg_match( $ref_pattern, $mk ) ) {
				continue;
			}
			$stored = $this->acf_single_meta_payload_value( $values );
			if ( null !== $stored && '' !== $stored && array() !== $stored ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the post already has nested ACF leaf meta for a field.
	 *
	 * @param int    $post_id    Target post ID.
	 * @param string $field_name Field name.
	 * @return bool
	 */
	private function acf_post_has_nested_leaf_meta( int $post_id, string $field_name ): bool {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) || array() === $all ) {
			return false;
		}

		$prefix_pattern = '/^' . preg_quote( $field_name, '/' ) . '_[^_]+_/';
		foreach ( $all as $meta_key => $values ) {
			$mk = (string) $meta_key;
			if ( ! preg_match( $prefix_pattern, $mk ) ) {
				continue;
			}
			$first = is_array( $values ) && array() !== $values ? reset( $values ) : $values;
			if ( null !== $first && '' !== $first && array() !== $first ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Import ACF meta keys for a single field directly from payload meta.
	 *
	 * Used when update_field() cannot expand nested fields (missing field group)
	 * or leaves them incomplete. After writing leaves, clears a non-scalar root
	 * blob left by a failed update_field() and restores ACF row-count when present.
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

		$prefix          = $field_name . '_';
		$has_nested_leaf = $this->acf_payload_meta_has_nested_leaves( $meta, $field_name );
		$root_count      = null;
		$payload_keys    = array();

		foreach ( array_keys( $meta ) as $raw_key ) {
			$meta_key = sanitize_text_field( (string) $raw_key );
			if ( ! $this->acf_meta_key_belongs_to_field( $meta_key, $field_name, $prefix ) ) {
				continue;
			}
			$payload_keys[ $meta_key ] = true;
		}

		// Drop destination rows that are not in the archive (shorter repeater on re-import).
		$this->acf_delete_stale_field_meta( $post_id, $field_name, $prefix, $payload_keys );

		foreach ( $meta as $raw_key => $values ) {
			$meta_key = sanitize_text_field( (string) $raw_key );
			if ( ! isset( $payload_keys[ $meta_key ] ) ) {
				continue;
			}

			$stored = $this->acf_single_meta_payload_value( $values );

			// ACF often stores an empty root meta row while leaf keys use "{$field_name}_..."; writing '' breaks nested groups.
			if ( $has_nested_leaf && $field_name === $meta_key ) {
				if ( null === $stored || '' === $stored || array() === $stored ) {
					continue;
				}
				// Remember scalar row count from source (repeater/flexible store an integer count).
				if ( is_numeric( $stored ) && ! is_array( $stored ) ) {
					$root_count = $stored;
				}
				// Do not restore a serialized array blob under the root key; leaves carry the data.
				if ( is_array( $stored ) ) {
					continue;
				}
			}

			delete_post_meta( $post_id, $meta_key );

			$value = maybe_unserialize( $stored );
			// Row count is a small integer and collides with exported attachment IDs.
			$is_row_count = $has_nested_leaf && $field_name === $meta_key && is_numeric( $value ) && ! is_array( $value );
			if ( ! $is_row_count ) {
				$value = $this->remap_media_values( $value, $id_map, $url_map, $url_to_new_id );
			}
			update_post_meta( $post_id, $meta_key, $value );
		}

		if ( $has_nested_leaf ) {
			$this->acf_cleanup_root_field_blob( $post_id, $field_name, $root_count, $payload_keys );
		}
	}

	/**
	 * Whether a meta key is the field root, its reference, or a nested leaf.
	 *
	 * @param string $meta_key   Sanitized meta key.
	 * @param string $field_name Field name.
	 * @param string $prefix     Field name plus underscore.
	 * @return bool
	 */
	private function acf_meta_key_belongs_to_field( string $meta_key, string $field_name, string $prefix ): bool {
		return (
			$field_name === $meta_key
			|| '_' . $field_name === $meta_key
			|| 0 === strpos( $meta_key, $prefix )
			|| 0 === strpos( $meta_key, '_' . $prefix )
		);
	}

	/**
	 * Delete post meta for this field that the archive does not contain.
	 *
	 * @param int                  $post_id      Target post ID.
	 * @param string               $field_name   Field name.
	 * @param string               $prefix       Field name plus underscore.
	 * @param array<string, true> $payload_keys Keys present in the archive for this field.
	 * @return void
	 */
	private function acf_delete_stale_field_meta( int $post_id, string $field_name, string $prefix, array $payload_keys ): void {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return;
		}

		foreach ( array_keys( $all ) as $meta_key ) {
			$meta_key = (string) $meta_key;
			if ( isset( $payload_keys[ $meta_key ] ) ) {
				continue;
			}
			if ( ! $this->acf_meta_key_belongs_to_field( $meta_key, $field_name, $prefix ) ) {
				continue;
			}
			delete_post_meta( $post_id, $meta_key );
		}
	}

	/**
	 * One stored meta value from a selected-content payload entry.
	 *
	 * BatchLoader exports `meta_key => unserialized_value`. A list (gallery,
	 * checkbox, relationship) is that single value, not several postmeta rows.
	 *
	 * @param mixed $values Payload meta value.
	 * @return mixed
	 */
	private function acf_single_meta_payload_value( $values ) {
		if ( is_string( $values ) ) {
			return maybe_unserialize( $values );
		}

		return $values;
	}

	/**
	 * Replace a non-scalar or stale root value with the archive row count.
	 *
	 * Count is taken from the payload root when it is numeric, otherwise from
	 * row indexes present in the archive — not from leftover rows on the post.
	 *
	 * @param int                  $post_id      Target post ID.
	 * @param string               $field_name   Field name.
	 * @param int|string|null      $root_count   Optional row count from payload meta.
	 * @param array<string, true> $payload_keys Keys present in the archive for this field.
	 * @return void
	 */
	private function acf_cleanup_root_field_blob( int $post_id, string $field_name, $root_count, array $payload_keys ): void {
		if ( $post_id <= 0 || '' === $field_name ) {
			return;
		}

		if ( ! $this->acf_post_has_nested_leaf_meta( $post_id, $field_name ) ) {
			return;
		}

		$count = null;
		if ( null !== $root_count && is_numeric( $root_count ) && ! is_array( $root_count ) ) {
			$count = $root_count;
		} else {
			$max_index = -1;
			$pattern   = '/^' . preg_quote( $field_name, '/' ) . '_(\d+)_/';
			foreach ( array_keys( $payload_keys ) as $meta_key ) {
				if ( preg_match( $pattern, (string) $meta_key, $matches ) ) {
					$max_index = max( $max_index, (int) $matches[1] );
				}
			}
			if ( $max_index >= 0 ) {
				$count = (string) ( $max_index + 1 );
			}
		}

		$current = get_post_meta( $post_id, $field_name, true );
		if ( null === $count ) {
			if ( is_array( $current ) ) {
				delete_post_meta( $post_id, $field_name );
			}
			return;
		}

		if ( is_array( $current ) || (string) $current !== (string) $count ) {
			update_post_meta( $post_id, $field_name, $count );
		}
	}

	/**
	 * Check whether a value is non-empty for import verification.
	 *
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private function acf_has_meaningful_value( $value ): bool {
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

			// BatchLoader / prepare_post_data export a single mixed value per key (already
			// unserialized). WordPress multi-row meta is a numeric list of row values.
			// Associative arrays must stay one meta row — do not iterate their keys.
			if ( is_array( $values ) && $this->is_meta_multi_value_list( $values ) ) {
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
	 * Whether a meta payload value is a list of WordPress multi-row values.
	 *
	 * @param array $values Candidate meta value.
	 */
	private function is_meta_multi_value_list( array $values ): bool {
		if ( array() === $values ) {
			return true;
		}

		$i = 0;
		foreach ( $values as $key => $_unused ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}

		return true;
	}

	/**
	 * Remap media inside one Options Page field without touching unrelated numbers.
	 *
	 * Bare integers are rewritten only for image, file, and gallery fields.
	 * Number and relationship values stay as exported even when they equal an attachment ID.
	 *
	 * @param mixed $value          Field value.
	 * @param array $schema         Field schema node (type, sub_fields, layouts).
	 * @param array $id_map         Original attachment ID => new ID.
	 * @param array $url_map        Original URL => new URL.
	 * @param array $url_to_new_id  Original URL => new attachment ID.
	 * @return mixed
	 */
	private function remap_options_field_value( $value, array $schema, array $id_map, array $url_map, array $url_to_new_id ) {
		$type = (string) ( $schema['type'] ?? '' );

		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			return $this->remap_attachment_leaf( $value, $id_map, $url_map, $url_to_new_id );
		}

		if ( 'gallery' === $type ) {
			return $this->remap_gallery_value( $value, $id_map, $url_map, $url_to_new_id );
		}

		if ( in_array( $type, array( 'wysiwyg', 'textarea' ), true ) && is_string( $value ) ) {
			return $this->remap_embedded_media_string( $value, $id_map, $url_map );
		}

		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			return $this->remap_named_options_fields( $value, $sub, $id_map, $url_map, $url_to_new_id );
		}

		if ( 'repeater' === $type && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			foreach ( $value as $index => $row ) {
				if ( is_array( $row ) ) {
					$value[ $index ] = $this->remap_named_options_fields( $row, $sub, $id_map, $url_map, $url_to_new_id );
				}
			}
			return $value;
		}

		if ( 'flexible_content' === $type && is_array( $value ) ) {
			$layouts = ( isset( $schema['layouts'] ) && is_array( $schema['layouts'] ) ) ? $schema['layouts'] : array();
			foreach ( $value as $index => $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$layout_name = isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
				$sub         = ( isset( $layouts[ $layout_name ]['sub_fields'] ) && is_array( $layouts[ $layout_name ]['sub_fields'] ) )
					? $layouts[ $layout_name ]['sub_fields']
					: array();
				$value[ $index ] = $this->remap_named_options_fields( $row, $sub, $id_map, $url_map, $url_to_new_id );
			}
			return $value;
		}

		return $this->remap_embedded_media_only( $value, $id_map, $url_map, $url_to_new_id );
	}

	/**
	 * Remap named sub fields of a group, repeater row, or flexible layout.
	 *
	 * @param array $values         Name => value.
	 * @param array $schema         Name => schema node.
	 * @param array $id_map         Original attachment ID => new ID.
	 * @param array $url_map        Original URL => new URL.
	 * @param array $url_to_new_id  Original URL => new attachment ID.
	 * @return array
	 */
	private function remap_named_options_fields( array $values, array $schema, array $id_map, array $url_map, array $url_to_new_id ): array {
		foreach ( $values as $name => $child ) {
			if ( 'acf_fc_layout' === (string) $name ) {
				continue;
			}
			$field_schema   = ( isset( $schema[ $name ] ) && is_array( $schema[ $name ] ) ) ? $schema[ $name ] : array();
			$values[ $name ] = $this->remap_options_field_value( $child, $field_schema, $id_map, $url_map, $url_to_new_id );
		}

		return $values;
	}

	/**
	 * Remap a gallery value (list of IDs or a comma-separated ID string).
	 *
	 * @param mixed $value          Gallery value.
	 * @param array $id_map         Original attachment ID => new ID.
	 * @param array $url_map        Original URL => new URL.
	 * @param array $url_to_new_id  Original URL => new attachment ID.
	 * @return mixed
	 */
	private function remap_gallery_value( $value, array $id_map, array $url_map, array $url_to_new_id ) {
		if ( is_string( $value ) && preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $value ) ) {
			$parts = array_map( 'trim', explode( ',', $value ) );
			foreach ( $parts as $index => $part ) {
				$remapped = $this->remap_attachment_leaf( $part, $id_map, $url_map, $url_to_new_id );
				$parts[ $index ] = is_scalar( $remapped ) ? (string) $remapped : $part;
			}
			return implode( ',', $parts );
		}

		if ( ! is_array( $value ) ) {
			return $this->remap_attachment_leaf( $value, $id_map, $url_map, $url_to_new_id );
		}

		foreach ( $value as $index => $item ) {
			$value[ $index ] = $this->remap_attachment_leaf( $item, $id_map, $url_map, $url_to_new_id );
		}

		return $value;
	}

	/**
	 * Remap one image/file leaf. Does not rewrite width, height, or other numeric meta.
	 *
	 * @param mixed $value          Leaf value.
	 * @param array $id_map         Original attachment ID => new ID.
	 * @param array $url_map        Original URL => new URL.
	 * @param array $url_to_new_id  Original URL => new attachment ID.
	 * @return mixed
	 */
	private function remap_attachment_leaf( $value, array $id_map, array $url_map, array $url_to_new_id ) {
		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			if ( isset( $id_map[ $int ] ) ) {
				return $id_map[ $int ];
			}
			return $value;
		}

		if ( is_string( $value ) ) {
			if ( isset( $url_to_new_id[ $value ] ) ) {
				return $url_to_new_id[ $value ];
			}
			if ( isset( $url_map[ $value ] ) ) {
				return $url_map[ $value ];
			}
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$old_id = null;
		if ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
			$old_id = (int) $value['ID'];
		} elseif ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
			$old_id = (int) $value['id'];
		}

		if ( null !== $old_id && isset( $id_map[ $old_id ] ) ) {
			$new_id      = $id_map[ $old_id ];
			$value['ID'] = $new_id;
			$value['id'] = $new_id;
			$new_url     = \wp_get_attachment_url( $new_id );
			if ( is_string( $new_url ) && '' !== $new_url ) {
				$value['url'] = $new_url;
			}
			if ( isset( $value['link'] ) ) {
				$value['link'] = \get_permalink( $new_id );
			}
			return $value;
		}

		if ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			if ( isset( $url_to_new_id[ $value['url'] ] ) ) {
				$new_id      = $url_to_new_id[ $value['url'] ];
				$value['ID'] = $new_id;
				$value['id'] = $new_id;
				$new_url     = \wp_get_attachment_url( $new_id );
				if ( is_string( $new_url ) && '' !== $new_url ) {
					$value['url'] = $new_url;
				}
			} elseif ( isset( $url_map[ $value['url'] ] ) ) {
				$value['url'] = $url_map[ $value['url'] ];
			}
		}

		return $value;
	}

	/**
	 * Replace uploads URLs and wp-image / gallery IDs inside an HTML string.
	 *
	 * @param string $value   HTML or textarea content.
	 * @param array  $id_map  Original attachment ID => new ID.
	 * @param array  $url_map Original URL => new URL.
	 * @return string
	 */
	private function remap_embedded_media_string( string $value, array $id_map, array $url_map ): string {
		foreach ( $url_map as $old => $new ) {
			if ( ! is_string( $old ) || ! is_string( $new ) || '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$value = str_replace( $old, $new, $value );
		}

		foreach ( $id_map as $old_id => $new_id ) {
			$old_id = (int) $old_id;
			$new_id = (int) $new_id;
			if ( $old_id <= 0 || $new_id <= 0 || $old_id === $new_id ) {
				continue;
			}
			$value = preg_replace( '/wp-image-' . $old_id . '\b/', 'wp-image-' . $new_id, $value );
		}

		if ( array() === $id_map ) {
			return $value;
		}

		$remapped = preg_replace_callback(
			'/\[gallery([^\]]*?)ids="([^"]+)"/i',
			static function ( array $matches ) use ( $id_map ): string {
				$parts = array_map( 'trim', explode( ',', $matches[2] ) );
				foreach ( $parts as $index => $part ) {
					if ( is_numeric( $part ) && isset( $id_map[ (int) $part ] ) ) {
						$parts[ $index ] = (string) $id_map[ (int) $part ];
					}
				}
				return '[gallery' . $matches[1] . 'ids="' . implode( ',', $parts ) . '"';
			},
			$value
		);

		return is_string( $remapped ) ? $remapped : $value;
	}

	/**
	 * Remap attachment-shaped arrays and embedded markup. Leave bare integers unchanged.
	 *
	 * @param mixed $value          Value node.
	 * @param array $id_map         Original attachment ID => new ID.
	 * @param array $url_map        Original URL => new URL.
	 * @param array $url_to_new_id  Original URL => new attachment ID.
	 * @return mixed
	 */
	private function remap_embedded_media_only( $value, array $id_map, array $url_map, array $url_to_new_id ) {
		if ( is_string( $value ) ) {
			return $this->remap_embedded_media_string( $value, $id_map, $url_map );
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		$has_id = ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) )
			|| ( isset( $value['id'] ) && is_numeric( $value['id'] ) );
		$looks_like_attachment = $has_id && ( isset( $value['url'] ) || isset( $value['filename'] ) || isset( $value['mime_type'] ) || isset( $value['sizes'] ) || isset( $value['type'] ) );
		if ( $looks_like_attachment ) {
			return $this->remap_attachment_leaf( $value, $id_map, $url_map, $url_to_new_id );
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->remap_embedded_media_only( $child, $id_map, $url_map, $url_to_new_id );
		}

		return $value;
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
	private function remap_media_values( $value, array $id_map, array $url_map, array $url_to_new_id = array() ) {
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
