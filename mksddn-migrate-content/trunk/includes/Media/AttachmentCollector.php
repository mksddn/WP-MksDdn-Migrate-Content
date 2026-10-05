<?php
/**
 * Attachment collector used during export.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Media;

use MksDdn\MigrateContent\Contracts\MediaCollectorInterface;
use MksDdn\MigrateContent\Core\BatchLoader;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans post content/meta to find referenced attachments.
 */
class AttachmentCollector implements MediaCollectorInterface {

	/**
	 * Batch loader for optimizing database queries.
	 *
	 * @var BatchLoader
	 */
	private BatchLoader $batch_loader;

	/**
	 * Constructor.
	 *
	 * @param BatchLoader|null $batch_loader Optional batch loader.
	 */
	public function __construct( ?BatchLoader $batch_loader = null ) {
		$this->batch_loader = $batch_loader ?? new BatchLoader();
	}

	/**
	 * Collect attachment data for the provided post.
	 *
	 * @param WP_Post $post Post or form.
	 * @return AttachmentCollection|null
	 */
	public function collect_for_post( WP_Post $post ): ?AttachmentCollection {
		return $this->collect_from_attachment_ids( $this->discover_attachment_ids( $post ), (int) $post->ID );
	}

	/**
	 * Collect attachments by known attachment IDs.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $parent_id      Context parent ID for the media manifest.
	 * @return AttachmentCollection|null
	 */
	public function collect_from_attachment_ids( array $attachment_ids, int $parent_id = 0 ): ?AttachmentCollection {
		$attachment_ids = array_values(
			array_filter(
				array_unique( array_map( 'absint', $attachment_ids ) ),
				static fn( $value ) => $value > 0
			)
		);

		if ( empty( $attachment_ids ) ) {
			return null;
		}

		$this->batch_loader->load_attachments_batch( $attachment_ids );

		$collection = new AttachmentCollection();

		foreach ( $attachment_ids as $attachment_id ) {
			$entry = $this->build_manifest_entry( $attachment_id, $parent_id );
			if ( ! $entry ) {
				continue;
			}

			$asset_entry = array(
				'source' => $entry['absolute_path'],
				'target' => $entry['archive_path'],
			);

			unset( $entry['absolute_path'] );

			$collection->add(
				$entry,
				$asset_entry
			);
		}

		return $collection->has_items() ? $collection : null;
	}

	/**
	 * Extract attachment IDs from ACF field values using field types.
	 *
	 * Bare integers count only for image, file, and gallery. Other numeric
	 * values (number, relationship, true/false) are ignored even when an
	 * attachment with the same ID exists. Without a schema, bare integers
	 * are ignored as well.
	 *
	 * @param mixed $value        ACF field value tree.
	 * @param array $field_schema Name => schema (type, sub_fields, layouts).
	 * @return int[]
	 */
	public function extract_attachment_ids_from_acf_values( $value, array $field_schema = array() ): array {
		$ids = array();

		if ( is_array( $value ) && array() !== $field_schema ) {
			$this->walk_named_acf_fields_for_attachment_ids( $value, $field_schema, $ids );
		} else {
			$this->walk_acf_value_for_embedded_media( $value, $ids );
		}

		$candidates = array_values(
			array_filter(
				array_unique( array_map( 'absint', $ids ) ),
				static fn( $id ) => $id > 0
			)
		);

		return $this->filter_existing_attachment_ids( $candidates );
	}

	/**
	 * Walk a name => value map using the matching field schema.
	 *
	 * @param array $values Value map.
	 * @param array $schema Schema map keyed by field name.
	 * @param int[] $ids    Accumulator (by reference).
	 * @return void
	 */
	private function walk_named_acf_fields_for_attachment_ids( array $values, array $schema, array &$ids ): void {
		foreach ( $values as $name => $child ) {
			if ( 'acf_fc_layout' === (string) $name ) {
				continue;
			}
			$field_schema = ( isset( $schema[ $name ] ) && is_array( $schema[ $name ] ) ) ? $schema[ $name ] : array();
			$this->walk_acf_field_for_attachment_ids( $child, $field_schema, $ids );
		}
	}

	/**
	 * Collect attachment IDs from one field value according to its type.
	 *
	 * @param mixed $value  Field value.
	 * @param array $schema Field schema node.
	 * @param int[] $ids    Accumulator (by reference).
	 * @return void
	 */
	private function walk_acf_field_for_attachment_ids( $value, array $schema, array &$ids ): void {
		$type = (string) ( $schema['type'] ?? '' );

		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			$this->collect_attachment_leaf_id( $value, $ids );
			return;
		}

		if ( 'gallery' === $type ) {
			$this->collect_gallery_attachment_ids( $value, $ids );
			return;
		}

