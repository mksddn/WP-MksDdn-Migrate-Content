<?php
/**
 * Parses admin request into a ContentSelection.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Selection;

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

		// Process all fields that match the pattern.
		foreach ( array_keys( $request ) as $field ) {
			if ( ! preg_match( '/^selected_(.+)_ids$/', $field, $matches ) ) {
				continue;
			}

			$type = sanitize_key( $matches[1] );
			if ( '' === $type ) {
				continue;
			}

			// Check if field exists and has values.
			if ( ! isset( $request[ $field ] ) ) {
				continue;
			}

			$values = (array) $request[ $field ];
			
			// Process IDs directly.
			foreach ( $values as $value ) {
				$id = absint( $value );
				if ( $id > 0 ) {
					$selection->add_item( $type, $id );
				}
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

