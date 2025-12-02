<?php
/**
 * Restores attachments during import.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Media;

use WP_Error;
use WP_Post;
use WP_Query;
use const \ABSPATH;
use function \delete_post_meta;
use function \get_post;
use function \get_post_meta;
use function \get_post_thumbnail_id;
use function \sanitize_file_name;
use function \sanitize_text_field;
use function \sanitize_textarea_field;
use function \set_post_thumbnail;
use function \update_post_meta;
use function \wp_get_attachment_url;
use function \wp_reset_postdata;
use function \wp_update_post;
use function \media_handle_sideload;
use function \is_wp_error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles attachment restoration and content rewrites.
 */
class AttachmentRestorer {

	/**
	 * Restore attachments and update references.
	 *
	 * @param array    $entries    Attachment manifest entries.
	 * @param callable $file_loader Callback that accepts archive path and returns local temp path|WP_Error.
	 * @param int      $parent_id  Newly created/updated post ID.
	 * @return array{
	 *     url_map: array<string,string>,
	 *     id_map: array<int,int>
	 * }
	 */
	public function restore( array $entries, callable $file_loader, int $parent_id ): array {
		if ( empty( $entries ) ) {
			return array(
				'url_map' => array(),
				'id_map'  => array(),
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$url_map = array();
		$id_map  = array();

		foreach ( $entries as $entry ) {
			$checksum = $entry['checksum'] ?? '';
			$archive  = $entry['archive_path'] ?? '';

			if ( '' === $checksum || '' === $archive ) {
				continue;
			}

			$existing = $this->find_existing_attachment( $checksum );
			if ( $existing ) {
				$id_map[ (int) $entry['original_id'] ]    = $existing;
				$url_map[ (string) ( $entry['source_url'] ?? '' ) ] = wp_get_attachment_url( $existing );
				continue;
			}

			$file_path = $file_loader( $archive );
			if ( is_wp_error( $file_path ) ) {
				continue;
			}

			$attachment_id = $this->sideload_attachment( $file_path, $entry, $parent_id );
			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $file_path );
				continue;
			}

			update_post_meta( $attachment_id, '_mksddn_mc_checksum', $checksum );

			$id_map[ (int) $entry['original_id'] ]    = $attachment_id;
			$url_map[ (string) ( $entry['source_url'] ?? '' ) ] = wp_get_attachment_url( $attachment_id );
		}

		$this->update_content_references( $parent_id, $url_map, $id_map );
		$this->maybe_update_thumbnail( $parent_id, $id_map );

		return array(
			'url_map' => $url_map,
			'id_map'  => $id_map,
		);
	}

	/**
	 * Try to find existing attachment by checksum.
	 *
	 * @param string $checksum SHA-256.
	 * @return int|null
	 */
	private function find_existing_attachment( string $checksum ): ?int {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => '_mksddn_mc_checksum',
						'value' => $checksum,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			wp_reset_postdata();
			return null;
		}

		$attachment_id = (int) $query->posts[0];
		wp_reset_postdata();

		return $attachment_id;
	}

	/**
	 * Handle sideloading of attachment.
	 *
	 * @param string $file_path Temp file path.
	 * @param array  $entry     Manifest entry.
	 * @param int    $parent_id Parent post ID.
	 * @return int|WP_Error
	 */
	private function sideload_attachment( string $file_path, array $entry, int $parent_id ) {
		$file_array = array(
			'name'     => sanitize_file_name( $entry['filename'] ?? basename( $file_path ) ),
			'tmp_name' => $file_path,
			'size'     => filesize( $file_path ),
		);

		$description = sanitize_textarea_field( $entry['description'] ?? '' );

		$attachment_id = media_handle_sideload( $file_array, $parent_id, $description );

		if ( ! is_wp_error( $attachment_id ) ) {
			if ( ! empty( $entry['alt'] ) ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $entry['alt'] ) );
			}

			$post_data = array(
				'ID'           => $attachment_id,
				'post_title'   => sanitize_text_field( $entry['title'] ?? '' ),
				'post_excerpt' => sanitize_textarea_field( $entry['caption'] ?? '' ),
			);

			wp_update_post( $post_data );
			if ( file_exists( $file_path ) ) {
				@unlink( $file_path );
			}
		}

		return $attachment_id;
	}

	/**
	 * Replace old URLs/IDs inside post content.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $url_map Original URL => new URL.
	 * @param array $id_map  Original ID => new ID.
	 */
	private function update_content_references( int $post_id, array $url_map, array $id_map ): void {
		if ( empty( $url_map ) && empty( $id_map ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$content = $post->post_content;
		$excerpt = $post->post_excerpt;

		foreach ( $url_map as $old => $new ) {
			if ( '' === $old || '' === $new ) {
				continue;
			}
			$content = str_replace( $old, $new, $content );
			$excerpt = str_replace( $old, $new, $excerpt );
		}

		foreach ( $id_map as $old_id => $new_id ) {
			$content = preg_replace( '/wp-image-' . $old_id . '\b/', 'wp-image-' . $new_id, $content );
		}

		if ( $content !== $post->post_content || $excerpt !== $post->post_excerpt ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				)
			);
		}
	}

	/**
	 * Update featured image mapping if present in payload.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $id_map  Original => new attachment IDs.
	 */
	private function maybe_update_thumbnail( int $post_id, array $id_map ): void {
		$current = get_post_thumbnail_id( $post_id );
		if ( $current ) {
			return;
		}

		$original_thumbnail = get_post_meta( $post_id, '_mksddn_original_thumbnail', true );
		if ( ! $original_thumbnail ) {
			return;
		}

		$original_thumbnail = (int) $original_thumbnail;
		if ( isset( $id_map[ $original_thumbnail ] ) ) {
			set_post_thumbnail( $post_id, $id_map[ $original_thumbnail ] );
		}

		delete_post_meta( $post_id, '_mksddn_original_thumbnail' );
	}
}

