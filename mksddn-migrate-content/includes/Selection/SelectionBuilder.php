<?php
/**
 * Parses admin request into a ContentSelection.
 *
 * @package MksDdn_Migrate_Content
 */

namespace Mksddn_MC\Selection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds selection objects based on submitted form data.
 */
class SelectionBuilder {

	/**
	 * Build selection from request data.
	 *
	 * @param array $request Request source (typically $_POST).
	 * @return ContentSelection
	 */
	public function from_request( array $request ): ContentSelection {
		$selection = new ContentSelection();

		$post_types = array_filter(
			array_map( 'sanitize_key', (array) ( $request['export_post_types'] ?? array() ) )
		);

		foreach ( $post_types as $type ) {
			$field = sprintf( 'selected_%s_ids', $type );
			if ( empty( $request[ $field ] ) ) {
				continue;
			}

			$ids = array_map( 'absint', (array) $request[ $field ] );
			foreach ( $ids as $id ) {
				$selection->add_item( $type, $id );
			}
		}

		$options = array_map( 'sanitize_text_field', (array) ( $request['options_keys'] ?? array() ) );
		foreach ( $options as $key ) {
			$selection->add_option( $key );
		}

		$widget_groups = array_map( 'sanitize_key', (array) ( $request['widget_groups'] ?? array() ) );
		foreach ( $widget_groups as $group ) {
			$selection->add_widget_group( $group );
		}

		return $selection;
	}
}

