<?php
/**
 * Import handler.
 *
 * @package MksDdn_Migrate_Content
 */

namespace MksDdn\MigrateContent\Import;

use MksDdn\MigrateContent\Contracts\ImporterInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapperInterface;
use MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapperInterface;
use MksDdn\MigrateContent\Media\AttachmentRestorer;
use MksDdn\MigrateContent\Options\OptionsImporter;
use WP_Error;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles importing pages, options pages, and forms.
 *
 * @since 1.0.0
 */
class ImportHandler implements ImporterInterface {

	/**
	 * Attachment restorer.
	 */
	private AttachmentRestorer $media_restorer;

	private OptionsImporter $options_importer;

	/**
	 * WordPress functions wrapper.
	 *
	 * @var WpFunctionsWrapperInterface
	 */
	private WpFunctionsWrapperInterface $wp_functions;

	/**
	 * WordPress user functions wrapper.
	 *
	 * @var WpUserFunctionsWrapperInterface
	 */
	private WpUserFunctionsWrapperInterface $wp_user_functions;

	/**
	 * Loader callback for media files (archives only).
	 *
	 * @var callable|null
	 */
	private $media_file_loader = null;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRestorer|null            $media_restorer   Optional media restorer.
	 * @param OptionsImporter|null               $options_importer Optional options importer.
	 * @param WpFunctionsWrapperInterface|null   $wp_functions     Optional WordPress functions wrapper.
	 * @param WpUserFunctionsWrapperInterface|null $wp_user_functions Optional WordPress user functions wrapper.
	 * @since 1.0.0
	 */
	public function __construct(
		?AttachmentRestorer $media_restorer = null,
		?OptionsImporter $options_importer = null,
		?WpFunctionsWrapperInterface $wp_functions = null,
		?WpUserFunctionsWrapperInterface $wp_user_functions = null
	) {
		$this->media_restorer   = $media_restorer ?? new AttachmentRestorer();
		$this->options_importer = $options_importer ?? new OptionsImporter();
		$this->wp_functions      = $wp_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpFunctionsWrapper();
		$this->wp_user_functions = $wp_user_functions ?? new \MksDdn\MigrateContent\Core\Wrappers\WpUserFunctionsWrapper();
	}

	/**
	 * Set loader used to fetch files from archive.
	 *
	 * @param callable|null $loader Loader callback.
	 * @return void
	 * @since 1.0.0
	 */
	public function set_media_file_loader( ?callable $loader ): void {
		$this->media_file_loader = $loader;
	}

	/**
	 * Import bundle containing multiple posts/options.
	 *
	 * @param array $data Bundle payload.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_bundle( array $data ): bool {
		$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( ! $this->import_single_page( $item ) ) {
				return false;
			}
		}

		if ( isset( $data['options'] ) && is_array( $data['options'] ) ) {
			$this->import_bundle_options( $data['options'] );
		}

		return true;
	}
	/**
	 * Imports a single page with ACF fields.
	 *
	 * @param array $data Data array containing page information.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public function import_single_page( array $data ): bool {
		if ( ! $this->validate_page_data( $data ) ) {
			return false;
		}

		$post_type = in_array( $data['type'] ?? 'page', array( 'post', 'page' ), true ) ? $data['type'] : 'page';
		$existing  = $this->wp_functions->get_page_by_path( $data['slug'], 'OBJECT', $post_type );
		$post_data = $this->prepare_post_data( $data, $post_type );

		$post_id = $existing ? $this->update_post( $existing, $post_data ) : $this->create_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return false;
		}

		if ( 'post' === $post_type ) {
			$this->assign_taxonomies( $post_id, $data );
		}

		$media_maps = $this->restore_media( $data, $post_id );
		$id_map     = $media_maps['id_map'] ?? array();
		$url_map    = $media_maps['url_map'] ?? array();

		// Import meta data first (includes ACF flat meta format).
		$this->import_meta_data( $data, $post_id, $id_map, $url_map );
		// Then import ACF fields using ACF API (for proper structure handling).
		$this->import_acf_fields( $data, $post_id, $id_map, $url_map );

		return true;
	}

	/**
	 * Restore media attachments for the entity.
	 *
	 * @param array $data    Payload.
	 * @param int   $post_id Target post ID.
	 */
	private function restore_media( array $data, int $post_id ): array {
		$entries = $data['_mksddn_media'] ?? array();

		if ( empty( $entries ) || ! is_callable( $this->media_file_loader ) ) {
			return array(
				'id_map'  => array(),
				'url_map' => array(),
			);
		}

		if ( isset( $data['featured_media'] ) ) {
			$this->wp_functions->update_post_meta( $post_id, '_mksddn_original_thumbnail', (int) $data['featured_media'] );
		}

		return $this->media_restorer->restore(
			$entries,
			$this->media_file_loader,
			$post_id
		);
	}

	/**
	 * Import option/widget bundle.
	 *
	 * @param array $data Payload.
	 */
	/**
	 * Validate page data.
	 *
	 * @param array $data Page payload.
	 * @return bool
	 */
	private function validate_page_data( array $data ): bool {
		return isset( $data['title'], $data['content'], $data['slug'] );
	}

	/**
	 * Validate options page data.
	 *
	 * @param array $data Options page payload.
	 * @return bool
	 */
	private function validate_options_page_data( array $data ): bool {
		return isset( $data['menu_slug'], $data['acf_fields'] );
	}

	/**
	 * Prepare page data for insert/update.
	 *
	 * @param array $data Page payload.
	 * @return array
	 */
	private function prepare_post_data( array $data, string $post_type ): array {
		return array(
			'post_title'   => sanitize_text_field( $data['title'] ),
			'post_content' => wp_kses_post( $data['content'] ),
			'post_excerpt' => sanitize_text_field( $data['excerpt'] ?? '' ),
			'post_name'    => sanitize_title( $data['slug'] ),
			'post_type'    => $post_type,
			'post_status'  => sanitize_key( $data['status'] ?? 'publish' ),
			'post_author'  => absint( $data['author'] ?? $this->wp_user_functions->get_current_user_id() ),
			'post_date_gmt'=> isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : current_time( 'mysql', true ),
		);
	}

	/**
	 * Update an existing post.
	 *
	 * @param WP_Post $existing Existing post.
	 * @param array   $post_data     Data to update.
	 * @return int|WP_Error
	 */
	private function update_post( WP_Post $existing, array $post_data ): int|WP_Error {
		$post_data['ID'] = $existing->ID;
		return $this->wp_functions->update_post( $post_data );
	}

	/**
	 * Create a post/page.
	 *
	 * @param array $post_data Data to insert.
	 * @return int|WP_Error
	 */
	private function create_post( array $post_data ): int|WP_Error {
		return $this->wp_functions->insert_post( $post_data );
	}

