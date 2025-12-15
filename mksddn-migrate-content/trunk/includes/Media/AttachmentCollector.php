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
		$attachment_ids = $this->discover_attachment_ids( $post );

		if ( empty( $attachment_ids ) ) {
			return null;
		}

		// Preload all attachments in batch.
		$this->batch_loader->load_attachments_batch( $attachment_ids );

		$collection = new AttachmentCollection();

		foreach ( $attachment_ids as $attachment_id ) {
			$entry = $this->build_manifest_entry( $attachment_id, $post->ID );
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
		$meta   = $this->batch_loader->get_post_meta( $post_id );
		$result = array();
		$potential_ids = array();

		foreach ( $meta as $values ) {
			foreach ( (array) $values as $value ) {
				if ( is_numeric( $value ) ) {
					$potential_ids[] = (int) $value;
				}
			}
		}

		if ( ! empty( $potential_ids ) ) {
			// Load attachments in batch.
			$this->batch_loader->load_attachments_batch( $potential_ids );

			foreach ( $potential_ids as $attachment_id ) {
				$attachment = $this->batch_loader->get_attachment( $attachment_id );
				if ( $attachment && 'attachment' === $attachment->post_type ) {
					$result[] = $attachment_id;
				}
			}
		}

		return $result;
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

