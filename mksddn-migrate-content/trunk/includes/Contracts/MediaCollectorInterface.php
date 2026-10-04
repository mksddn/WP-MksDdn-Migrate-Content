<?php
/**
 * @file: MediaCollectorInterface.php
 * @description: Contract for media collection operations
 * @dependencies: Media\AttachmentCollection
 * @created: 2024-01-01
 */

namespace MksDdn\MigrateContent\Contracts;

use MksDdn\MigrateContent\Media\AttachmentCollection;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contract for media collection operations.
 *
 * @since 1.0.0
 */
interface MediaCollectorInterface {

	/**
	 * Collect attachments for a post.
	 *
	 * @param WP_Post $post Post instance.
	 * @return AttachmentCollection|null Collection or null if no attachments found.
	 * @since 1.0.0
	 */
	public function collect_for_post( WP_Post $post ): ?AttachmentCollection;

	/**
	 * Collect attachments by known attachment IDs (e.g. from ACF Options Page fields).
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $parent_id      Context parent ID for the media manifest (0 for options pages).
	 * @return AttachmentCollection|null Collection or null if no attachments found.
	 * @since 2.8.0
	 */
	public function collect_from_attachment_ids( array $attachment_ids, int $parent_id = 0 ): ?AttachmentCollection;

	/**
	 * Extract attachment IDs from ACF field values using field types.
	 *
	 * Bare integers are attachment IDs only for image, file, and gallery fields.
	 * Number, relationship, and other scalar fields are ignored. WYSIWYG and
	 * textarea values are scanned for embedded media markup.
	 *
	 * @param mixed $value        ACF field value tree (name => value for a field map).
	 * @param array $field_schema Optional schema from OptionsHelper::format_options_page_export().
	 * @return int[]
	 * @since 2.8.0
	 */
	public function extract_attachment_ids_from_acf_values( $value, array $field_schema = array() ): array;
}

