<?php
/**
 * @file: SelectedContentDiffBuilder.php
 * @description: Builds field-name + status inventory for selected-content import preflight
 * @dependencies: Options\OptionsHelper
 * @created: 2026-10-05
 */

namespace MksDdn\MigrateContent\Import;

use MksDdn\MigrateContent\Options\OptionsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares archive payload items/options pages against the local site for preflight UI.
 *
 * Stores only non-unchanged rows (field name + status) to keep preflight reports small.
 *
 * @since 2.8.0
 */
class SelectedContentDiffBuilder {

	private const ACF_CAP = 40;

	private const META_CAP = 30;

	private const TAX_TERM_SAMPLE = 15;

	private const MEDIA_SAMPLE = 30;

	/**
	 * Source URL origins extracted from media manifest (for URL-rewrite detection).
	 *
	 * @var string[]
	 */
	private array $source_origins = array();

	/**
	 * Target home URL origin.
	 *
	 * @var string
	 */
	private string $target_origin = '';

	/**
	 * Media manifest indexed by original_id.
	 *
	 * @var array<int, array>
	 */
	private array $media_by_id = array();

	/**
	 * Prepare URL rewrite helpers from a media list.
	 *
	 * @param array $media Media manifest entries.
	 * @return void
	 */
	public function set_media_context( array $media ): void {
		$this->source_origins = array();
		$this->media_by_id    = array();
		$this->target_origin  = $this->url_origin( (string) home_url() );

		foreach ( $media as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$original_id = isset( $entry['original_id'] ) ? (int) $entry['original_id'] : 0;
			if ( $original_id > 0 ) {
				$this->media_by_id[ $original_id ] = $entry;
			}
			$source_url = isset( $entry['source_url'] ) ? (string) $entry['source_url'] : '';
			$origin     = $this->url_origin( $source_url );
			if ( '' !== $origin && ! in_array( $origin, $this->source_origins, true ) ) {
				$this->source_origins[] = $origin;
			}
		}
	}

	/**
	 * Build media sample list for the report.
	 *
	 * @param array $media Media manifest entries.
	 * @return array{total:int,sample:array<int,array{filename:string,filesize:int,host:string}>,truncated:bool}
	 */
	public function build_media_sample( array $media ): array {
		$sample = array();
		$total  = 0;

		foreach ( $media as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			++$total;
			if ( count( $sample ) >= self::MEDIA_SAMPLE ) {
				continue;
			}
			$filename = isset( $entry['filename'] ) ? (string) $entry['filename'] : '';
			if ( '' === $filename && isset( $entry['source_url'] ) ) {
				$filename = wp_basename( (string) $entry['source_url'] );
			}
			$host = '';
			if ( ! empty( $entry['source_url'] ) ) {
				$host = (string) wp_parse_url( (string) $entry['source_url'], PHP_URL_HOST );
			}
			$sample[] = array(
				'filename' => $filename,
				'filesize' => isset( $entry['filesize'] ) ? (int) $entry['filesize'] : 0,
				'host'     => $host,
			);
		}

		return array(
			'total'     => $total,
			'sample'    => $sample,
			'truncated' => $total > count( $sample ),
		);
	}

	/**
	 * Build field changes for a content item row.
	 *
	 * @param array $item             Archive payload item.
	 * @param int   $existing_post_id Local post ID (0 = create).
	 * @return array Compact changes payload.
	 */
	public function build_item_changes( array $item, int $existing_post_id ): array {
		$item_media = ( isset( $item['_mksddn_media'] ) && is_array( $item['_mksddn_media'] ) )
			? $item['_mksddn_media']
			: array();
		if ( ! empty( $item_media ) ) {
			$this->set_media_context( array_merge( array_values( $this->media_by_id ), $item_media ) );
		}

		if ( $existing_post_id <= 0 ) {
			return $this->build_create_changes( $item );
		}

		$post = get_post( $existing_post_id );
		if ( ! $post instanceof \WP_Post ) {
			return $this->build_create_changes( $item );
		}

		$core       = $this->diff_core_fields( $item, $post );
		$acf        = $this->diff_acf_fields( $item, $existing_post_id );
		$meta       = $this->diff_meta_fields( $item, $existing_post_id );
		$taxonomies = $this->diff_taxonomies( $item, $existing_post_id );
		$featured   = $this->diff_featured_media( $item, $existing_post_id );

		return $this->assemble_changes( $core, $acf, $meta, $taxonomies, $featured );
	}

