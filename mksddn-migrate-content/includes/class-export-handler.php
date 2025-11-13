<?php
/**
 * Export handler class.
 *
 * @package MksDdn_Migrate_Content
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export handler class.
 */
class MksDdn_MC_Export_Handler {

	/**
	 * Export type: full site.
	 *
	 * @var string
	 */
	const TYPE_FULL = 'full';

	/**
	 * Export type: specific posts/pages.
	 *
	 * @var string
	 */
	const TYPE_SELECTIVE = 'selective';

	/**
	 * Export data.
	 *
	 * @param string $type Export type.
	 * @param array  $args Export arguments.
	 * @return array|WP_Error
	 */
	public function export( $type = self::TYPE_FULL, $args = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'permission_denied', __( 'Permission denied.', 'mksddn-migrate-content' ) );
		}

		switch ( $type ) {
			case self::TYPE_FULL:
				return $this->export_full_site( $args );
			case self::TYPE_SELECTIVE:
				return $this->export_selective( $args );
			default:
				return new WP_Error( 'invalid_type', __( 'Invalid export type.', 'mksddn-migrate-content' ) );
		}
	}

	/**
	 * Export full site.
	 *
	 * @param array $args Export arguments.
	 * @return array|WP_Error
	 */
	private function export_full_site( $args = array() ) {
		$export_data = array(
			'version'     => MKSDDN_MC_VERSION,
			'type'        => self::TYPE_FULL,
			'timestamp'   => current_time( 'mysql' ),
			'site_url'    => get_site_url(),
			'home_url'    => get_home_url(),
			'database'    => $this->export_database(),
			'plugins'     => $this->export_plugins(),
			'themes'      => $this->export_themes(),
			'uploads'     => $this->export_uploads_info(),
			'options'     => $this->export_options(),
		);

		return $export_data;
	}

	/**
	 * Export selective content (posts/pages by slug).
	 *
	 * @param array $args Export arguments (post_types, slugs).
	 * @return array|WP_Error
	 */
	private function export_selective( $args = array() ) {
		$post_types = isset( $args['post_types'] ) ? $args['post_types'] : array( 'post', 'page' );
		$slugs      = isset( $args['slugs'] ) ? $args['slugs'] : array();

		if ( empty( $slugs ) ) {
			return new WP_Error( 'no_slugs', __( 'No slugs provided for selective export.', 'mksddn-migrate-content' ) );
		}

		$export_data = array(
			'version'     => MKSDDN_MC_VERSION,
			'type'        => self::TYPE_SELECTIVE,
			'timestamp'   => current_time( 'mysql' ),
			'site_url'    => get_site_url(),
			'home_url'    => get_home_url(),
			'post_types'  => $post_types,
			'slugs'       => $slugs,
			'posts'       => $this->export_posts_by_slug( $post_types, $slugs ),
			'media'       => $this->export_related_media( $post_types, $slugs ),
			'options'     => $this->export_options(),
		);

		return $export_data;
	}

	/**
	 * Export database content.
	 *
	 * @return array
	 */
	private function export_database() {
		global $wpdb;

		$tables = array();
		$db_tables = $wpdb->get_results( 'SHOW TABLES', ARRAY_N );

		foreach ( $db_tables as $table ) {
			$table_name = $table[0];
			$tables[ $table_name ] = $wpdb->get_results( "SELECT * FROM {$table_name}", ARRAY_A );
		}

		return $tables;
	}

	/**
	 * Export plugins info.
	 *
	 * @return array
	 */
	private function export_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		return array(
			'all'    => $plugins,
			'active' => $active_plugins,
		);
	}

	/**
	 * Export themes info.
	 *
	 * @return array
	 */
	private function export_themes() {
		$themes = wp_get_themes();
		$active_theme = get_option( 'stylesheet' );

		return array(
			'all'    => array_keys( $themes ),
			'active' => $active_theme,
		);
	}

	/**
	 * Export uploads directory info.
	 *
	 * @return array
	 */
	private function export_uploads_info() {
		$upload_dir = wp_upload_dir();
		return array(
			'basedir' => $upload_dir['basedir'],
			'baseurl' => $upload_dir['baseurl'],
		);
	}

	/**
	 * Export WordPress options.
	 *
	 * @return array
	 */
	private function export_options() {
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
	 * Export posts by slug.
	 *
	 * @param array $post_types Post types.
	 * @param array $slugs Slugs.
	 * @return array
	 */
	private function export_posts_by_slug( $post_types, $slugs ) {
		$posts = array();

		foreach ( $post_types as $post_type ) {
			foreach ( $slugs as $slug ) {
				$post = get_page_by_path( $slug, OBJECT, $post_type );
				if ( $post ) {
					$posts[] = $this->export_post_data( $post );
				}
			}
		}

		return $posts;
	}

	/**
	 * Export single post data.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function export_post_data( $post ) {
		$post_data = array(
			'ID'           => $post->ID,
			'post_title'   => $post->post_title,
			'post_name'    => $post->post_name,
			'post_content' => $post->post_content,
			'post_excerpt' => $post->post_excerpt,
			'post_status'  => $post->post_status,
			'post_type'    => $post->post_type,
			'post_date'    => $post->post_date,
			'post_author'  => $post->post_author,
			'meta'         => get_post_meta( $post->ID ),
			'taxonomies'   => $this->export_post_taxonomies( $post->ID ),
		);

		return $post_data;
	}

	/**
	 * Export post taxonomies.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	private function export_post_taxonomies( $post_id ) {
		$taxonomies = array();
		$post_taxonomies = get_object_taxonomies( get_post_type( $post_id ) );

		foreach ( $post_taxonomies as $taxonomy ) {
			$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'all' ) );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$taxonomies[ $taxonomy ] = array();
				foreach ( $terms as $term ) {
					$taxonomies[ $taxonomy ][] = array(
						'term_id'  => $term->term_id,
						'name'     => $term->name,
						'slug'     => $term->slug,
						'taxonomy' => $term->taxonomy,
					);
				}
			}
		}

		return $taxonomies;
	}

	/**
	 * Export media files related to posts.
	 *
	 * @param array $post_types Post types.
	 * @param array $slugs Slugs.
	 * @return array
	 */
	private function export_related_media( $post_types, $slugs ) {
		$media = array();

		foreach ( $post_types as $post_type ) {
			foreach ( $slugs as $slug ) {
				$post = get_page_by_path( $slug, OBJECT, $post_type );
				if ( $post ) {
					$attachments = get_attached_media( 'image', $post->ID );
					foreach ( $attachments as $attachment ) {
						$media[] = array(
							'ID'          => $attachment->ID,
							'guid'        => $attachment->guid,
							'post_title'  => $attachment->post_title,
							'post_name'   => $attachment->post_name,
							'file_path'   => get_attached_file( $attachment->ID ),
							'meta'        => wp_get_attachment_metadata( $attachment->ID ),
						);
					}
				}
			}
		}

		return $media;
	}

	/**
	 * Create export file.
	 *
	 * @param array $data Export data.
	 * @return string|WP_Error File path or error.
	 */
	public function create_export_file( $data ) {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/mksddn-mc-exports';

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		$filename = 'export-' . date( 'Y-m-d-H-i-s' ) . '-' . uniqid() . '.json';
		$filepath = $export_dir . '/' . $filename;

		$json_data = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		if ( false === file_put_contents( $filepath, $json_data ) ) {
			return new WP_Error( 'file_write_error', __( 'Failed to write export file.', 'mksddn-migrate-content' ) );
		}

		return $filepath;
	}
}