	/**
	 * Assign taxonomy terms for posts.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Payload.
	 */
	private function assign_taxonomies( int $post_id, array $data ): void {
		if ( empty( $data['taxonomies'] ) || ! is_array( $data['taxonomies'] ) ) {
			return;
		}

		foreach ( $data['taxonomies'] as $taxonomy => $terms ) {
			if ( ! taxonomy_exists( $taxonomy ) || ! is_array( $terms ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( $terms as $term_data ) {
				$term = wp_insert_term(
					$term_data['name'] ?? '',
					$taxonomy,
					array(
						'slug'        => $term_data['slug'] ?? '',
						'description' => $term_data['description'] ?? '',
					)
				);

				if ( is_wp_error( $term ) ) {
					$existing = get_term_by( 'slug', $term_data['slug'] ?? '', $taxonomy );
					if ( $existing ) {
						$term_ids[] = (int) $existing->term_id;
					}
				} else {
					$term_ids[] = (int) $term['term_id'];
				}
			}

			if ( ! empty( $term_ids ) ) {
				wp_set_object_terms( $post_id, $term_ids, $taxonomy );
			}
		}
	}

	/**
	 * Import a fields configuration meta for a form.
	 *
	 * @param array $data    Payload containing 'fields_config'.
	 * @param int   $form_id Form ID.
	 * @return void
	 */
	private function import_fields_config( array $data, int $form_id ): void {
		if ( ! isset( $data['fields_config'] ) ) {
			return;
		}

		delete_post_meta( $form_id, '_fields_config' );

		$sanitized = sanitize_textarea_field( $data['fields_config'] );
		add_post_meta( $form_id, '_fields_config', $sanitized );
	}

	/**
	 * Import ACF fields for a post.
	 *
	 * @param array      $data    Payload containing 'acf_fields'.
	 * @param int|string $post_id Target post ID.
	 * @param array      $id_map  Media ID mapping.
	 * @param array      $url_map Media URL mapping.
	 * @return void
	 */
	private function import_acf_fields( array $data, int|string $post_id, array $id_map = array(), array $url_map = array() ): void {
		// Log start of import (always log to file).
		$this->log_debug( sprintf( '[ACF Import] ===== START import_acf_fields: post_id=%d =====', $post_id ) );
		$this->log_debug( sprintf( '[ACF Import] acf_fields exists: %s', isset( $data['acf_fields'] ) ? 'yes' : 'no' ) );
		if ( isset( $data['acf_fields'] ) ) {
			$this->log_debug( sprintf( '[ACF Import] acf_fields is_array: %s, count: %d', is_array( $data['acf_fields'] ) ? 'yes' : 'no', is_array( $data['acf_fields'] ) ? count( $data['acf_fields'] ) : 0 ) );
			$this->log_debug( sprintf( '[ACF Import] acf_fields keys: %s', wp_json_encode( array_keys( $data['acf_fields'] ) ) ) );
		}

		if ( ! isset( $data['acf_fields'] ) || ! is_array( $data['acf_fields'] ) || empty( $data['acf_fields'] ) ) {
			$this->log_debug( '[ACF Import] No acf_fields found, exiting' );
			return;
		}

		// Use ACF API if available (preferred method).
		if ( function_exists( 'update_field' ) ) {
			$this->log_debug( '[ACF Import] ACF API available, starting recursive import' );
			// Process fields recursively to handle nested structures (groups, repeaters, etc.).
			$this->import_acf_fields_recursive( $data['acf_fields'], $post_id, $id_map, $url_map );
		} else {
			$this->log_debug( '[ACF Import] ACF API not available, using fallback' );
			// Fallback: update meta fields directly if ACF API is not available.
			foreach ( $data['acf_fields'] as $field_name => $field_value ) {
				if ( empty( $field_name ) || ! is_string( $field_name ) ) {
					continue;
				}

				$value = $this->remap_media_values( $field_value, $id_map, $url_map );
				$this->wp_functions->update_post_meta( $post_id, sanitize_text_field( $field_name ), $value );
			}
		}
	}

	/**
	 * Recursively import ACF fields, handling nested structures.
	 *
	 * @param array      $fields  ACF fields data (can be nested).
	 * @param int|string $post_id Target post ID.
	 * @param array      $id_map  Media ID mapping.
	 * @param array      $url_map Media URL mapping.
	 * @param string     $parent_field_name Parent field name for nested fields.
	 * @return void
	 */
	private function import_acf_fields_recursive( array $fields, int|string $post_id, array $id_map = array(), array $url_map = array(), string $parent_field_name = '' ): void {
		$this->log_debug( sprintf( '[ACF Import] Starting recursive import for post_id=%d, parent_field=%s, fields_count=%d', $post_id, $parent_field_name, count( $fields ) ) );

		foreach ( $fields as $field_name => $field_value ) {
			if ( empty( $field_name ) || ! is_string( $field_name ) ) {
				continue;
			}

			$sanitized_name = sanitize_text_field( $field_name );
			$full_field_name = $parent_field_name ? $parent_field_name . '_' . $sanitized_name : $sanitized_name;

			$this->log_debug( sprintf( '[ACF Import] Processing field: name=%s, full_name=%s, value_type=%s', $field_name, $full_field_name, gettype( $field_value ) ) );

			// Get field object to determine field type.
			$field_object = null;
			if ( function_exists( 'get_field_object' ) ) {
				// Try to get field object with full name first (for nested fields).
				$field_object = get_field_object( $full_field_name, $post_id, false, true );
				// If not found, try without parent prefix.
				if ( ! $field_object && $parent_field_name ) {
					$field_object = get_field_object( $sanitized_name, $post_id, false, true );
				}
				// Normalize false to null for type safety.
				if ( false === $field_object ) {
					$field_object = null;
				}
			}

			// Recursively remap media values in the entire field structure.
			$value = $this->remap_media_values( $field_value, $id_map, $url_map );

			// Process value based on field type.
			$field_type = $field_object['type'] ?? null;
			$this->log_debug( sprintf( '[ACF Import] Field type detected: name=%s, type=%s', $field_name, $field_type ?? 'unknown' ) );

			// Handle group fields - process nested fields and update group.
			if ( 'group' === $field_type && is_array( $value ) ) {
				$this->log_debug( sprintf( '[ACF Import] Processing GROUP field: name=%s, sub_fields_count=%d', $field_name, count( $value ) ) );
				// Get group field object to access sub-field definitions.
				$group_field_object = $field_object;

				// Process group value with all nested structures.
				$processed_group_value = $this->process_group_value( $value, $post_id, $id_map, $url_map, $full_field_name, $group_field_object );
				$this->log_debug( sprintf( '[ACF Import] Group processed: name=%s, processed_fields=%s', $field_name, wp_json_encode( array_keys( $processed_group_value ) ) ) );

				// Update each sub-field individually instead of updating group as a whole.
				// This is more reliable for ACF group fields.
				if ( function_exists( 'update_field' ) && $group_field_object && isset( $group_field_object['sub_fields'] ) ) {
					foreach ( $processed_group_value as $sub_field_name => $sub_field_value ) {
						$sub_field_name_sanitized = sanitize_text_field( $sub_field_name );
						$sub_field_full_name = $full_field_name . '_' . $sub_field_name_sanitized;

						// Find sub-field definition to get its type.
						$sub_field_type = null;
						foreach ( $group_field_object['sub_fields'] as $sub_field_def ) {
							if ( isset( $sub_field_def['name'] ) && $sub_field_def['name'] === $sub_field_name_sanitized ) {
								$sub_field_type = $sub_field_def['type'] ?? null;
								break;
							}
						}

						// Skip repeater fields - they are already updated separately in process_group_value.
						if ( 'repeater' === $sub_field_type ) {
							$this->log_debug( sprintf( '[ACF Import] Skipping REPEATER sub-field (already updated): full_name=%s', $sub_field_full_name ) );
							continue;
						}

						// Find sub-field key for update_field with field_key.
						$sub_field_key = null;
						foreach ( $group_field_object['sub_fields'] as $sub_field_def ) {
							if ( isset( $sub_field_def['name'] ) && $sub_field_def['name'] === $sub_field_name_sanitized ) {
								$sub_field_key = $sub_field_def['key'] ?? null;
								break;
							}
						}
						
						// Update each non-repeater sub-field individually.
						$this->log_debug( sprintf( '[ACF Import] Updating sub-field in group: full_name=%s, type=%s, key=%s', $sub_field_full_name, $sub_field_type ?? 'unknown', $sub_field_key ?? 'none' ) );
						
						// For nested group fields, recursively process them.
						if ( 'group' === $sub_field_type && is_array( $sub_field_value ) ) {
							// Get sub-field object for nested group.
							$nested_group_field_object = null;
							foreach ( $group_field_object['sub_fields'] as $sub_field_def ) {
								if ( isset( $sub_field_def['name'] ) && $sub_field_def['name'] === $sub_field_name_sanitized ) {
									$nested_group_field_object = $sub_field_def;
									break;
								}
							}
							
							// Process nested group and update its sub-fields.
							$nested_processed = $this->process_group_value( $sub_field_value, $post_id, $id_map, $url_map, $sub_field_full_name, $nested_group_field_object );
							
							// Update each nested sub-field using full field name (not field_key for nested groups).
							if ( $nested_group_field_object && isset( $nested_group_field_object['sub_fields'] ) ) {
								foreach ( $nested_processed as $nested_sub_field_name => $nested_sub_field_value ) {
									$nested_sub_field_name_sanitized = sanitize_text_field( $nested_sub_field_name );
									$nested_sub_field_full_name = $sub_field_full_name . '_' . $nested_sub_field_name_sanitized;
									
									// Find nested sub-field type.
									$nested_sub_field_type = null;
									$nested_sub_field_key = null;
									foreach ( $nested_group_field_object['sub_fields'] as $nested_sub_field_def ) {
										if ( isset( $nested_sub_field_def['name'] ) && $nested_sub_field_def['name'] === $nested_sub_field_name_sanitized ) {
											$nested_sub_field_type = $nested_sub_field_def['type'] ?? null;
											$nested_sub_field_key = $nested_sub_field_def['key'] ?? null;
											break;
										}
									}
									
									// Skip repeaters in nested groups.
									if ( 'repeater' === $nested_sub_field_type ) {
										continue;
									}
									
									// Try field_key first, then fallback to full name, then direct meta update.
									$nested_update_result = false;
									if ( $nested_sub_field_key ) {
										$nested_update_result = update_field( $nested_sub_field_key, $nested_sub_field_value, $post_id );
									}
									if ( ! $nested_update_result ) {
										// Try full name.
										$nested_update_result = update_field( $nested_sub_field_full_name, $nested_sub_field_value, $post_id );
									}
									if ( ! $nested_update_result ) {
										// Fallback to direct meta update for nested group fields.
										// ACF stores nested group fields with field name as meta key.
										$this->wp_functions->update_post_meta( $post_id, $nested_sub_field_full_name, $nested_sub_field_value );
										// Also update with field_key format if available.
										if ( $nested_sub_field_key ) {
											$this->wp_functions->update_post_meta( $post_id, $nested_sub_field_key, $nested_sub_field_value );
										}
										$this->log_debug( sprintf( '[ACF Import] Nested sub-field update via meta: name=%s, full_name=%s, key=%s', $nested_sub_field_name, $nested_sub_field_full_name, $nested_sub_field_key ?? 'none' ) );
										$nested_update_result = true; // Assume success for meta update.
									}
									$this->log_debug( sprintf( '[ACF Import] Nested sub-field update: name=%s, full_name=%s, type=%s, key=%s, result=%s', $nested_sub_field_name, $nested_sub_field_full_name, $nested_sub_field_type ?? 'unknown', $nested_sub_field_key ?? 'none', $nested_update_result ? 'success' : 'failed' ) );
								}
							}
						} else {
							// Update simple sub-field using field_key with update_field, with fallback to direct meta.
							$sub_update_result = false;
							if ( $sub_field_key ) {
								// Try field_key first.
								$sub_update_result = update_field( $sub_field_key, $sub_field_value, $post_id );
							}
							if ( ! $sub_update_result ) {
								// Fallback to full name if field_key fails.
								$sub_update_result = update_field( $sub_field_full_name, $sub_field_value, $post_id );
							}
							if ( ! $sub_update_result ) {
								// Final fallback: direct meta update for group sub-fields.
								$this->wp_functions->update_post_meta( $post_id, $sub_field_full_name, $sub_field_value );
								// Also update with field_key format if available.
								if ( $sub_field_key ) {
									$this->wp_functions->update_post_meta( $post_id, $sub_field_key, $sub_field_value );
								}
								$this->log_debug( sprintf( '[ACF Import] Sub-field update via meta: full_name=%s, key=%s', $sub_field_full_name, $sub_field_key ?? 'none' ) );
								$sub_update_result = true; // Assume success for meta update.
							} else {
								$this->log_debug( sprintf( '[ACF Import] Sub-field update with key: full_name=%s, key=%s, result=%s', $sub_field_full_name, $sub_field_key ?? 'none', $sub_update_result ? 'success' : 'failed' ) );
							}
						}
					}
				}
				continue;
			}

			// For gallery fields, convert array of objects to array of IDs.
			if ( 'gallery' === $field_type && is_array( $value ) ) {
				$value = $this->convert_gallery_to_ids( $value );
			}

			// For image fields, extract ID if it's an object.
			if ( 'image' === $field_type && is_array( $value ) && isset( $value['ID'] ) ) {
				$value = (int) $value['ID'];
			}

			// For file fields, extract ID if it's an object.
			if ( 'file' === $field_type && is_array( $value ) && isset( $value['ID'] ) ) {
				$value = (int) $value['ID'];
			}

			// For repeater fields, ensure proper format and process rows.
			if ( 'repeater' === $field_type && is_array( $value ) ) {
				$this->log_debug( sprintf( '[ACF Import] Processing REPEATER field: name=%s, rows_count=%d', $field_name, count( $value ) ) );
				$value = $this->process_repeater_value( $value, $post_id, $id_map, $url_map, $full_field_name, $field_object );
				$this->log_debug( sprintf( '[ACF Import] REPEATER processed: name=%s, processed_rows=%d', $field_name, is_array( $value ) ? count( $value ) : 0 ) );
				
				// Update repeater field using field_key if available, otherwise use field name.
				if ( function_exists( 'update_field' ) && ! empty( $value ) ) {
					$repeater_field_key = $field_object['key'] ?? null;
					$update_field_name = $parent_field_name ? $full_field_name : $sanitized_name;
					$this->log_debug( sprintf( '[ACF Import] Updating REPEATER field: name=%s, key=%s, rows=%d', $update_field_name, $repeater_field_key ?? 'none', is_array( $value ) ? count( $value ) : 0 ) );
					
					// Try field_key first, then fallback to field name.
					$update_result = false;
					if ( $repeater_field_key ) {
						$update_result = update_field( $repeater_field_key, $value, $post_id );
					}
					if ( ! $update_result ) {
						$update_result = update_field( $update_field_name, $value, $post_id );
					}
					$this->log_debug( sprintf( '[ACF Import] REPEATER field update result: name=%s, key=%s, result=%s', $update_field_name, $repeater_field_key ?? 'none', $update_result ? 'success' : 'failed' ) );
					
					// If update_field fails, try direct meta update as fallback.
					if ( ! $update_result ) {
						$this->log_debug( sprintf( '[ACF Import] REPEATER update_field failed, trying direct meta update: name=%s, key=%s', $update_field_name, $repeater_field_key ?? 'none' ) );
						$repeater_count = is_array( $value ) ? count( $value ) : 0;
						$this->wp_functions->update_post_meta( $post_id, $update_field_name, $repeater_count );
						if ( $repeater_field_key ) {
							$this->wp_functions->update_post_meta( $post_id, $repeater_field_key, $repeater_count );
						}
						// Update each row's sub-fields.
						foreach ( $value as $row_index => $row_data ) {
							if ( is_array( $row_data ) ) {
								foreach ( $row_data as $row_sub_field_name => $row_sub_field_value ) {
									$row_sub_field_name_sanitized = sanitize_text_field( $row_sub_field_name );
									$row_sub_field_full_name = $update_field_name . '_' . $row_index . '_' . $row_sub_field_name_sanitized;
									
									// Check if this is a link field (array with title, url, target keys).
									$is_link_field = false;
									$link_field_def = null;
									if ( $field_object && isset( $field_object['sub_fields'] ) ) {
										foreach ( $field_object['sub_fields'] as $row_sub_field_def ) {
											$def_name = $row_sub_field_def['name'] ?? null;
											$def_key = $row_sub_field_def['key'] ?? null;
											$def_type = $row_sub_field_def['type'] ?? null;
											// Check by name or key, and type must be 'link'.
											if ( 'link' === $def_type && ( ( $def_name && $def_name === $row_sub_field_name_sanitized ) || ( $def_key && $def_key === $row_sub_field_name ) ) ) {
												$is_link_field = true;
												$link_field_def = $row_sub_field_def;
												break;
											}
										}
									}
									// Also check by structure: if value is array with title, url, target keys, it might be a link field.
									if ( ! $is_link_field && is_array( $row_sub_field_value ) && isset( $row_sub_field_value['title'], $row_sub_field_value['url'], $row_sub_field_value['target'] ) ) {
										// Try to find link field in repeater sub_fields.
										if ( $field_object && isset( $field_object['sub_fields'] ) ) {
											foreach ( $field_object['sub_fields'] as $row_sub_field_def ) {
												if ( 'link' === ( $row_sub_field_def['type'] ?? null ) ) {
													$is_link_field = true;
													$link_field_def = $row_sub_field_def;
													// Use the field name from definition, not from data.
													$row_sub_field_name = $row_sub_field_def['name'] ?? $row_sub_field_name;
													$row_sub_field_name_sanitized = sanitize_text_field( $row_sub_field_name );
													$row_sub_field_full_name = $update_field_name . '_' . $row_index . '_' . $row_sub_field_name_sanitized;
													break;
												}
											}
										}
									}
									
									// For link fields, save the entire array as serialized value.
									if ( $is_link_field && is_array( $row_sub_field_value ) ) {
										// Ensure proper structure for ACF link field: title, url, target.
										$link_data = array(
											'title'  => $row_sub_field_value['title'] ?? '',
											'url'    => $row_sub_field_value['url'] ?? '',
											'target' => $row_sub_field_value['target'] ?? '',
										);
										$this->log_debug( sprintf( '[ACF Import] Saving link field via meta: full_name=%s, value=%s', $row_sub_field_full_name, wp_json_encode( $link_data ) ) );
										$this->wp_functions->update_post_meta( $post_id, $row_sub_field_full_name, $link_data );
										if ( $link_field_def && isset( $link_field_def['key'] ) ) {
											$link_field_key_full = $update_field_name . '_' . $row_index . '_' . $link_field_def['key'];
											$this->wp_functions->update_post_meta( $post_id, $link_field_key_full, $link_data );
										}
									} else {
										// Normal field processing.
										$this->wp_functions->update_post_meta( $post_id, $row_sub_field_full_name, $row_sub_field_value );
										
										// Also try with field_key format if we have sub-field definitions.
										$row_sub_field_key = null;
										if ( $field_object && isset( $field_object['sub_fields'] ) ) {
											// First, try to find in direct sub-fields of repeater.
											foreach ( $field_object['sub_fields'] as $row_sub_field_def ) {
												if ( isset( $row_sub_field_def['name'] ) && $row_sub_field_def['name'] === $row_sub_field_name_sanitized ) {
													$row_sub_field_key = $row_sub_field_def['key'] ?? null;
													break;
												}
											}
											
											// If not found, check if it's a sub-field of a group field (unwrapped group).
											if ( ! $row_sub_field_key ) {
												foreach ( $field_object['sub_fields'] as $row_sub_field_def ) {
													if ( 'group' === ( $row_sub_field_def['type'] ?? null ) && isset( $row_sub_field_def['sub_fields'] ) && is_array( $row_sub_field_def['sub_fields'] ) ) {
														foreach ( $row_sub_field_def['sub_fields'] as $group_sub_field_def ) {
															if ( isset( $group_sub_field_def['name'] ) && $group_sub_field_def['name'] === $row_sub_field_name_sanitized ) {
																$row_sub_field_key = $group_sub_field_def['key'] ?? null;
																break 2;
															}
														}
													}
												}
											}
										}
										
										if ( $row_sub_field_key ) {
											$row_sub_field_key_full = $update_field_name . '_' . $row_index . '_' . $row_sub_field_key;
											$this->wp_functions->update_post_meta( $post_id, $row_sub_field_key_full, $row_sub_field_value );
										}
									}
								}
							}
						}
						$this->log_debug( sprintf( '[ACF Import] REPEATER update via meta: name=%s, rows=%d', $update_field_name, $repeater_count ) );
					}
					continue; // Skip normal field update for repeater fields.
				}
			}

			// Update field using ACF API.
			if ( function_exists( 'update_field' ) ) {
				// Use full field name for nested fields, simple name for root fields.
				$update_field_name = $parent_field_name ? $full_field_name : $sanitized_name;
				$this->log_debug( sprintf( '[ACF Import] Updating field: name=%s, type=%s, value_type=%s', $update_field_name, $field_type ?? 'unknown', gettype( $value ) ) );
				$update_result = update_field( $update_field_name, $value, $post_id );
				$this->log_debug( sprintf( '[ACF Import] Field update result: name=%s, result=%s', $update_field_name, $update_result ? 'success' : 'failed' ) );
			}
		}
	}

	/**
	 * Process group field value, handling nested structures.
	 *
	 * @param array      $value              Group field value.
	 * @param int|string $post_id            Post ID.
	 * @param array      $id_map             Media ID mapping.
	 * @param array      $url_map            Media URL mapping.
	 * @param string     $parent_field_name  Parent field name.
	 * @param array|null $parent_field_object Parent field object (optional).
	 * @return array Processed group value.
	 */
	private function process_group_value( array $value, int|string $post_id, array $id_map = array(), array $url_map = array(), string $parent_field_name = '', ?array $parent_field_object = null ): array {
		$this->log_debug( sprintf( '[ACF Import] process_group_value: parent=%s, sub_fields_count=%d', $parent_field_name, count( $value ) ) );
		$processed = array();

		// Use provided parent field object or get it.
		if ( null === $parent_field_object && function_exists( 'get_field_object' ) && ! empty( $parent_field_name ) ) {
			$parent_field_object = get_field_object( $parent_field_name, $post_id, false, true );
			// Normalize false to null for type safety.
			if ( false === $parent_field_object ) {
				$parent_field_object = null;
			}
		}

		foreach ( $value as $sub_field_name => $sub_field_value ) {
			$this->log_debug( sprintf( '[ACF Import] Processing sub-field in group: parent=%s, sub_field=%s, value_type=%s', $parent_field_name, $sub_field_name, gettype( $sub_field_value ) ) );
			$sub_field_name_sanitized = sanitize_text_field( $sub_field_name );
			$sub_field_full_name = $parent_field_name ? $parent_field_name . '_' . $sub_field_name_sanitized : $sub_field_name_sanitized;

			// Get sub-field object from parent group field definition.
			$sub_field_object = null;
			$sub_field_type = null;

			if ( $parent_field_object && isset( $parent_field_object['sub_fields'] ) && is_array( $parent_field_object['sub_fields'] ) ) {
				// Find sub-field definition in parent group.
				// Check both by name and by key, as data may use field keys instead of names.
				foreach ( $parent_field_object['sub_fields'] as $sub_field_def ) {
					$def_name = $sub_field_def['name'] ?? null;
					$def_key = $sub_field_def['key'] ?? null;
					if ( ( $def_name && $def_name === $sub_field_name_sanitized ) || ( $def_key && $def_key === $sub_field_name ) ) {
						$sub_field_object = $sub_field_def;
						$sub_field_type = $sub_field_def['type'] ?? null;
						$this->log_debug( sprintf( '[ACF Import] Found sub-field definition: name=%s, key=%s, type=%s', $def_name ?? 'null', $def_key ?? 'null', $sub_field_type ?? 'null' ) );
						break;
					}
				}
			}

			// Fallback: try to get field object directly.
			if ( ! $sub_field_type && function_exists( 'get_field_object' ) ) {
				$sub_field_object = get_field_object( $sub_field_full_name, $post_id, false, true );
				// Normalize false to null for type safety.
				if ( false === $sub_field_object ) {
					$sub_field_object = null;
				}
				$sub_field_type = $sub_field_object['type'] ?? null;
			}

			// Detect repeater by structure if type is not found.
			if ( ! $sub_field_type && is_array( $sub_field_value ) && $this->is_repeater_structure( $sub_field_value ) ) {
				$sub_field_type = 'repeater';
			}

			// Process based on sub-field type.
			if ( 'repeater' === $sub_field_type && is_array( $sub_field_value ) ) {
				$this->log_debug( sprintf( '[ACF Import] Processing REPEATER in group: parent=%s, sub_field=%s, rows=%d', $parent_field_name, $sub_field_name, count( $sub_field_value ) ) );
				$this->log_debug( sprintf( '[ACF Import] sub_field_object for repeater: %s, has_sub_fields: %s', $sub_field_object ? 'exists' : 'null', $sub_field_object && isset( $sub_field_object['sub_fields'] ) ? 'yes' : 'no' ) );
				$processed[ $sub_field_name ] = $this->process_repeater_value( $sub_field_value, $post_id, $id_map, $url_map, $sub_field_full_name, $sub_field_object );
				$this->log_debug( sprintf( '[ACF Import] REPEATER processed in group: parent=%s, sub_field=%s, processed_rows=%d', $parent_field_name, $sub_field_name, is_array( $processed[ $sub_field_name ] ) ? count( $processed[ $sub_field_name ] ) : 0 ) );

				// Update repeater field immediately using field_key if available.
				if ( function_exists( 'update_field' ) && ! empty( $processed[ $sub_field_name ] ) ) {
					$repeater_field_key = $sub_field_object['key'] ?? null;
					$this->log_debug( sprintf( '[ACF Import] Updating REPEATER field in process_group_value: full_name=%s, key=%s, rows=%d', $sub_field_full_name, $repeater_field_key ?? 'none', is_array( $processed[ $sub_field_name ] ) ? count( $processed[ $sub_field_name ] ) : 0 ) );
					
					// Try field_key first, then fallback to full name.
					$repeater_update_result = false;
					if ( $repeater_field_key ) {
						$repeater_update_result = update_field( $repeater_field_key, $processed[ $sub_field_name ], $post_id );
					}
					if ( ! $repeater_update_result ) {
						$repeater_update_result = update_field( $sub_field_full_name, $processed[ $sub_field_name ], $post_id );
					}
					$this->log_debug( sprintf( '[ACF Import] REPEATER update result in process_group_value: full_name=%s, key=%s, result=%s', $sub_field_full_name, $repeater_field_key ?? 'none', $repeater_update_result ? 'success' : 'failed' ) );
					
					// If update_field fails, try direct meta update as fallback.
					if ( ! $repeater_update_result ) {
						$this->log_debug( sprintf( '[ACF Import] REPEATER update_field failed, trying direct meta update: full_name=%s, key=%s', $sub_field_full_name, $repeater_field_key ?? 'none' ) );
						// ACF stores repeater count in main field name.
						$repeater_count = is_array( $processed[ $sub_field_name ] ) ? count( $processed[ $sub_field_name ] ) : 0;
						$this->wp_functions->update_post_meta( $post_id, $sub_field_full_name, $repeater_count );
						if ( $repeater_field_key ) {
							$this->wp_functions->update_post_meta( $post_id, $repeater_field_key, $repeater_count );
						}
						// Update each row's sub-fields.
						foreach ( $processed[ $sub_field_name ] as $row_index => $row_data ) {
							if ( is_array( $row_data ) ) {
								foreach ( $row_data as $row_sub_field_name => $row_sub_field_value ) {
									$row_sub_field_name_sanitized = sanitize_text_field( $row_sub_field_name );
									$row_sub_field_full_name = $sub_field_full_name . '_' . $row_index . '_' . $row_sub_field_name_sanitized;
									
									// Check if this is a link field (array with title, url, target keys).
									$is_link_field = false;
									$link_field_def = null;
									if ( $sub_field_object && isset( $sub_field_object['sub_fields'] ) ) {
										foreach ( $sub_field_object['sub_fields'] as $row_sub_field_def ) {
											$def_name = $row_sub_field_def['name'] ?? null;
											$def_key = $row_sub_field_def['key'] ?? null;
											$def_type = $row_sub_field_def['type'] ?? null;
											// Check by name or key, and type must be 'link'.
											if ( 'link' === $def_type && ( ( $def_name && $def_name === $row_sub_field_name_sanitized ) || ( $def_key && $def_key === $row_sub_field_name ) ) ) {
												$is_link_field = true;
												$link_field_def = $row_sub_field_def;
												break;
											}
										}
									}
									// Also check by structure: if value is array with title, url, target keys, it might be a link field.
									if ( ! $is_link_field && is_array( $row_sub_field_value ) && isset( $row_sub_field_value['title'], $row_sub_field_value['url'], $row_sub_field_value['target'] ) ) {
										// Try to find link field in repeater sub_fields.
										if ( $sub_field_object && isset( $sub_field_object['sub_fields'] ) ) {
											foreach ( $sub_field_object['sub_fields'] as $row_sub_field_def ) {
												if ( 'link' === ( $row_sub_field_def['type'] ?? null ) ) {
													$is_link_field = true;
													$link_field_def = $row_sub_field_def;
													// Use the field name from definition, not from data.
													$row_sub_field_name = $row_sub_field_def['name'] ?? $row_sub_field_name;
													$row_sub_field_name_sanitized = sanitize_text_field( $row_sub_field_name );
													$row_sub_field_full_name = $sub_field_full_name . '_' . $row_index . '_' . $row_sub_field_name_sanitized;
													break;
												}
											}
										}
									}
									
									// For link fields, save the entire array as serialized value.
									if ( $is_link_field && is_array( $row_sub_field_value ) ) {
										// Ensure proper structure for ACF link field: title, url, target.
										$link_data = array(
											'title'  => $row_sub_field_value['title'] ?? '',
											'url'    => $row_sub_field_value['url'] ?? '',
											'target' => $row_sub_field_value['target'] ?? '',
										);
										$this->log_debug( sprintf( '[ACF Import] Saving link field via meta: full_name=%s, value=%s', $row_sub_field_full_name, wp_json_encode( $link_data ) ) );
										$this->wp_functions->update_post_meta( $post_id, $row_sub_field_full_name, $link_data );
										if ( $link_field_def && isset( $link_field_def['key'] ) ) {
											$link_field_key_full = $sub_field_full_name . '_' . $row_index . '_' . $link_field_def['key'];
											$this->wp_functions->update_post_meta( $post_id, $link_field_key_full, $link_data );
										}
									} else {
										// Normal field processing.
										$this->wp_functions->update_post_meta( $post_id, $row_sub_field_full_name, $row_sub_field_value );
										
										// Also try with field_key format if we have sub-field definitions.
										$row_sub_field_key = null;
										if ( $sub_field_object && isset( $sub_field_object['sub_fields'] ) ) {
											// First, try to find in direct sub-fields of repeater.
											foreach ( $sub_field_object['sub_fields'] as $row_sub_field_def ) {
												if ( isset( $row_sub_field_def['name'] ) && $row_sub_field_def['name'] === $row_sub_field_name_sanitized ) {
													$row_sub_field_key = $row_sub_field_def['key'] ?? null;
													break;
												}
											}
											
											// If not found, check if it's a sub-field of a group field (unwrapped group).
											if ( ! $row_sub_field_key ) {
												foreach ( $sub_field_object['sub_fields'] as $row_sub_field_def ) {
													if ( 'group' === ( $row_sub_field_def['type'] ?? null ) && isset( $row_sub_field_def['sub_fields'] ) && is_array( $row_sub_field_def['sub_fields'] ) ) {
														foreach ( $row_sub_field_def['sub_fields'] as $group_sub_field_def ) {
															if ( isset( $group_sub_field_def['name'] ) && $group_sub_field_def['name'] === $row_sub_field_name_sanitized ) {
																$row_sub_field_key = $group_sub_field_def['key'] ?? null;
																break 2;
															}
														}
													}
												}
											}
										}
										
										if ( $row_sub_field_key ) {
											$row_sub_field_key_full = $sub_field_full_name . '_' . $row_index . '_' . $row_sub_field_key;
											$this->wp_functions->update_post_meta( $post_id, $row_sub_field_key_full, $row_sub_field_value );
										}
									}
								}
							}
						}
						$this->log_debug( sprintf( '[ACF Import] REPEATER update via meta: full_name=%s, rows=%d', $sub_field_full_name, $repeater_count ) );
					}
				}
			} elseif ( 'gallery' === $sub_field_type && is_array( $sub_field_value ) ) {
				$processed[ $sub_field_name ] = $this->convert_gallery_to_ids( $sub_field_value );
			} elseif ( 'image' === $sub_field_type && is_array( $sub_field_value ) && isset( $sub_field_value['ID'] ) ) {
				$processed[ $sub_field_name ] = (int) $sub_field_value['ID'];
			} elseif ( 'file' === $sub_field_type && is_array( $sub_field_value ) && isset( $sub_field_value['ID'] ) ) {
				$processed[ $sub_field_name ] = (int) $sub_field_value['ID'];
			} elseif ( 'group' === $sub_field_type && is_array( $sub_field_value ) ) {
				$processed[ $sub_field_name ] = $this->process_group_value( $sub_field_value, $post_id, $id_map, $url_map, $sub_field_full_name );
			} else {
				// Remap media values for simple fields.
				$processed[ $sub_field_name ] = $this->remap_media_values( $sub_field_value, $id_map, $url_map );
			}
		}
		return $processed;
	}

	/**
	 * Check if array structure looks like a repeater field.
	 *
	 * @param array $value Array to check.
	 * @return bool True if structure looks like repeater.
	 */
	private function is_repeater_structure( array $value ): bool {
		if ( empty( $value ) ) {
			return false;
		}

		$keys = array_keys( $value );
		// Check if all keys are numeric (0, 1, 2, ...).
		$all_numeric = true;
		foreach ( $keys as $key ) {
			if ( ! is_numeric( $key ) || (int) $key != $key ) {
				$all_numeric = false;
				break;
			}
		}

		if ( ! $all_numeric ) {
			return false;
		}

		// Check if values are arrays (repeater rows).
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Process repeater field value, handling nested structures in rows.
	 *
	 * @param array      $value              Repeater field value.
	 * @param int|string $post_id            Post ID.
	 * @param array      $id_map             Media ID mapping.
	 * @param array      $url_map            Media URL mapping.
	 * @param string     $parent_field_name  Parent field name.
	 * @param array|null $parent_field_object Parent field object (optional).
	 * @return array Processed repeater value.
	 */
	private function process_repeater_value( array $value, int|string $post_id, array $id_map = array(), array $url_map = array(), string $parent_field_name = '', ?array $parent_field_object = null ): array {
		$this->log_debug( sprintf( '[ACF Import] process_repeater_value: parent=%s, rows_count=%d', $parent_field_name, count( $value ) ) );
		if ( empty( $value ) || ! is_array( $value ) ) {
			$this->log_debug( sprintf( '[ACF Import] REPEATER value is empty or not array: parent=%s', $parent_field_name ) );
			return array();
		}

		// Use provided parent field object or get it if not provided.
		$this->log_debug( sprintf( '[ACF Import] process_repeater_value: parent_field_object provided: %s', $parent_field_object ? 'yes' : 'no' ) );
		if ( null === $parent_field_object && function_exists( 'get_field_object' ) && ! empty( $parent_field_name ) ) {
			$this->log_debug( sprintf( '[ACF Import] Trying to get field object for: %s', $parent_field_name ) );
			$parent_field_object = get_field_object( $parent_field_name, $post_id, false, true );
			// Normalize false to null for type safety.
			if ( false === $parent_field_object ) {
				$parent_field_object = null;
				$this->log_debug( sprintf( '[ACF Import] get_field_object returned false for: %s', $parent_field_name ) );
			} else {
				$this->log_debug( sprintf( '[ACF Import] get_field_object found field: %s, has_sub_fields: %s', $parent_field_name, isset( $parent_field_object['sub_fields'] ) ? 'yes' : 'no' ) );
			}
		}
		
		if ( $parent_field_object && isset( $parent_field_object['sub_fields'] ) ) {
			$this->log_debug( sprintf( '[ACF Import] parent_field_object has %d sub_fields', count( $parent_field_object['sub_fields'] ) ) );
		}

		$processed = array();
		foreach ( $value as $row_index => $row_data ) {
			$this->log_debug( sprintf( '[ACF Import] Processing repeater row: parent=%s, row_index=%d, row_fields_count=%d', $parent_field_name, $row_index, is_array( $row_data ) ? count( $row_data ) : 0 ) );
			if ( ! is_array( $row_data ) ) {
				$processed[ $row_index ] = $this->remap_media_values( $row_data, $id_map, $url_map );
				continue;
			}

			// Check if row_data contains only one key that is a group field.
			// In this case, we need to unwrap the group's sub-fields into the row_data.
			$unwrapped_group_field_object = null;
			$row_keys = array_keys( $row_data );
			if ( count( $row_keys ) === 1 ) {
				$single_key = $row_keys[0];
				$single_value = $row_data[ $single_key ];
				
				$this->log_debug( sprintf( '[ACF Import] Repeater row has single key: parent=%s, row_index=%d, key=%s, is_array=%s', $parent_field_name, $row_index, $single_key, is_array( $single_value ) ? 'yes' : 'no' ) );
				
				// Check if this single key is a group field in the repeater definition.
				if ( is_array( $single_value ) && $parent_field_object && isset( $parent_field_object['sub_fields'] ) && is_array( $parent_field_object['sub_fields'] ) ) {
					$this->log_debug( sprintf( '[ACF Import] Checking repeater sub_fields for group match: parent=%s, sub_fields_count=%d', $parent_field_name, count( $parent_field_object['sub_fields'] ) ) );
					
					$found_match = false;
					foreach ( $parent_field_object['sub_fields'] as $sub_field_def ) {
						$sub_field_name_in_def = $sub_field_def['name'] ?? null;
						$sub_field_key_in_def = $sub_field_def['key'] ?? null;
						$sub_field_type_in_def = $sub_field_def['type'] ?? null;
						
						$this->log_debug( sprintf( '[ACF Import] Checking sub_field: name=%s, key=%s, type=%s, single_key=%s', $sub_field_name_in_def ?? 'null', $sub_field_key_in_def ?? 'null', $sub_field_type_in_def ?? 'null', $single_key ) );
						
						// Check if single_key matches field name or field key, and it's a group type.
						if ( ( $single_key === $sub_field_name_in_def || $single_key === $sub_field_key_in_def ) && 'group' === $sub_field_type_in_def ) {
							$this->log_debug( sprintf( '[ACF Import] Unwrapping group field in repeater row: parent=%s, row_index=%d, group_field=%s', $parent_field_name, $row_index, $single_key ) );
							// Store the group field object for later use when processing sub-fields.
							$unwrapped_group_field_object = $sub_field_def;
							// Unwrap: replace row_data with the group's sub-fields.
							$row_data = $single_value;
							$this->log_debug( sprintf( '[ACF Import] Unwrapped row_data keys: %s', wp_json_encode( array_keys( $row_data ) ) ) );
							$found_match = true;
							break;
						}
					}
					
					if ( ! $found_match ) {
						$this->log_debug( sprintf( '[ACF Import] No group field match found for single_key=%s in repeater sub_fields', $single_key ) );
						// Try to detect if single_value looks like a group or link field (has multiple keys that might be sub-fields).
						// If it does, assume it needs to be unwrapped.
						if ( count( $single_value ) > 0 && ! $this->is_repeater_structure( $single_value ) ) {
							$this->log_debug( sprintf( '[ACF Import] single_value looks like a group/link structure, attempting to unwrap anyway: keys=%s', wp_json_encode( array_keys( $single_value ) ) ) );
							// Check if single_value looks like a link field structure (has title, url, target).
							$looks_like_link = is_array( $single_value ) && isset( $single_value['title'], $single_value['url'] );
							
							// Try to find matching field definition (group or link) by checking sub_fields.
							foreach ( $parent_field_object['sub_fields'] as $sub_field_def ) {
								$def_name = $sub_field_def['name'] ?? null;
								$def_key = $sub_field_def['key'] ?? null;
								$def_type = $sub_field_def['type'] ?? null;
								// Check if single_key matches this field definition.
								if ( ( $single_key === $def_name || $single_key === $def_key ) && ( 'group' === $def_type || 'link' === $def_type ) ) {
									$unwrapped_group_field_object = $sub_field_def;
									$this->log_debug( sprintf( '[ACF Import] Found %s field definition for unwrapped data: name=%s', $def_type, $def_name ?? 'null' ) );
									break;
								}
								// Also check if single_value looks like a link and this field is a link type.
								if ( $looks_like_link && 'link' === $def_type && ! $unwrapped_group_field_object ) {
									$unwrapped_group_field_object = $sub_field_def;
									$this->log_debug( sprintf( '[ACF Import] Found link field definition by structure match: name=%s', $def_name ?? 'null' ) );
									break;
								}
							}
							// If still not found, try to find any group or link field as fallback.
							if ( ! $unwrapped_group_field_object ) {
								foreach ( $parent_field_object['sub_fields'] as $sub_field_def ) {
									$def_type = $sub_field_def['type'] ?? null;
									if ( 'group' === $def_type || 'link' === $def_type ) {
										$unwrapped_group_field_object = $sub_field_def;
										$this->log_debug( sprintf( '[ACF Import] Found %s field definition as fallback for unwrapped data: name=%s', $def_type, $sub_field_def['name'] ?? 'null' ) );
										break;
									}
								}
							}
							
							// Only unwrap if we found a matching field definition.
							if ( $unwrapped_group_field_object ) {
								$row_data = $single_value;
								$this->log_debug( sprintf( '[ACF Import] Unwrapped row_data for %s field: keys=%s', $unwrapped_group_field_object['type'] ?? 'unknown', wp_json_encode( array_keys( $row_data ) ) ) );
							}
						}
					}
				} else {
					$this->log_debug( sprintf( '[ACF Import] Cannot check for group: is_array=%s, has_parent_object=%s', is_array( $single_value ) ? 'yes' : 'no', $parent_field_object ? 'yes' : 'no' ) );
				}
			}

			$processed_row = array();
			
			// Special handling for unwrapped link fields: save the entire array as the link field value.
			if ( $unwrapped_group_field_object && 'link' === ( $unwrapped_group_field_object['type'] ?? null ) ) {
				$link_field_name = $unwrapped_group_field_object['name'] ?? null;
				if ( $link_field_name ) {
					$this->log_debug( sprintf( '[ACF Import] Saving unwrapped link field as single array: field_name=%s, keys=%s, value=%s', $link_field_name, wp_json_encode( array_keys( $row_data ) ), wp_json_encode( $row_data ) ) );
					// For link fields, ensure the structure is correct: must have title, url, and optionally target.
					// ACF link fields expect: array('title' => string, 'url' => string, 'target' => string)
					$link_value = $this->remap_media_values( $row_data, $id_map, $url_map );
					// Ensure proper structure for ACF link field.
					if ( is_array( $link_value ) && isset( $link_value['title'], $link_value['url'] ) ) {
						$processed_row[ $link_field_name ] = array(
							'title'  => $link_value['title'] ?? '',
							'url'    => $link_value['url'] ?? '',
							'target' => $link_value['target'] ?? '',
						);
					} else {
						// Fallback: use the value as-is if structure is unexpected.
						$processed_row[ $link_field_name ] = $link_value;
					}
				} else {
					// Fallback: process each key separately if we can't find the field name.
					foreach ( $row_data as $sub_field_name => $sub_field_value ) {
						$processed_row[ $sub_field_name ] = $this->remap_media_values( $sub_field_value, $id_map, $url_map );
					}
				}
			} else {
				// Normal processing for other field types.
				foreach ( $row_data as $sub_field_name => $sub_field_value ) {
					$sub_field_name_sanitized = sanitize_text_field( $sub_field_name );

					// Get sub-field object from parent repeater field definition.
					// If we unwrapped a group field, look for sub-fields in the unwrapped group field definition.
					$sub_field_object = null;
					$sub_field_type = null;

					if ( $unwrapped_group_field_object && isset( $unwrapped_group_field_object['sub_fields'] ) && is_array( $unwrapped_group_field_object['sub_fields'] ) ) {
						// Find sub-field definition in the unwrapped group field.
						foreach ( $unwrapped_group_field_object['sub_fields'] as $sub_field_def ) {
							if ( isset( $sub_field_def['name'] ) && $sub_field_def['name'] === $sub_field_name_sanitized ) {
								$sub_field_object = $sub_field_def;
								$sub_field_type = $sub_field_def['type'] ?? null;
								break;
							}
						}
					} elseif ( $parent_field_object && isset( $parent_field_object['sub_fields'] ) && is_array( $parent_field_object['sub_fields'] ) ) {
						// Find sub-field definition in parent repeater.
						foreach ( $parent_field_object['sub_fields'] as $sub_field_def ) {
							if ( isset( $sub_field_def['name'] ) && $sub_field_def['name'] === $sub_field_name_sanitized ) {
								$sub_field_object = $sub_field_def;
								$sub_field_type = $sub_field_def['type'] ?? null;
								break;
							}
						}
					}

				// Process based on sub-field type.
				if ( 'gallery' === $sub_field_type && is_array( $sub_field_value ) ) {
					$processed_row[ $sub_field_name ] = $this->convert_gallery_to_ids( $sub_field_value );
				} elseif ( 'image' === $sub_field_type && is_array( $sub_field_value ) && isset( $sub_field_value['ID'] ) ) {
					$processed_row[ $sub_field_name ] = (int) $sub_field_value['ID'];
				} elseif ( 'file' === $sub_field_type && is_array( $sub_field_value ) && isset( $sub_field_value['ID'] ) ) {
					$processed_row[ $sub_field_name ] = (int) $sub_field_value['ID'];
				} elseif ( 'link' === $sub_field_type && is_array( $sub_field_value ) ) {
					// Handle link fields: ensure proper structure with title, url, target.
					$link_value = $this->remap_media_values( $sub_field_value, $id_map, $url_map );
					if ( is_array( $link_value ) && isset( $link_value['title'], $link_value['url'] ) ) {
						$processed_row[ $sub_field_name ] = array(
							'title'  => $link_value['title'] ?? '',
							'url'    => $link_value['url'] ?? '',
							'target' => $link_value['target'] ?? '',
						);
					} else {
						// Fallback: use the value as-is if structure is unexpected.
						$processed_row[ $sub_field_name ] = $link_value;
					}
				} elseif ( 'repeater' === $sub_field_type && is_array( $sub_field_value ) ) {
					// Handle nested repeater fields within repeater rows.
					$sub_field_full_name = $parent_field_name . '_' . $row_index . '_' . $sub_field_name_sanitized;
					$processed_row[ $sub_field_name ] = $this->process_repeater_value( $sub_field_value, $post_id, $id_map, $url_map, $sub_field_full_name, $sub_field_object );
				} elseif ( 'group' === $sub_field_type && is_array( $sub_field_value ) ) {
					$sub_field_full_name = $parent_field_name . '_' . $row_index . '_' . $sub_field_name_sanitized;
					$processed_row[ $sub_field_name ] = $this->process_group_value( $sub_field_value, $post_id, $id_map, $url_map, $sub_field_full_name, $sub_field_object );
				} else {
					// Remap media values for simple fields.
					$processed_row[ $sub_field_name ] = $this->remap_media_values( $sub_field_value, $id_map, $url_map );
				}
				}
			}
			$processed[ $row_index ] = $processed_row;
		}
		return array_values( $processed );
	}

	/**
	 * Convert gallery array (objects) to array of IDs.
	 *
	 * @param array $gallery_array Gallery array (may contain objects or IDs).
	 * @return array Array of attachment IDs.
	 */
	private function convert_gallery_to_ids( array $gallery_array ): array {
		$ids = array();
		foreach ( $gallery_array as $item ) {
			if ( is_array( $item ) && isset( $item['ID'] ) ) {
				$ids[] = (int) $item['ID'];
			} elseif ( is_numeric( $item ) ) {
				$ids[] = (int) $item;
			}
		}
		return $ids;
	}



	/**
	 * Import meta for a post.
	 *
	 * @param array $data    Payload containing 'meta'.
	 * @param int   $post_id Target post ID.
	 * @return void
	 */
	private function import_meta_data( array $data, int $post_id, array $id_map = array(), array $url_map = array() ): void {
		if ( ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return;
		}

		// Import all meta fields, including ACF flat format for nested structures.
		foreach ( $data['meta'] as $key => $values ) {
			$meta_key = sanitize_text_field( $key );

			// Delete existing meta before adding new.
			delete_post_meta( $post_id, $meta_key );

			if ( is_array( $values ) ) {
				foreach ( $values as $value ) {
					$value = maybe_unserialize( $value );
					$value = $this->remap_media_values( $value, $id_map, $url_map );
					add_post_meta( $post_id, $meta_key, $value );
				}
			} else {
				// Single value.
				$value = maybe_unserialize( $values );
				$value = $this->remap_media_values( $value, $id_map, $url_map );
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * Recursively find field keys in ACF field structure.
	 *
	 * @param array $fields     ACF fields array.
	 * @param array $acf_fields Fields to import.
	 * @param array $field_key_map Field key map (passed by reference).
	 * @return void
	 */
	private function find_field_keys_recursive( array $fields, array $acf_fields, array &$field_key_map ): void {
		foreach ( $fields as $field ) {
			if ( isset( $field['name'], $field['key'] ) && isset( $acf_fields[ $field['name'] ] ) ) {
				$field_key_map[ $field['name'] ] = $field['key'];
			}

			// Check for sub_fields (repeater, group, etc.).
			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$this->find_field_keys_recursive( $field['sub_fields'], $acf_fields, $field_key_map );
			}
		}
	}

	private function remap_media_values( mixed $value, array $id_map, array $url_map ): mixed {
		if ( is_array( $value ) ) {
			if ( isset( $value['ID'] ) ) {
				$old_id = (int) $value['ID'];
				if ( isset( $id_map[ $old_id ] ) ) {
					$new_id         = $id_map[ $old_id ];
					$value['ID']    = $new_id;
					$value['id']    = $new_id;
					$value['url']   = \wp_get_attachment_url( $new_id );
					$value['link']  = \get_permalink( $new_id );
					$value['sizes'] = $this->remap_media_values( $value['sizes'] ?? array(), $id_map, $url_map );
				}
			}

			foreach ( $value as $key => $child ) {
				$value[ $key ] = $this->remap_media_values( $child, $id_map, $url_map );
			}

			return $value;
		}

		if ( is_numeric( $value ) ) {
			$int = (int) $value;
			if ( isset( $id_map[ $int ] ) ) {
				return $id_map[ $int ];
			}
		}

		if ( is_string( $value ) && isset( $url_map[ $value ] ) ) {
			return $url_map[ $value ];
		}

		return $value;
	}

	/**
	 * Import options/widgets portion of a bundle.
	 *
	 * @param array $options_bundle Bundle options structure.
	 * @return void
	 */
	private function import_bundle_options( array $options_bundle ): void {
		if ( isset( $options_bundle['options'] ) && is_array( $options_bundle['options'] ) ) {
			$this->options_importer->import_options( $options_bundle['options'] );
		}

		if ( isset( $options_bundle['widgets'] ) && is_array( $options_bundle['widgets'] ) ) {
			$this->options_importer->import_widgets( $options_bundle['widgets'] );
		}
	}

	/**
	 * Log debug message to file and error_log.
	 *
	 * @param string $message Message to log.
	 * @return void
	 */
	private function log_debug( string $message ): void {
		$timestamp = gmdate( 'Y-m-d H:i:s' );
		$log_message = sprintf( '[%s] %s', $timestamp, $message ) . PHP_EOL;

		// Log to error_log if WP_DEBUG is enabled.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $message );
		}

		// Always log to custom file.
		$upload_dir = wp_upload_dir();
		$log_file = $upload_dir['basedir'] . '/mksddn-acf-import-debug.log';
		@file_put_contents( $log_file, $log_message, FILE_APPEND | LOCK_EX );
	}
}