		if ( in_array( $type, array( 'wysiwyg', 'textarea' ), true ) ) {
			if ( is_string( $value ) ) {
				$this->collect_embedded_media_ids_from_string( $value, $ids );
			}
			return;
		}

		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			$this->walk_named_acf_fields_for_attachment_ids( $value, $sub, $ids );
			return;
		}

		if ( 'repeater' === $type && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			foreach ( $value as $row ) {
				if ( is_array( $row ) ) {
					$this->walk_named_acf_fields_for_attachment_ids( $row, $sub, $ids );
				}
			}
			return;
		}

		if ( 'flexible_content' === $type && is_array( $value ) ) {
			$layouts = ( isset( $schema['layouts'] ) && is_array( $schema['layouts'] ) ) ? $schema['layouts'] : array();
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$layout_name = isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
				$sub         = ( isset( $layouts[ $layout_name ]['sub_fields'] ) && is_array( $layouts[ $layout_name ]['sub_fields'] ) )
					? $layouts[ $layout_name ]['sub_fields']
					: array();
				$this->walk_named_acf_fields_for_attachment_ids( $row, $sub, $ids );
			}
			return;
		}

		// Unknown type: embedded markup and attachment arrays only, never bare integers.
		$this->walk_acf_value_for_embedded_media( $value, $ids );
	}

	/**
	 * Collect one image/file value (ID, attachment array, or uploads URL).
	 *
	 * @param mixed $value Field value.
	 * @param int[] $ids   Accumulator (by reference).
	 * @return void
	 */
	private function collect_attachment_leaf_id( $value, array &$ids ): void {
		if ( is_numeric( $value ) ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			return;
		}

		if ( is_string( $value ) ) {
			$this->collect_attachment_id_from_url( $value, $ids );
			return;
		}

		if ( ! is_array( $value ) || ! $this->is_acf_attachment_value_array( $value ) ) {
			return;
		}

		if ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) ) {
			$ids[] = (int) $value['ID'];
		} elseif ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
			$ids[] = (int) $value['id'];
		} elseif ( isset( $value['url'] ) && is_string( $value['url'] ) ) {
			$this->collect_attachment_id_from_url( $value['url'], $ids );
		}
	}

	/**
	 * Collect IDs from a gallery value (list of IDs, arrays, or a comma-separated string).
	 *
	 * @param mixed $value Gallery value.
	 * @param int[] $ids   Accumulator (by reference).
	 * @return void
	 */
	private function collect_gallery_attachment_ids( $value, array &$ids ): void {
		if ( is_string( $value ) ) {
			if ( preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $value ) ) {
				foreach ( explode( ',', $value ) as $part ) {
					$id = (int) trim( $part );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
				return;
			}
			$this->collect_attachment_leaf_id( $value, $ids );
			return;
		}

		if ( ! is_array( $value ) ) {
			$this->collect_attachment_leaf_id( $value, $ids );
			return;
		}

		foreach ( $value as $item ) {
			$this->collect_attachment_leaf_id( $item, $ids );
		}
	}

	/**
	 * Scan a value tree for embedded media without treating bare integers as attachments.
	 *
	 * @param mixed $value Value node.
	 * @param int[] $ids   Accumulator (by reference).
	 * @return void
	 */
	private function walk_acf_value_for_embedded_media( $value, array &$ids ): void {
		if ( is_string( $value ) ) {
			$this->collect_embedded_media_ids_from_string( $value, $ids );
			return;
		}

		if ( ! is_array( $value ) ) {
			return;
		}

		if ( $this->is_acf_attachment_value_array( $value ) ) {
			$this->collect_attachment_leaf_id( $value, $ids );
			return;
		}

		foreach ( $value as $child ) {
			$this->walk_acf_value_for_embedded_media( $child, $ids );
		}
	}

	/**
	 * Pull attachment IDs out of HTML, gallery shortcodes, and uploads URLs.
	 *
	 * @param string $value Content string.
	 * @param int[]  $ids   Accumulator (by reference).
	 * @return void
	 */
	private function collect_embedded_media_ids_from_string( string $value, array &$ids ): void {
		if ( '' === $value ) {
			return;
		}

		foreach ( $this->parse_content_for_ids( $value ) as $content_id ) {
			$ids[] = (int) $content_id;
		}
		foreach ( $this->parse_gallery_ids( $value ) as $gallery_id ) {
			$ids[] = (int) $gallery_id;
		}

		if ( preg_match_all( '#(?:https?:)?//[^\s"\']+/wp-content/uploads/[^\s"\']+#i', $value, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$this->collect_attachment_id_from_url( $url, $ids );
			}
		}
	}

	/**
	 * Resolve an uploads URL to an attachment ID.
	 *
	 * @param string $url URL candidate.
	 * @param int[]  $ids Accumulator (by reference).
	 * @return void
	 */
	private function collect_attachment_id_from_url( string $url, array &$ids ): void {
		if ( '' === $url || false === strpos( $url, '/wp-content/uploads/' ) ) {
			return;
		}

		$url_id = attachment_url_to_postid( $url );
		if ( $url_id > 0 ) {
			$ids[] = $url_id;
		}
	}

	/**
	 * Keep only IDs that resolve to attachment posts.
	 *
	 * @param int[] $candidate_ids Candidate IDs.
	 * @return int[]
	 */
	private function filter_existing_attachment_ids( array $candidate_ids ): array {
		if ( empty( $candidate_ids ) ) {
			return array();
		}

		$this->batch_loader->load_attachments_batch( $candidate_ids );

		$result = array();
		foreach ( $candidate_ids as $attachment_id ) {
			$attachment = $this->batch_loader->get_attachment( (int) $attachment_id );
			if ( $attachment && 'attachment' === $attachment->post_type ) {
				$result[] = (int) $attachment_id;
			}
		}

		return $result;
	}

	/**
	 * Whether a value looks like an ACF image/file array return format.
	 *
	 * @param array $value Value node.
	 */
	private function is_acf_attachment_value_array( array $value ): bool {
		$has_id = ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) )
			|| ( isset( $value['id'] ) && is_numeric( $value['id'] ) );

		if ( ! $has_id ) {
			return false;
		}

		return isset( $value['url'] )
			|| isset( $value['filename'] )
			|| isset( $value['mime_type'] )
			|| isset( $value['sizes'] )
			|| isset( $value['type'] );
	}

	/**
	 * Discover attachment IDs referenced by the post.
	 *
	 * @param WP_Post $post Post instance.
	 * @return int[]
	 */
	private function discover_attachment_ids( WP_Post $post ): array {
		$ids = array();

		// Featured media - use batch loader.
		$thumbnail_id = $this->batch_loader->get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id ) {
			$ids[] = (int) $thumbnail_id;
		}

		// IDs embedded in content (wp-image-123, etc).
		$ids = array_merge( $ids, $this->parse_content_for_ids( $post->post_content ?? '' ) );

		// Gallery shortcode IDs.
		$ids = array_merge( $ids, $this->parse_gallery_ids( $post->post_content ?? '' ) );

		// Generic meta references (ACF image fields often store numeric IDs).
		$ids = array_merge( $ids, $this->probe_meta_for_attachments( $post->ID ) );

		$ids = array_filter(
			array_unique( array_map( 'absint', $ids ) ),
			static fn( $value ) => $value > 0
		);

		return $ids;
	}

	/**
	 * Extract attachment IDs from post content.
	 */
	private function parse_content_for_ids( string $content ): array {
		$matches = array();
		$ids     = array();

		$patterns = array(
			'/wp-image-([0-9]+)/i',
			'/data-id="([0-9]+)"/i',
			'/attachment_([0-9]+)/i',
			'/\"id\":\s*([0-9]+)/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $content, $matches ) ) {
				$ids = array_merge( $ids, $matches[1] );
			}
		}

		return $ids;
	}

	/**
	 * Parse `[gallery ids="1,2,3"]` blocks.
	 */
	private function parse_gallery_ids( string $content ): array {
		$matches = array();
		if ( ! preg_match_all( '/\[gallery[^\]]*ids="([^"]+)"/i', $content, $matches ) ) {
			return array();
		}

		$ids = array();
		foreach ( $matches[1] as $group ) {
			$ids = array_merge( $ids, array_map( 'trim', explode( ',', $group ) ) );
		}

		return $ids;
	}

	/**
	 * Attempt to detect attachment IDs from meta (basic heuristics).
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	private function probe_meta_for_attachments( int $post_id ): array {
		// Use batch loader for optimized queries.
		$meta          = $this->batch_loader->get_post_meta( $post_id );
		$potential_ids = array();

		foreach ( $meta as $values ) {
			foreach ( (array) $values as $value ) {
				if ( is_numeric( $value ) ) {
					$potential_ids[] = (int) $value;
				}
			}
		}

		return $this->filter_existing_attachment_ids( $potential_ids );
	}

	/**
	 * Build manifest entry for attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $parent_id     Parent post ID (for context).
	 * @return array|null
	 */
	private function build_manifest_entry( int $attachment_id, int $parent_id ): ?array {
		// Use batch loader for optimized queries.
		$attachment = $this->batch_loader->get_attachment( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return null;
		}

		// Load meta for attachment in batch if needed.
		$attachment_meta = $this->batch_loader->get_post_meta( $attachment_id );

		$file_path = get_attached_file( $attachment_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return null;
		}

		$hash     = hash_file( 'sha256', $file_path );
		$filename = wp_basename( $file_path );
		$target   = 'media/' . $attachment_id . '-' . $filename;

		// Get alt text from cached meta.
		$alt_text = $attachment_meta['_wp_attachment_image_alt'] ?? '';

		return array(
			'original_id'   => $attachment_id,
			'parent'        => $parent_id,
			'filename'      => $filename,
			'mime_type'     => get_post_mime_type( $attachment_id ),
			'filesize'      => filesize( $file_path ),
			'checksum'      => $hash,
			'source_url'    => wp_get_attachment_url( $attachment_id ),
			'title'         => $attachment->post_title,
			'alt'           => is_string( $alt_text ) ? $alt_text : '',
			'caption'       => $attachment->post_excerpt,
			'description'   => $attachment->post_content,
			'archive_path'  => $target,
			'absolute_path' => $file_path,
		);
	}
}

