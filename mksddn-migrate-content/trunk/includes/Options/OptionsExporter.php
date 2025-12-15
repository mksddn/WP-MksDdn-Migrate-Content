<?php
/**
 * Handles exporting option groups and widgets.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects options and widgets for export.
 */
class OptionsExporter {

	/**
	 * Fetch selected options.
	 *
	 * @param string[] $option_keys Keys to export.
	 * @return array
	 */
	public function export_options( array $option_keys ): array {
		$result = array();

		foreach ( $option_keys as $key ) {
			$value = get_option( $key, null );
			if ( null !== $value ) {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Export selected widget groups.
	 *
	 * @param string[] $widget_groups Widget option prefixes (e.g., widget_text).
	 * @return array
	 */
	public function export_widgets( array $widget_groups ): array {
		$result = array();

		foreach ( $widget_groups as $group ) {
			$value = get_option( $group, null );
			if ( null !== $value ) {
				$result[ $group ] = $value;
			}
		}

		$sidebars = get_option( 'sidebars_widgets', null );
		if ( null !== $sidebars ) {
			$result['sidebars_widgets'] = $sidebars;
		}

		return $result;
	}
}

