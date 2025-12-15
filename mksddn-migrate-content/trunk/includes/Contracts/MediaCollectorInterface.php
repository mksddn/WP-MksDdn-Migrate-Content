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
}

