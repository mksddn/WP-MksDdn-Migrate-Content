<?php
/**
 * Import handler class.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import handler class.
 */
class MksDdn_MC_Import_Handler {

	/**
	 * Import data.
	 *
	 * @var array
	 */
	private $import_data = array();

	/**
	 * Backup data for rollback.
	 *
	 * @var array
	 */
	private $backup_data = array();

	/**
	 * Import from file.
	 *
	 * @param string $file_path File path.
	 * @return array|WP_Error
	 */
	public function import_from_file( $file_path ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'permission_denied', __( 'Permission denied.', 'mksddn-migrate-content' ) );
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Import file not found.', 'mksddn-migrate-content' ) );
		}

		$file_content = file_get_contents( $file_path );
		if ( false === $file_content ) {
			return new WP_Error( 'file_read_error', __( 'Failed to read import file.', 'mksddn-migrate-content' ) );
		}

		$data = json_decode( $file_content, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_json', __( 'Invalid JSON format.', 'mksddn-migrate-content' ) );
		}

		return $this->validate_and_import( $data );
	}

	/**
	 * Validate and import data.
	 *
	 * @param array $data Import data.
	 * @return array|WP_Error
	 */
	private function validate_and_import( $data ) {
		$validation = $this->validate_import_data( $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$this->import_data = $data;
		$this->create_backup();

		try {
			$result = $this->process_import();
			return $result;
		} catch ( Exception $e ) {
			$this->rollback();
			return new WP_Error( 'import_error', $e->getMessage() );
		}
	}

	/**
	 * Validate import data.
	 *
	 * @param array $data Import data.
	 * @return true|WP_Error
	 */
	private function validate_import_data( $data ) {
		if ( ! isset( $data['version'] ) || ! isset( $data['type'] ) ) {
			return new WP_Error( 'invalid_data', __( 'Invalid import data structure.', 'mksddn-migrate-content' ) );
		}

		if ( ! isset( $data['site_url'] ) || ! isset( $data['home_url'] ) ) {
			return new WP_Error( 'missing_urls', __( 'Missing site URLs in import data.', 'mksddn-migrate-content' ) );
		}

		return true;
	}

	/**
	 * Create backup before import.
	 */
	private function create_backup() {
		$this->backup_data = array(
			'site_url' => get_site_url(),
			'home_url' => get_home_url(),
			'options'  => $this->backup_options(),
		);
	}

	/**
	 * Backup WordPress options.
	 *
	 * @return array
	 */
	private function backup_options() {
		global $wpdb;

		$options = array();
		$option_names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name NOT LIKE '_transient%' AND option_name NOT LIKE '_site_transient%'"
		);

		foreach ( $option_names as $option_name ) {
			$options[ $option_name ] = get_option( $option_name );
		}

		return $options;
	}

	/**
	 * Process import step by step.
	 *
	 * @return array
	 */
	private function process_import() {
		$type = $this->import_data['type'];

		if ( 'full' === $type ) {
			return $this->import_full_site();
		} elseif ( 'selective' === $type ) {
			return $this->import_selective();
		}

		return new WP_Error( 'unknown_type', __( 'Unknown import type.', 'mksddn-migrate-content' ) );
	}

	/**
	 * Import full site.
	 *
	 * @return array
	 */
	private function import_full_site() {
		$this->replace_urls();
		$this->import_options();
		$this->import_database();

		return array(
			'success' => true,
			'message' => __( 'Full site imported successfully.', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * Import selective content.
	 *
	 * @return array
	 */
	private function import_selective() {
		$post_types = isset( $this->import_data['post_types'] ) ? $this->import_data['post_types'] : array();
		$slugs      = isset( $this->import_data['slugs'] ) ? $this->import_data['slugs'] : array();
		$posts      = isset( $this->import_data['posts'] ) ? $this->import_data['posts'] : array();

		$imported = array();

		foreach ( $posts as $post_data ) {
			$result = $this->import_post_by_slug( $post_data );
			if ( ! is_wp_error( $result ) ) {
				$imported[] = $result;
			}
		}

		$this->replace_urls();

		return array(
			'success'  => true,
			'message'  => __( 'Selective content imported successfully.', 'mksddn-migrate-content' ),
			'imported' => $imported,
		);
	}

	/**
	 * Import post by slug.
	 *
	 * @param array $post_data Post data.
	 * @return int|WP_Error Post ID or error.
	 */
	private function import_post_by_slug( $post_data ) {
		$slug = isset( $post_data['post_name'] ) ? $post_data['post_name'] : '';

		if ( empty( $slug ) ) {
			return new WP_Error( 'no_slug', __( 'Post slug is missing.', 'mksddn-migrate-content' ) );
		}

		$existing_post = get_page_by_path( $slug, OBJECT, $post_data['post_type'] );

		$post_args = array(
			'post_title'   => $post_data['post_title'],
			'post_name'    => $slug,
			'post_content' => $post_data['post_content'],
			'post_excerpt' => $post_data['post_excerpt'],
			'post_status'  => $post_data['post_status'],
			'post_type'    => $post_data['post_type'],
			'post_date'    => $post_data['post_date'],
		);

		if ( $existing_post ) {
			$post_args['ID'] = $existing_post->ID;
			$post_id = wp_update_post( $post_args, true );
		} else {
			$post_id = wp_insert_post( $post_args, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( isset( $post_data['meta'] ) && is_array( $post_data['meta'] ) ) {
			foreach ( $post_data['meta'] as $meta_key => $meta_value ) {
				if ( is_array( $meta_value ) && count( $meta_value ) === 1 ) {
					$meta_value = $meta_value[0];
				}
				update_post_meta( $post_id, $meta_key, maybe_unserialize( $meta_value ) );
			}
		}

		if ( isset( $post_data['taxonomies'] ) && is_array( $post_data['taxonomies'] ) ) {
			foreach ( $post_data['taxonomies'] as $taxonomy => $terms ) {
				$term_ids = array();
				foreach ( $terms as $term_data ) {
					$term = get_term_by( 'slug', $term_data['slug'], $taxonomy );
					if ( ! $term ) {
						$term = wp_insert_term( $term_data['name'], $taxonomy, array( 'slug' => $term_data['slug'] ) );
						if ( ! is_wp_error( $term ) ) {
							$term_ids[] = $term['term_id'];
						}
					} else {
						$term_ids[] = $term->term_id;
					}
				}
				if ( ! empty( $term_ids ) ) {
					wp_set_object_terms( $post_id, $term_ids, $taxonomy );
				}
			}
		}

		return $post_id;
	}

	/**
	 * Replace URLs in content.
	 */
	private function replace_urls() {
		$old_site_url = $this->import_data['site_url'];
		$old_home_url = $this->import_data['home_url'];
		$new_site_url = get_site_url();
		$new_home_url = get_home_url();

		if ( $old_site_url === $new_site_url && $old_home_url === $new_home_url ) {
			return;
		}

		global $wpdb;

		// Replace in posts table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s), post_excerpt = REPLACE(post_excerpt, %s, %s), guid = REPLACE(guid, %s, %s) WHERE post_content LIKE %s OR post_excerpt LIKE %s OR guid LIKE %s",
				$old_site_url,
				$new_site_url,
				$old_site_url,
				$new_site_url,
				$old_site_url,
				$new_site_url,
				'%' . $wpdb->esc_like( $old_site_url ) . '%',
				'%' . $wpdb->esc_like( $old_site_url ) . '%',
				'%' . $wpdb->esc_like( $old_site_url ) . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s), post_excerpt = REPLACE(post_excerpt, %s, %s), guid = REPLACE(guid, %s, %s) WHERE post_content LIKE %s OR post_excerpt LIKE %s OR guid LIKE %s",
				$old_home_url,
				$new_home_url,
				$old_home_url,
				$new_home_url,
				$old_home_url,
				$new_home_url,
				'%' . $wpdb->esc_like( $old_home_url ) . '%',
				'%' . $wpdb->esc_like( $old_home_url ) . '%',
				'%' . $wpdb->esc_like( $old_home_url ) . '%'
			)
		);

		// Replace in postmeta table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
				$old_site_url,
				$new_site_url,
				'%' . $wpdb->esc_like( $old_site_url ) . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s",
				$old_home_url,
				$new_home_url,
				'%' . $wpdb->esc_like( $old_home_url ) . '%'
			)
		);

		// Replace in options table.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s",
				$old_site_url,
				$new_site_url,
				'%' . $wpdb->esc_like( $old_site_url ) . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s",
				$old_home_url,
				$new_home_url,
				'%' . $wpdb->esc_like( $old_home_url ) . '%'
			)
		);

		// Handle serialized data in options.
		$this->replace_urls_in_serialized_options( $old_site_url, $new_site_url );
		$this->replace_urls_in_serialized_options( $old_home_url, $new_home_url );
	}

	/**
	 * Replace URLs in serialized options.
	 *
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 */
	private function replace_urls_in_serialized_options( $old_url, $new_url ) {
		global $wpdb;

		$options = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value LIKE %s",
				'%' . $wpdb->esc_like( $old_url ) . '%'
			)
		);

		foreach ( $options as $option ) {
			$value = maybe_unserialize( $option->option_value );
			if ( is_serialized( $option->option_value ) ) {
				$value = $this->replace_urls_in_array( $value, $old_url, $new_url );
				update_option( $option->option_name, $value );
			}
		}
	}

	/**
	 * Recursively replace URLs in array.
	 *
	 * @param mixed  $data Data to process.
	 * @param string $old_url Old URL.
	 * @param string $new_url New URL.
	 * @return mixed
	 */
	private function replace_urls_in_array( $data, $old_url, $new_url ) {
		if ( is_string( $data ) ) {
			return str_replace( $old_url, $new_url, $data );
		} elseif ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = $this->replace_urls_in_array( $value, $old_url, $new_url );
			}
		} elseif ( is_object( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data->$key = $this->replace_urls_in_array( $value, $old_url, $new_url );
			}
		}
		return $data;
	}

	/**
	 * Import WordPress options.
	 */
	private function import_options() {
		if ( ! isset( $this->import_data['options'] ) || ! is_array( $this->import_data['options'] ) ) {
			return;
		}

		foreach ( $this->import_data['options'] as $option_name => $option_value ) {
			if ( strpos( $option_name, '_transient' ) === 0 || strpos( $option_name, '_site_transient' ) === 0 ) {
				continue;
			}
			update_option( $option_name, maybe_unserialize( $option_value ) );
		}
	}

	/**
	 * Import database content.
	 */
	private function import_database() {
		if ( ! isset( $this->import_data['database'] ) || ! is_array( $this->import_data['database'] ) ) {
			return;
		}

		global $wpdb;

		foreach ( $this->import_data['database'] as $table_name => $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$wpdb->replace( $table_name, $row );
			}
		}
	}

	/**
	 * Rollback changes.
	 */
	private function rollback() {
		if ( empty( $this->backup_data ) ) {
			return;
		}

		if ( isset( $this->backup_data['options'] ) ) {
			foreach ( $this->backup_data['options'] as $option_name => $option_value ) {
				update_option( $option_name, $option_value );
			}
		}
	}
}

