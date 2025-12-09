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

		foreach ( $this->resolve_post_type_keys( $request ) as $type ) {
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

	/**
	 * Determine which post-type keys are present in the request.
	 *
	 * @param array $request Raw request.
	 * @return string[]
	 */
	private function resolve_post_type_keys( array $request ): array {
		$keys = array();

		$legacy = array_map( 'sanitize_key', (array) ( $request['export_post_types'] ?? array() ) );
		if ( ! empty( $legacy ) ) {
			$keys = array_merge( $keys, $legacy );
		}

		foreach ( array_keys( $request ) as $field ) {
			if ( ! preg_match( '/^selected_(.+)_ids$/', $field, $matches ) ) {
				continue;
			}

			$type = sanitize_key( $matches[1] );

			if ( '' === $type ) {
				continue;
			}

			$keys[] = $type;
		}

		return array_values( array_unique( $keys ) );
	}
}

