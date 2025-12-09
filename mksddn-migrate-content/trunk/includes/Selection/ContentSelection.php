<?php
/**
 * Represents a user selection for export.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds export selections for posts/cpts/options/etc.
 */
class ContentSelection {

	/**
	 * List of post objects keyed by post type.
	 *
	 * @var array<string, int[]>
	 */
	private array $items = array();

	/**
	 * Option keys requested for export.
	 *
	 * @var string[]
	 */
	private array $options = array();

	/**
	 * Widget groups requested (widget_*).
	 *
	 * @var string[]
	 */
	private array $widgets = array();

	/**
	 * Record post ID for a given type.
	 *
	 * @param string $type    Post type.
	 * @param int    $item_id Post ID.
	 */
	public function add_item( string $type, int $item_id ): void {
		if ( 0 >= $item_id ) {
			return;
		}

		if ( ! isset( $this->items[ $type ] ) ) {
			$this->items[ $type ] = array();
		}

		if ( ! in_array( $item_id, $this->items[ $type ], true ) ) {
			$this->items[ $type ][] = $item_id;
		}
	}

	/**
	 * Get items map.
	 *
	 * @return array<string, int[]>
	 */
	public function get_items(): array {
		return $this->items;
	}

	/**
	 * Add option key for export.
	 *
	 * @param string $key Option key.
	 */
	public function add_option( string $key ): void {
		if ( '' === $key ) {
			return;
		}

		$this->options[] = sanitize_key( $key );
	}

	/**
	 * Get option keys.
	 *
	 * @return string[]
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Add widget group (e.g., widget_text).
	 *
	 * @param string $group Group key.
	 */
	public function add_widget_group( string $group ): void {
		if ( '' === $group ) {
			return;
		}

		$this->widgets[] = sanitize_key( $group );
	}

	/**
	 * Get widget groups.
	 *
	 * @return string[]
	 */
	public function get_widgets(): array {
		return $this->widgets;
	}

	/**
	 * Whether selection includes post-type items.
	 */
	public function has_items(): bool {
		return ! empty( $this->items );
	}

	/**
	 * Whether selection includes options/widgets.
	 */
	public function has_options(): bool {
		return ! empty( $this->options ) || ! empty( $this->widgets );
	}

	/**
	 * Total number of selected post objects.
	 */
	public function count_items(): int {
		$total = 0;
		foreach ( $this->items as $ids ) {
			$total += count( $ids );
		}

		return $total;
	}

	/**
	 * Get the first selected item reference.
	 *
	 * @return array|null { type, id } or null.
	 */
	public function first_item(): ?array {
		foreach ( $this->items as $type => $ids ) {
			foreach ( $ids as $id ) {
				return array(
					'type' => $type,
					'id'   => $id,
				);
			}
		}

		return null;
	}
}