	/**
	 * Build field changes for an ACF Options Page.
	 *
	 * @param array $page Archive options page payload.
	 * @param array $row  Preflight options page row (action, local_post_id, …).
	 * @return array Compact changes payload.
	 */
	public function build_options_page_changes( array $page, array $row ): array {
		$action = sanitize_key( (string) ( $row['action'] ?? 'missing' ) );
		$fields = array();
		if ( isset( $page['acf_fields'] ) && is_array( $page['acf_fields'] ) ) {
			$fields = $page['acf_fields'];
		} elseif ( isset( $page['data'] ) && is_array( $page['data'] ) ) {
			$fields = $page['data'];
		}

		// ACF is unavailable: skip field comparison. Preflight already warns about that.
		if ( ! function_exists( 'get_field' ) ) {
			return $this->assemble_changes(
				array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
				array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
				array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
				array(),
				null
			);
		}

		if ( 'missing' === $action ) {
			$rows = array();
			foreach ( $fields as $name => $value ) {
				if ( ! is_string( $name ) || '' === $name ) {
					continue;
				}
				$rows[] = array(
					'field'  => $name,
					'status' => 'added',
				);
				if ( count( $rows ) >= self::ACF_CAP ) {
					break;
				}
			}
			$extra = max( 0, count( $fields ) - count( $rows ) );
			return $this->assemble_changes(
				array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
				array(
					'rows'            => $rows,
					'unchanged_count' => 0,
					'truncated'       => $extra > 0,
					'more'            => $extra,
				),
				array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
				array(),
				null
			);
		}

		$menu_slug     = sanitize_key( (string) ( $row['menu_slug'] ?? $page['menu_slug'] ?? '' ) );
		$local_post_id = $row['local_post_id'] ?? null;
		$local_fields  = ( new OptionsHelper() )->get_unformatted_field_values( $menu_slug, $local_post_id );

		$acf = $this->diff_acf_maps( $fields, $local_fields );
		return $this->assemble_changes(
			array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
			$acf,
			array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 ),
			array(),
			null
		);
	}

	/**
	 * Create-mode: list non-empty incoming fields.
	 *
	 * @param array $item Payload item.
	 * @return array
	 */
	private function build_create_changes( array $item ): array {
		$core_rows = array();
		foreach ( $this->core_field_map( $item, null ) as $field => $pair ) {
			$after = $pair['after'];
			if ( $this->is_empty_value( $after ) ) {
				continue;
			}
			$core_rows[] = array(
				'field'  => $field,
				'status' => 'added',
			);
		}

		$acf_fields = ( isset( $item['acf_fields'] ) && is_array( $item['acf_fields'] ) ) ? $item['acf_fields'] : array();
		$acf_rows   = array();
		foreach ( $acf_fields as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$acf_rows[] = array(
				'field'  => $name,
				'status' => 'added',
			);
			if ( count( $acf_rows ) >= self::ACF_CAP ) {
				break;
			}
		}
		$acf_more = max( 0, count( $acf_fields ) - count( $acf_rows ) );

		$meta       = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : array();
		$skip_meta  = $this->acf_meta_skip_keys( $acf_fields );
		$meta_rows  = array();
		$meta_total = 0;
		foreach ( $meta as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( isset( $skip_meta[ $key ] ) || $this->is_skipped_meta_key( $key ) ) {
				continue;
			}
			++$meta_total;
			if ( count( $meta_rows ) >= self::META_CAP ) {
				continue;
			}
			$meta_rows[] = array(
				'field'  => $key,
				'status' => 'added',
			);
		}

		$taxonomies = array();
		if ( isset( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) {
			foreach ( $item['taxonomies'] as $taxonomy => $terms ) {
				if ( ! is_string( $taxonomy ) || ! is_array( $terms ) ) {
					continue;
				}
				$slugs = $this->term_slugs_from_payload( $terms );
				if ( empty( $slugs ) ) {
					continue;
				}
				$taxonomies[] = array(
					'taxonomy'      => $taxonomy,
					'added_terms'   => array_slice( $slugs, 0, self::TAX_TERM_SAMPLE ),
					'removed_terms' => array(),
					'more_added'    => max( 0, count( $slugs ) - self::TAX_TERM_SAMPLE ),
					'more_removed'  => 0,
				);
			}
		}

		$featured = $this->diff_featured_media( $item, 0 );

		return $this->assemble_changes(
			array(
				'rows'            => $core_rows,
				'unchanged_count' => 0,
				'truncated'       => false,
				'more'            => 0,
			),
			array(
				'rows'            => $acf_rows,
				'unchanged_count' => 0,
				'truncated'       => $acf_more > 0,
				'more'            => $acf_more,
			),
			array(
				'rows'            => $meta_rows,
				'unchanged_count' => 0,
				'truncated'       => $meta_total > count( $meta_rows ),
				'more'            => max( 0, $meta_total - count( $meta_rows ) ),
			),
			$taxonomies,
			$featured
		);
	}

	/**
	 * Assemble changes with summary counts.
	 *
	 * @param array      $core       Core section.
	 * @param array      $acf        ACF section.
	 * @param array      $meta       Meta section.
	 * @param array      $taxonomies Taxonomy diffs.
	 * @param array|null $featured   Featured media status or null.
	 * @return array
	 */
	private function assemble_changes( array $core, array $acf, array $meta, array $taxonomies, $featured ): array {
		// url_rewrite rows stay visible in the UI but do not inflate "will change" totals.
		$changed = $this->count_actionable_rows( $core )
			+ $this->count_actionable_rows( $acf )
			+ $this->count_actionable_rows( $meta )
			+ count( $taxonomies );

		// AttachmentRestorer only sets a thumbnail when the post has none; never clears/replaces.
		if ( is_array( $featured ) && isset( $featured['status'] ) && 'will_set' === $featured['status'] ) {
			++$changed;
		}

		return array(
			'core'           => $core,
			'acf'            => $acf,
			'meta'           => $meta,
			'taxonomies'     => $taxonomies,
			'featured_media' => $featured,
			'summary'        => array(
				'changed_count' => $changed,
				'field_count'   => count( $core['rows'] ?? array() ) + count( $acf['rows'] ?? array() ) + count( $meta['rows'] ?? array() ),
				'tax_count'     => count( $taxonomies ),
			),
		);
	}

	/**
	 * Count section rows that represent a real content change (not domain-only rewrite).
	 *
	 * @param array $section Diff section with rows.
	 * @return int
	 */
	private function count_actionable_rows( array $section ): int {
		$rows = ( isset( $section['rows'] ) && is_array( $section['rows'] ) ) ? $section['rows'] : array();
		$count = 0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( 'url_rewrite' === ( $row['status'] ?? '' ) ) {
				continue;
			}
			++$count;
		}
		return $count;
	}

	/**
	 * Diff core WP post fields.
	 *
	 * @param array    $item Archive item.
	 * @param \WP_Post $post Local post.
	 * @return array
	 */
	private function diff_core_fields( array $item, \WP_Post $post ): array {
		$map  = $this->core_field_map( $item, $post );
		$rows = array();
		$unchanged = 0;

		foreach ( $map as $field => $pair ) {
			$before = $pair['before'];
			$after  = $pair['after'];
			$status = $this->compare_values( $before, $after, $this->core_empty_means_removed( $field, $item ) );

			if ( 'unchanged' === $status ) {
				++$unchanged;
				continue;
			}

			$rows[] = array(
				'field'  => $field,
				'status' => $status,
			);
		}

		return array(
			'rows'            => $rows,
			'unchanged_count' => $unchanged,
			'truncated'       => false,
			'more'            => 0,
		);
	}

	/**
	 * Map core fields before/after.
	 *
	 * @param array         $item Archive item.
	 * @param \WP_Post|null $post Local post or null for create.
	 * @return array<string, array{before:mixed,after:mixed}>
	 */
	private function core_field_map( array $item, $post ): array {
		$parent_slug = '';
		if ( $post instanceof \WP_Post && $post->post_parent > 0 ) {
			$parent = get_post( (int) $post->post_parent );
			if ( $parent instanceof \WP_Post ) {
				$parent_slug = (string) $parent->post_name;
			}
		}

		return array(
			'title'          => array(
				'before' => $post instanceof \WP_Post ? (string) $post->post_title : '',
				'after'  => isset( $item['title'] ) ? (string) $item['title'] : '',
			),
			'status'         => array(
				'before' => $post instanceof \WP_Post ? (string) $post->post_status : '',
				// Importer defaults a missing status to publish.
				'after'  => array_key_exists( 'status', $item ) ? (string) $item['status'] : 'publish',
			),
			'excerpt'        => array(
				'before' => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
				'after'  => isset( $item['excerpt'] ) ? (string) $item['excerpt'] : '',
			),
			'content'        => array(
				'before' => $post instanceof \WP_Post ? (string) $post->post_content : '',
				'after'  => isset( $item['content'] ) ? (string) $item['content'] : '',
			),
			'parent_slug'    => array(
				'before' => $parent_slug,
				'after'  => isset( $item['parent_slug'] ) ? (string) $item['parent_slug'] : '',
			),
			'menu_order'     => array(
				'before' => $post instanceof \WP_Post ? (string) (int) $post->menu_order : '',
				'after'  => isset( $item['menu_order'] ) ? (string) (int) $item['menu_order'] : '',
			),
			'comment_status' => array(
				'before' => $post instanceof \WP_Post ? (string) $post->comment_status : '',
				'after'  => isset( $item['comment_status'] ) ? (string) $item['comment_status'] : '',
			),
			'ping_status'    => array(
				'before' => $post instanceof \WP_Post ? (string) $post->ping_status : '',
				'after'  => isset( $item['ping_status'] ) ? (string) $item['ping_status'] : '',
			),
		);
	}

	/**
	 * Diff ACF fields for a post.
	 *
	 * Loads local values with get_fields() (formatted) to match Selected Content
	 * export via BatchLoader. Media-like values are compared by filename fingerprint
	 * so cross-site attachment ID / URL remaps do not inflate "changed" totals.
	 *
	 * @param array $item    Archive item.
	 * @param int   $post_id Local post ID.
	 * @return array
	 */
	private function diff_acf_fields( array $item, int $post_id ): array {
		$incoming = ( isset( $item['acf_fields'] ) && is_array( $item['acf_fields'] ) ) ? $item['acf_fields'] : array();
		$empty    = array( 'rows' => array(), 'unchanged_count' => 0, 'truncated' => false, 'more' => 0 );
		if ( empty( $incoming ) || ! function_exists( 'get_fields' ) ) {
			return $empty;
		}

		$local = get_fields( $post_id );
		if ( ! is_array( $local ) ) {
			$local = array();
		}

		return $this->diff_acf_maps( $incoming, $local );
	}

	/**
	 * Diff two ACF field maps.
	 *
	 * @param array $incoming Archive fields.
	 * @param array $local    Local fields.
	 * @return array
	 */
	private function diff_acf_maps( array $incoming, array $local ): array {
		$priority = array(
			'changed'     => array(),
			'added'       => array(),
			'url_rewrite' => array(),
		);
		$unchanged = 0;

		foreach ( $incoming as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( ! array_key_exists( $name, $local ) ) {
				$priority['added'][] = array(
					'field'  => $name,
					'status' => 'added',
				);
				continue;
			}
			$status = $this->compare_acf_values( $local[ $name ], $value );
			if ( 'unchanged' === $status ) {
				++$unchanged;
				continue;
			}
			$bucket = ( 'url_rewrite' === $status ) ? 'url_rewrite' : 'changed';
			$priority[ $bucket ][] = array(
				'field'  => $name,
				'status' => $status,
			);
		}

		$rows = array_merge( $priority['changed'], $priority['added'], $priority['url_rewrite'] );
		$more = max( 0, count( $rows ) - self::ACF_CAP );
		$rows = array_slice( $rows, 0, self::ACF_CAP );

		return array(
			'rows'            => $rows,
			'unchanged_count' => $unchanged,
			'truncated'       => $more > 0,
			'more'            => $more,
		);
	}

	/**
	 * Compare ACF values with media-aware fingerprinting before generic compare.
	 *
	 * @param mixed $before Local value.
	 * @param mixed $after  Archive value.
	 * @return string added|changed|url_rewrite|removed|unchanged
	 */
	private function compare_acf_values( $before, $after ): string {
		$media_status = $this->compare_as_media_values( $before, $after );
		if ( null !== $media_status ) {
			return $media_status;
		}

		return $this->compare_values( $before, $after, false );
	}

	/**
	 * Compare image/file/gallery-shaped values by attachment filename fingerprints.
	 *
	 * Returns null when either side is not media-shaped so callers fall through
	 * to generic compare (text, repeaters, relationships, etc.).
	 *
	 * @param mixed $before Local value.
	 * @param mixed $after  Archive value.
	 * @return string|null unchanged|changed|url_rewrite|added|removed, or null.
	 */
	private function compare_as_media_values( $before, $after ) {
		$before_fps = $this->media_fingerprints( $before, 'local' );
		$after_fps  = $this->media_fingerprints( $after, 'archive' );
		if ( null === $before_fps || null === $after_fps ) {
			return null;
		}

		$before_empty = array() === $before_fps;
		$after_empty  = array() === $after_fps;
		if ( $before_empty && $after_empty ) {
			return 'unchanged';
		}
		if ( $before_empty && ! $after_empty ) {
			return 'added';
		}
		if ( ! $before_empty && $after_empty ) {
			return 'removed';
		}

		sort( $before_fps );
		sort( $after_fps );
		if ( $before_fps === $after_fps ) {
			return 'unchanged';
		}

		return 'changed';
	}

	/**
	 * Build sorted filename fingerprints for media-shaped ACF values.
	 *
	 * @param mixed  $value  Field value.
	 * @param string $side   local|archive (controls ID → filename resolution).
	 * @return string[]|null Fingerprints, or null when value is not media-shaped.
	 */
	private function media_fingerprints( $value, string $side ) {
		if ( null === $value || false === $value || '' === $value ) {
			// Empty scalar: treat as empty media only when paired with media on the other
			// side — callers require both sides non-null. Empty alone is ambiguous.
			return array();
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$id = (int) $value;
			if ( $id <= 0 ) {
				return array();
			}
			$fp = $this->filename_for_attachment_id( $id, $side );
			return '' !== $fp ? array( $fp ) : array( 'id:' . $id );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( array() === $value ) {
			return array();
		}

		// Formatted single image/file object.
		if ( $this->is_acf_media_object( $value ) ) {
			$fp = $this->fingerprint_from_media_object( $value, $side );
			return '' !== $fp ? array( $fp ) : array();
		}

		// Gallery / multi-file: list of IDs or media objects.
		if ( $this->is_list_array( $value ) ) {
			$fps     = array();
			$saw_media = false;
			foreach ( $value as $entry ) {
				if ( is_int( $entry ) || ( is_string( $entry ) && ctype_digit( $entry ) ) ) {
					$id = (int) $entry;
					if ( $id <= 0 ) {
						continue;
					}
					$saw_media = true;
					$fp        = $this->filename_for_attachment_id( $id, $side );
					$fps[]     = '' !== $fp ? $fp : ( 'id:' . $id );
					continue;
				}
				if ( is_array( $entry ) && $this->is_acf_media_object( $entry ) ) {
					$saw_media = true;
					$fp        = $this->fingerprint_from_media_object( $entry, $side );
					if ( '' !== $fp ) {
						$fps[] = $fp;
					}
					continue;
				}
				// Mixed / non-media list (e.g. relationship posts).
				return null;
			}
			return $saw_media ? $fps : null;
		}

		return null;
	}

	/**
	 * Whether an array looks like a formatted ACF image/file object (not a relationship).
	 *
	 * @param array $value Candidate.
	 * @return bool
	 */
	private function is_acf_media_object( array $value ): bool {
		if ( isset( $value['post_type'] ) || isset( $value['post_title'] ) ) {
			return false;
		}
		$has_id = isset( $value['ID'] ) || isset( $value['id'] );
		if ( ! $has_id && empty( $value['filename'] ) && empty( $value['url'] ) ) {
			return false;
		}
		return isset( $value['filename'] )
			|| isset( $value['mime_type'] )
			|| isset( $value['sizes'] )
			|| isset( $value['url'] )
			|| isset( $value['filesize'] );
	}

	/**
	 * Whether array is a sequential list (0..n-1).
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private function is_list_array( array $value ): bool {
		$i = 0;
		foreach ( $value as $key => $_unused ) {
			if ( $key !== $i ) {
				return false;
			}
			++$i;
		}
		return true;
	}

	/**
	 * Filename fingerprint from a formatted media object.
	 *
	 * @param array  $object Media object.
	 * @param string $side   local|archive.
	 * @return string
	 */
	private function fingerprint_from_media_object( array $object, string $side ): string {
		if ( ! empty( $object['filename'] ) && is_string( $object['filename'] ) ) {
			return strtolower( wp_basename( $object['filename'] ) );
		}
		if ( ! empty( $object['url'] ) && is_string( $object['url'] ) ) {
			return strtolower( wp_basename( (string) wp_parse_url( $object['url'], PHP_URL_PATH ) ) );
		}
		$id = 0;
		if ( isset( $object['ID'] ) ) {
			$id = (int) $object['ID'];
		} elseif ( isset( $object['id'] ) ) {
			$id = (int) $object['id'];
		}
		if ( $id > 0 ) {
			$fp = $this->filename_for_attachment_id( $id, $side );
			return '' !== $fp ? $fp : ( 'id:' . $id );
		}
		return '';
	}

	/**
	 * Resolve attachment ID to a lowercase basename.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $side          local|archive.
	 * @return string
	 */
	private function filename_for_attachment_id( int $attachment_id, string $side ): string {
		if ( 'archive' === $side && isset( $this->media_by_id[ $attachment_id ]['filename'] ) ) {
			return strtolower( wp_basename( (string) $this->media_by_id[ $attachment_id ]['filename'] ) );
		}

		$file = get_attached_file( $attachment_id );
		if ( is_string( $file ) && '' !== $file ) {
			return strtolower( wp_basename( $file ) );
		}

		if ( 'archive' === $side && isset( $this->media_by_id[ $attachment_id ]['source_url'] ) ) {
			$path = (string) wp_parse_url( (string) $this->media_by_id[ $attachment_id ]['source_url'], PHP_URL_PATH );
			if ( '' !== $path ) {
				return strtolower( wp_basename( $path ) );
			}
		}

		return '';
	}

	/**
	 * Diff non-ACF meta keys present in the archive.
	 *
	 * @param array $item    Archive item.
	 * @param int   $post_id Local post ID.
	 * @return array
	 */
	private function diff_meta_fields( array $item, int $post_id ): array {
		$incoming   = ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) ? $item['meta'] : array();
		$acf_fields = ( isset( $item['acf_fields'] ) && is_array( $item['acf_fields'] ) ) ? $item['acf_fields'] : array();
		$skip       = $this->acf_meta_skip_keys( $acf_fields );

		$priority = array(
			'changed'     => array(),
			'added'       => array(),
			'url_rewrite' => array(),
		);
		$unchanged = 0;

		foreach ( $incoming as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}
			if ( isset( $skip[ $key ] ) || $this->is_skipped_meta_key( $key ) ) {
				continue;
			}
			if ( is_string( $value ) && 0 === strpos( $value, 'field_' ) ) {
				continue;
			}

			$local_raw = get_post_meta( $post_id, $key, false );
			if ( empty( $local_raw ) ) {
				$priority['added'][] = array(
					'field'  => $key,
					'status' => 'added',
				);
				continue;
			}

			$local_value = ( 1 === count( $local_raw ) ) ? maybe_unserialize( $local_raw[0] ) : array_map( 'maybe_unserialize', $local_raw );
			$status      = $this->compare_values( $local_value, $value, false );
			if ( 'unchanged' === $status ) {
				++$unchanged;
				continue;
			}
			$bucket = ( 'url_rewrite' === $status ) ? 'url_rewrite' : 'changed';
			$priority[ $bucket ][] = array(
				'field'  => $key,
				'status' => $status,
			);
		}

		$rows = array_merge( $priority['changed'], $priority['added'], $priority['url_rewrite'] );
		$more = max( 0, count( $rows ) - self::META_CAP );
		$rows = array_slice( $rows, 0, self::META_CAP );

		return array(
			'rows'            => $rows,
			'unchanged_count' => $unchanged,
			'truncated'       => $more > 0,
			'more'            => $more,
		);
	}

	/**
	 * Diff taxonomy term slugs.
	 *
	 * @param array $item    Archive item.
	 * @param int   $post_id Local post ID.
	 * @return array
	 */
	private function diff_taxonomies( array $item, int $post_id ): array {
		$incoming = ( isset( $item['taxonomies'] ) && is_array( $item['taxonomies'] ) ) ? $item['taxonomies'] : array();
		$result   = array();

		foreach ( $incoming as $taxonomy => $terms ) {
			if ( ! is_string( $taxonomy ) || ! is_array( $terms ) ) {
				continue;
			}

			// ImportHandler skips post_translations (Polylang manages them separately).
			if ( 'post_translations' === $taxonomy ) {
				continue;
			}

			$archive_slugs = $this->term_slugs_from_payload( $terms );
			// Empty archive term lists do not clear local terms (importer only
			// calls wp_set_object_terms when it resolved at least one term id).
			if ( empty( $archive_slugs ) ) {
				continue;
			}

			$local_slugs = array();
			if ( taxonomy_exists( $taxonomy ) ) {
				$local_terms = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'slugs' ) );
				if ( ! is_wp_error( $local_terms ) && is_array( $local_terms ) ) {
					$local_slugs = array_map( 'strval', $local_terms );
				}
			}

			sort( $archive_slugs );
			sort( $local_slugs );
			$added   = array_values( array_diff( $archive_slugs, $local_slugs ) );
			$removed = array_values( array_diff( $local_slugs, $archive_slugs ) );
			if ( empty( $added ) && empty( $removed ) ) {
				continue;
			}

			$result[] = array(
				'taxonomy'      => $taxonomy,
				'added_terms'   => array_slice( $added, 0, self::TAX_TERM_SAMPLE ),
				'removed_terms' => array_slice( $removed, 0, self::TAX_TERM_SAMPLE ),
				'more_added'    => max( 0, count( $added ) - self::TAX_TERM_SAMPLE ),
				'more_removed'  => max( 0, count( $removed ) - self::TAX_TERM_SAMPLE ),
			);
		}

		return $result;
	}

	/**
	 * Featured media status by filename (not numeric ID).
	 *
	 * Matches AttachmentRestorer::maybe_update_thumbnail(): the importer only
	 * sets a featured image when the local post has none. It never clears or
	 * replaces an existing thumbnail.
	 *
	 * @param array $item    Archive item.
	 * @param int   $post_id Local post ID (0 for create).
	 * @return array|null
	 */
	private function diff_featured_media( array $item, int $post_id ) {
		$has_archive = array_key_exists( 'featured_media', $item );
		$archive_id  = $has_archive ? (int) $item['featured_media'] : 0;

		$archive_filename = '';
		$status_hint      = '';
		if ( $archive_id > 0 ) {
			if ( isset( $this->media_by_id[ $archive_id ]['filename'] ) ) {
				$archive_filename = (string) $this->media_by_id[ $archive_id ]['filename'];
			} else {
				$status_hint = 'unknown';
			}
		}

		$local_filename = '';
		$local_thumb_id = 0;
		if ( $post_id > 0 ) {
			$local_thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( $local_thumb_id > 0 ) {
				$file = get_attached_file( $local_thumb_id );
				if ( is_string( $file ) && '' !== $file ) {
					$local_filename = wp_basename( $file );
				}
			}
		}

		if ( ! $has_archive && 0 === $post_id ) {
			return null;
		}

		if ( $archive_id <= 0 && $local_thumb_id <= 0 ) {
			return array(
				'status'           => 'unchanged',
				'archive_filename' => '',
				'local_filename'   => '',
			);
		}

		// Local thumbnail present: importer keeps it (no clear, no replace).
		if ( $local_thumb_id > 0 ) {
			if ( $archive_id > 0 && '' !== $archive_filename && $archive_filename === $local_filename ) {
				return array(
					'status'           => 'same_file',
					'archive_filename' => $archive_filename,
					'local_filename'   => $local_filename,
				);
			}
			return array(
				'status'           => 'local_kept',
				'archive_filename' => $archive_filename,
				'local_filename'   => $local_filename,
			);
		}

		// No local thumbnail: importer may set one when archive provides a mappable ID.
		if ( $archive_id > 0 ) {
			return array(
				'status'           => '' !== $status_hint ? $status_hint : 'will_set',
				'archive_filename' => $archive_filename,
				'local_filename'   => '',
			);
		}

		return array(
			'status'           => 'unchanged',
			'archive_filename' => '',
			'local_filename'   => '',
		);
	}

	/**
	 * Whether an empty archive value will overwrite the local field on import.
	 *
	 * Matches ImportHandler::prepare_post_data(): title, content, and excerpt are
	 * always written (missing excerpt becomes an empty string). Status defaults to
	 * publish when omitted. menu_order, comment_status, and ping_status are written
	 * only when present. An empty parent_slug does not clear the local parent.
	 *
	 * @param string $field Field key.
	 * @param array  $item  Archive item.
	 * @return bool
	 */
	private function core_empty_means_removed( string $field, array $item ): bool {
		if ( in_array( $field, array( 'title', 'content', 'excerpt' ), true ) ) {
			return true;
		}

		if ( in_array( $field, array( 'menu_order', 'comment_status', 'ping_status', 'status' ), true ) ) {
			return array_key_exists( $field, $item );
		}

		return false;
	}

	/**
	 * Compare two values with URL-rewrite awareness.
	 *
	 * @param mixed $before       Local value.
	 * @param mixed $after        Archive value.
	 * @param bool  $allow_empty  Whether empty archive value counts as removed.
	 * @return string added|changed|url_rewrite|removed|unchanged
	 */
	private function compare_values( $before, $after, bool $allow_empty ): string {
		$before_empty = $this->is_empty_value( $before );
		$after_empty  = $this->is_empty_value( $after );

		if ( $before_empty && $after_empty ) {
			return 'unchanged';
		}
		if ( $before_empty && ! $after_empty ) {
			return 'added';
		}
		if ( ! $before_empty && $after_empty ) {
			return $allow_empty ? 'removed' : 'unchanged';
		}

		$norm_before = $this->normalize_for_compare( $before );
		$norm_after  = $this->normalize_for_compare( $after );
		if ( $norm_before === $norm_after ) {
			return 'unchanged';
		}

		$rewritten_after = $this->rewrite_origins_in_value( $after );
		$norm_rewritten  = $this->normalize_for_compare( $rewritten_after );
		if ( $norm_before === $norm_rewritten ) {
			return 'url_rewrite';
		}

		return 'changed';
	}

	/**
	 * Normalize a value for equality compare.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function normalize_for_compare( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $this->canonicalize( $value ) );
		}
		if ( is_object( $value ) ) {
			return (string) wp_json_encode( $this->canonicalize( (array) $value ) );
		}
		return '';
	}

	/**
	 * Replace known source origins with the target origin inside strings/arrays.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function rewrite_origins_in_value( $value ) {
		if ( empty( $this->source_origins ) || '' === $this->target_origin ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$out = $value;
			foreach ( $this->source_origins as $origin ) {
				if ( $origin === $this->target_origin ) {
					continue;
				}
				$out = str_replace( $origin, $this->target_origin, $out );
			}
			return $out;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = $this->rewrite_origins_in_value( $v );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Sort keys recursively for stable JSON compare.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function canonicalize( array $data ): array {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->canonicalize( $v );
			}
		}
		ksort( $data );
		return $data;
	}

	/**
	 * Whether a value is empty for import purposes.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_empty_value( $value ): bool {
		if ( is_null( $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return '' === $value;
		}
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return false;
	}

	/**
	 * Meta keys covered by ACF (name + _{name}).
	 *
	 * @param array $acf_fields ACF map.
	 * @return array<string,bool>
	 */
	private function acf_meta_skip_keys( array $acf_fields ): array {
		$skip = array();
		foreach ( array_keys( $acf_fields ) as $name ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			$skip[ $name ]       = true;
			$skip[ '_' . $name ] = true;
		}
		return $skip;
	}

	/**
	 * Skip noisy/internal meta keys.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	private function is_skipped_meta_key( string $key ): bool {
		return in_array( $key, array( '_edit_lock', '_edit_last', '_thumbnail_id' ), true );
	}

	/**
	 * Extract term slugs from taxonomy payload.
	 *
	 * @param array $terms Terms list.
	 * @return string[]
	 */
	private function term_slugs_from_payload( array $terms ): array {
		$slugs = array();
		foreach ( $terms as $term ) {
			if ( is_string( $term ) && '' !== $term ) {
				$slugs[] = sanitize_title( $term );
				continue;
			}
			if ( is_array( $term ) && isset( $term['slug'] ) ) {
				$slug = sanitize_title( (string) $term['slug'] );
				if ( '' !== $slug ) {
					$slugs[] = $slug;
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Scheme + host (+ port) origin from a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_origin( string $url ): string {
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . $host . $port;
	}
}
