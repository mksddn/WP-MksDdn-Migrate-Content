<?php
/**
 * Value object containing collected attachments.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Represents attachment metadata + related files.
 */
class AttachmentCollection {

	/**
	 * Attachment manifest entries.
	 *
	 * @var array[]
	 */
	private array $manifest = array();

	/**
	 * Files to include in archive.
	 *
	 * @var array[]
	 */
	private array $assets = array();

	/**
	 * Add entry to collection.
	 *
	 * @param array $manifest_entry Attachment metadata.
	 * @param array $asset_entry    Source/target file pair.
	 */
	public function add( array $manifest_entry, array $asset_entry ): void {
		$this->manifest[] = $manifest_entry;
		$this->assets[]   = $asset_entry;
	}

	/**
	 * Whether collection has attachments.
	 */
	public function has_items(): bool {
		return ! empty( $this->manifest );
	}

	/**
	 * Get manifest entries.
	 *
	 * @return array[]
	 */
	public function get_manifest(): array {
		return $this->manifest;
	}

	/**
	 * Files to inject into archive.
	 *
	 * @return array[]
	 */
	public function get_assets(): array {
		return $this->assets;
	}

	/**
	 * Merge another collection into the current instance.
	 *
	 * @param AttachmentCollection $other Source collection.
	 * @return void
	 */
	public function absorb( AttachmentCollection $other ): void {
		$other_manifest = $other->get_manifest();
		$other_assets   = $other->get_assets();

		foreach ( $other_manifest as $index => $entry ) {
			$asset_entry = $other_assets[ $index ] ?? null;
			if ( ! is_array( $asset_entry ) || empty( $asset_entry['source'] ) || empty( $asset_entry['target'] ) ) {
				continue;
			}

			$this->add( $entry, $asset_entry );
		}
	}
}

