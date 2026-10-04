<?php
/**
 * @file: ContentPickerQueryService.php
 * @description: Builds WP_Query results for the Selected Content export picker (title search + pagination).
 * @dependencies: WordPress WP_Query
 * @created: 2026-10-04
 */

namespace MksDdn\MigrateContent\Admin\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Query service for the Selected Content grid picker.
 */
class ContentPickerQueryService {

	/**
	 * Default posts per page for the picker lists.
	 *
	 * @var int
	 */
	public const PER_PAGE = 50;

	/**
	 * Post types that must never appear in the Selected Content picker.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'nav_menu_item',
	);

	/**
	 * Query var that carries the title LIKE pattern for this picker's WP_Query only.
	 *
	 * @var string
	 */
	private const TITLE_SEARCH_QUERY_VAR = 'mksddn_mc_title_like';

	/**
	 * Exportable post types for the Selected Content UI (slug => label).
	 *
	 * @return array<string, string>
	 */
	public static function get_exportable_post_types(): array {
		$objects = get_post_types(
			array(
				'show_ui' => true,
				'public'  => true,
			),
			'objects'
		);

		$types = array();
		foreach ( $objects as $type => $object ) {
			if ( in_array( $type, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			$types[ $type ] = $object->labels->singular_name ?? $object->label ?? sprintf(
				/* translators: %s: post type slug */
				__( 'Content type: %s', 'mksddn-migrate-content' ),
				$type
			);
		}

		if ( ! isset( $types['page'] ) ) {
			$types = array( 'page' => __( 'Page', 'mksddn-migrate-content' ) ) + $types;
		}

		return $types;
	}

	/**
	 * Whether a post type is allowed in the Selected Content picker.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_exportable_post_type( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return false;
		}

		$types = self::get_exportable_post_types();
		return isset( $types[ $post_type ] );
	}

	/**
	 * Search paginated published posts by title for a picker column.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $search    Title search term.
	 * @param int    $page      1-based page.
	 * @return array{posts: array<int, array{id: int, label: string}>, total: int, page: int, per_page: int, total_pages: int}|\WP_Error
	 */
	public function search_posts( string $post_type, string $search, int $page ) {
		$validated = $this->validate_post_type( $post_type );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$page     = max( 1, $page );
		$per_page = self::PER_PAGE;
		$args     = array(
			'post_type'           => sanitize_key( $post_type ),
			'posts_per_page'      => $per_page,
			'post_status'         => 'publish',
			'orderby'             => 'title',
			'order'               => 'ASC',
			'paged'               => $page,
			'lang'                => '', // All languages (Polylang compatibility).
			'ignore_sticky_posts' => true,
			'suppress_filters'    => false,
		);

		$query = $this->run_query( $args, $search );
		$posts = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post = get_post();
				if ( ! $post instanceof \WP_Post ) {
					continue;
				}

				$label   = $post->post_title !== '' ? $post->post_title : ( '#' . $post->ID );
				$posts[] = array(
					'id'    => (int) $post->ID,
					'label' => $label,
				);
			}
			wp_reset_postdata();
		}

		$total = (int) $query->found_posts;

		return array(
			'posts'       => $posts,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Validate that the post type is allowed in the Selected Content picker.
	 *
	 * @param string $post_type Post type slug.
	 * @return true|\WP_Error
	 */
	private function validate_post_type( string $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return new \WP_Error( 'mksddn_mc_missing_post_type', __( 'Post type is required.', 'mksddn-migrate-content' ) );
		}

		if ( ! self::is_exportable_post_type( $post_type ) ) {
			return new \WP_Error( 'mksddn_mc_invalid_post_type', __( 'Invalid post type.', 'mksddn-migrate-content' ) );
		}

		return true;
	}

	/**
	 * Run WP_Query with an optional title-only LIKE constraint.
	 *
	 * @param array<string, mixed> $args   Query args.
	 * @param string               $search Title search term.
	 * @return \WP_Query
	 */
	private function run_query( array $args, string $search ): \WP_Query {
		$search = trim( $search );
		if ( '' === $search ) {
			return new \WP_Query( $args );
		}

		global $wpdb;
		$args[ self::TITLE_SEARCH_QUERY_VAR ] = '%' . $wpdb->esc_like( $search ) . '%';

		add_filter( 'posts_where', array( $this, 'filter_posts_where_by_title' ), 10, 2 );
		try {
			return new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', array( $this, 'filter_posts_where_by_title' ), 10 );
		}
	}

	/**
	 * Restrict this picker's query to post_title LIKE matches.
	 *
	 * Nested queries do not carry the query var, so the clause is not applied to them.
	 *
	 * @param string    $where Existing WHERE clause.
	 * @param \WP_Query $query Current query.
	 * @return string
	 */
	public function filter_posts_where_by_title( string $where, \WP_Query $query ): string {
		$like = $query->get( self::TITLE_SEARCH_QUERY_VAR );
		if ( ! is_string( $like ) || '' === $like ) {
			return $where;
		}

		global $wpdb;
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $like );
	}
}
