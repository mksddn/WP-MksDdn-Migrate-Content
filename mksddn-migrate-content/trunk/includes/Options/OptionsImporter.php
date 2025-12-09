<?php
/**
 * Imports options and widget groups.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Options;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores serialized options.
 */
class OptionsImporter {

	/**
	 * Import option key => value pairs.
	 *
	 * @param array $options Key-value map.
	 * @param bool  $overwrite Whether to overwrite existing.
	 * @return void
	 */
	public function import_options( array $options, bool $overwrite = true ): void {
		foreach ( $options as $key => $value ) {
			if ( $overwrite || ! get_option( $key, null ) ) {
				update_option( $key, $value );
			}
		}
	}

	/**
	 * Import widgets (widget_* and sidebars).
	 *
	 * @param array $widgets Data map.
	 * @param bool  $overwrite Overwrite mode.
	 */
	public function import_widgets( array $widgets, bool $overwrite = true ): void {
		foreach ( $widgets as $key => $value ) {
			if ( 'sidebars_widgets' === $key ) {
				$this->apply_sidebar_widgets( $value, $overwrite );
				continue;
			}

			if ( 0 === strpos( $key, 'widget_' ) ) {
				if ( $overwrite || ! get_option( $key, null ) ) {
					update_option( $key, $value );
				}
			}
		}
	}

	/**
	 * Merge sidebars_widgets data.
	 *
	 * @param array $data      Sidebar structure.
	 * @param bool  $overwrite Overwrite mode.
	 */
	private function apply_sidebar_widgets( array $data, bool $overwrite ): void {
		if ( $overwrite ) {
			update_option( 'sidebars_widgets', $data );
			return;
		}

		$current = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $current ) ) {
			update_option( 'sidebars_widgets', $data );
			return;
		}

		$merged = array_merge( $current, $data );
		update_option( 'sidebars_widgets', $merged );
	}
}

