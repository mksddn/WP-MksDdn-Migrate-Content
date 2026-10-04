<?php
/**
 * @file: ImportPreflightService.php
 * @description: Read-only preflight analysis for unified import (dry-run)
 * @dependencies: ImportPayloadPreparer, Users\UserDiffBuilder, Support\MimeTypeHelper, Support\ThemeArchivePathHelper
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Options\OptionsHelper;
use MksDdn\MigrateContent\Support\MimeTypeHelper;
use MksDdn\MigrateContent\Support\ThemeArchivePathHelper;
use MksDdn\MigrateContent\Users\UserDiffBuilder;
use WP_Error;
use WP_Query;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds normalized preflight reports without running imports.
 *
 * @since 2.2.0
 */
class ImportPreflightService {

	/**
	 * Max sample paths stored per action (add/overwrite) per theme.
	 */
	private const THEME_FILE_SAMPLE_CAP = 40;

	/**
	 * Max slugs per WP_Query when resolving existing posts.
	 */
	private const SLUG_LOOKUP_CHUNK = 100;

	/**
	 * Payload preparer.
	 *
	 * @var ImportPayloadPreparer
	 */
	private ImportPayloadPreparer $payload_preparer;

	/**
	 * ACF Options Pages helper.
	 *
	 * @var OptionsHelper
	 */
	private OptionsHelper $options_helper;

	/**
	 * Constructor.
	 *
	 * @param ImportPayloadPreparer|null $payload_preparer Payload preparer.
	 * @param OptionsHelper|null         $options_helper   ACF options helper.
	 */
	public function __construct( ?ImportPayloadPreparer $payload_preparer = null, ?OptionsHelper $options_helper = null ) {
		$this->payload_preparer = $payload_preparer ?? new ImportPayloadPreparer();
		$this->options_helper   = $options_helper ?? new OptionsHelper();
	}

	/**
	 * Run analysis for resolved file and detected import type.
	 *
	 * @param array  $file_info   Resolved file info from UnifiedImportOrchestrator.
	 * @param string $import_type full|themes|selected.
	 * @return array Normalized report (v1 contract).
	 */
	public function analyze( array $file_info, string $import_type ): array {
		switch ( $import_type ) {
			case 'full':
				return $this->analyze_full( $file_info );
			case 'themes':
				return $this->analyze_themes( $file_info );
			case 'selected':
			default:
				return $this->analyze_selected( $file_info );
		}
	}

	/**
	 * Map internal source to report source value.
	 *
	 * @param string $source Internal source key.
	 * @return string upload|server|chunk.
	 */
	private function normalize_source( string $source ): string {
		if ( 'chunked' === $source ) {
			return 'chunk';
		}
		if ( 'server' === $source ) {
			return 'server';
		}
		return 'upload';
	}

	/**
	 * File size if readable.
	 *
	 * @param string $path Absolute path.
	 * @return int Bytes.
	 */
	private function file_size( string $path ): int {
		if ( ! $path || ! file_exists( $path ) ) {
			return 0;
		}
		$s = filesize( $path );
		return false !== $s ? (int) $s : 0;
	}

	/**
	 * Preflight for selected content (JSON or .wpbkp manifest).
	 *
	 * @param array $file_info File info.
	 * @return array
	 */
	private function analyze_selected( array $file_info ): array {
		$path      = $file_info['path'];
		$extension = $file_info['extension'] ?? '';
		$mime      = MimeTypeHelper::detect( $path, $extension );

		$prepared = $this->payload_preparer->prepare( $extension, $mime, $path );
		if ( is_wp_error( $prepared ) ) {
			return $this->failure_report(
				'selected',
				$file_info,
				array( $prepared->get_error_message() )
			);
		}

		$payload = $prepared['payload'];
		$type    = sanitize_key( $prepared['type'] ?? 'page' );
		$media   = isset( $prepared['media'] ) && is_array( $prepared['media'] ) ? $prepared['media'] : array();

		$warnings               = array();
		$errors                 = array();
		$slug_conflicts         = array();
		$content_items          = array();
		$options_pages          = array();
		$options_may_need_media = false;

		if ( 'bundle' === $type ) {
			$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$row = $this->build_selected_content_row( $item );
				if ( empty( $row ) ) {
					continue;
				}
				$content_items[] = $row;
			}

			$raw_options_pages = isset( $payload['options_pages'] ) && is_array( $payload['options_pages'] )
				? $payload['options_pages']
				: array();
			foreach ( $raw_options_pages as $page ) {
				if ( ! is_array( $page ) ) {
					$errors[] = __( 'Archive contains an invalid ACF Options Page entry.', 'mksddn-migrate-content' );
					continue;
				}
				$row = $this->build_options_page_row( $page );
				if ( empty( $row ) ) {
					$errors[] = __( 'Archive contains an ACF Options Page without a valid menu_slug.', 'mksddn-migrate-content' );
					continue;
				}
				$options_pages[] = $row;
				if ( $this->options_page_payload_may_reference_media( $page ) ) {
					$options_may_need_media = true;
				}
			}
		} else {
			$row = $this->build_selected_content_row( $payload, $type );
			if ( ! empty( $row ) ) {
				$content_items[] = $row;
			} elseif ( empty( $payload['slug'] ) ) {
				$warnings[] = __( 'Payload has no slug; the importer may generate one at run time.', 'mksddn-migrate-content' );
			}
		}

		$this->attach_existing_post_ids( $content_items );

		foreach ( $content_items as $row ) {
			if ( ! empty( $row['existing_post_id'] ) ) {
				$slug_conflicts[] = array(
					'slug'      => $row['slug'],
					'post_type' => $row['post_type'],
					'post_id'   => $row['existing_post_id'],
				);
			}
		}

		if ( ! empty( $slug_conflicts ) ) {
			$warnings[] = __( 'Some slugs already exist on this site; existing posts may be updated.', 'mksddn-migrate-content' );
		}

		if ( ! empty( $options_pages ) && ! function_exists( 'update_field' ) ) {
			$errors[] = __( 'This archive includes ACF Options Pages, but Advanced Custom Fields is not available on this site.', 'mksddn-migrate-content' );
		} elseif ( ! empty( $options_pages ) ) {
			$missing_count = 0;
			foreach ( $options_pages as $options_page_row ) {
				if ( isset( $options_page_row['action'] ) && 'missing' === $options_page_row['action'] ) {
					++$missing_count;
				}
			}

			if ( $missing_count > 0 ) {
				$errors[] = sprintf(
					/* translators: %d: number of missing ACF Options Pages */
					_n(
						'%d ACF Options Page from the archive is not registered on this site (matched by menu_slug).',
						'%d ACF Options Pages from the archive are not registered on this site (matched by menu_slug).',
						$missing_count,
						'mksddn-migrate-content'
					),
					$missing_count
				);
			} else {
				$warnings[] = __( 'ACF Options Page fields will be overwritten on import. There is no automatic rollback if a later step fails.', 'mksddn-migrate-content' );
			}

			foreach ( $options_pages as $options_page_row ) {
				if ( empty( $options_page_row['post_id_mismatch'] ) ) {
					continue;
				}
				$warnings[] = sprintf(
					/* translators: 1: menu_slug, 2: archive post_id, 3: local post_id */
					__( 'ACF Options Page "%1$s" uses a different post_id locally (%3$s) than in the archive (%2$s); import will write to the local post_id.', 'mksddn-migrate-content' ),
					(string) ( $options_page_row['menu_slug'] ?? '' ),
					(string) ( $options_page_row['archive_post_id'] ?? '' ),
					(string) ( $options_page_row['local_post_id'] ?? '' )
				);
			}
		}

		$media_count  = count( $media );
		$media_source = (string) ( $prepared['media_source'] ?? '' );
		if ( 'archive' === $media_source && $media_count > 0 ) {
			$warnings[] = __( 'Archive includes media files; real import will write uploads.', 'mksddn-migrate-content' );
		}

		if ( 'json' === $media_source && $options_may_need_media ) {
			$warnings[] = __( 'This JSON payload appears to reference media in ACF Options Page fields, but JSON cannot carry media files. Re-export as .wpbkp, or image/file fields may point to missing attachments.', 'mksddn-migrate-content' );
		}

		$status = ! empty( $errors ) ? 'error' : ( ! empty( $warnings ) ? 'warning' : 'ok' );

		if ( 'error' === $status ) {
			$next_step = __( 'Resolve the errors above, then run preflight again. Start import is unavailable until preflight succeeds.', 'mksddn-migrate-content' );
		} else {
			$next_step = __( 'Use “Start import” below to run the real import with the same file (no upload needed).', 'mksddn-migrate-content' );
		}

		return array(
			'status'              => $status,
			'import_type'         => 'selected',
			'source'              => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'             => array(
				'file_name'              => $file_info['name'] ?? basename( $path ),
				'file_size'              => $this->file_size( $path ),
				'payload_type'           => $type,
				'item_count'             => count( $content_items ),
				'options_pages_count'    => count( $options_pages ),
				'media_files'            => $media_count,
				'slug_conflicts_count'   => count( $slug_conflicts ),
			),
			'warnings'            => $warnings,
			'errors'              => $errors,
			'estimated_changes'   => array(
				'items'          => $content_items,
				'options_pages'  => $options_pages,
				'slug_conflicts' => $slug_conflicts,
			),
			'next_step'           => $next_step,
		);
	}

	/**
	 * Whether an Options Page payload looks like it references media (IDs/URLs/HTML).
	 *
	 * Used for JSON preflight warnings; does not require attachments to exist locally.
	 *
	 * @param array $page Options page payload.
	 */
	private function options_page_payload_may_reference_media( array $page ): bool {
		if ( ! empty( $page['_mksddn_media'] ) && is_array( $page['_mksddn_media'] ) ) {
			return true;
		}

		$fields = array();
		if ( isset( $page['acf_fields'] ) && is_array( $page['acf_fields'] ) ) {
			$fields = $page['acf_fields'];
		} elseif ( isset( $page['data'] ) && is_array( $page['data'] ) ) {
			$fields = $page['data'];
		}

		$schema = ( isset( $page['acf_field_schema'] ) && is_array( $page['acf_field_schema'] ) ) ? $page['acf_field_schema'] : array();
		if ( array() !== $schema ) {
			return $this->schema_fields_reference_media( $fields, $schema );
		}

		return $this->acf_values_may_reference_media( $fields );
	}

	/**
	 * Whether typed ACF fields reference media (image/file/gallery or embedded HTML).
	 *
	 * Bare numbers are media only when the field type is image, file, or gallery.
	 *
	 * @param array $values Name => value.
	 * @param array $schema Name => schema node.
	 */
	private function schema_fields_reference_media( array $values, array $schema ): bool {
		foreach ( $values as $name => $child ) {
			if ( 'acf_fc_layout' === (string) $name ) {
				continue;
			}
			$field_schema = ( isset( $schema[ $name ] ) && is_array( $schema[ $name ] ) ) ? $schema[ $name ] : array();
			if ( $this->schema_value_references_media( $child, $field_schema ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether one typed field value references media.
	 *
	 * @param mixed $value  Field value.
	 * @param array $schema Field schema node.
	 */
	private function schema_value_references_media( $value, array $schema ): bool {
		$type = (string) ( $schema['type'] ?? '' );

		if ( in_array( $type, array( 'image', 'file' ), true ) ) {
			return $this->attachment_leaf_is_set( $value );
		}

		if ( 'gallery' === $type ) {
			if ( is_string( $value ) && preg_match( '/^\d+(?:\s*,\s*\d+)*$/', $value ) ) {
				return true;
			}
			if ( ! is_array( $value ) ) {
				return $this->attachment_leaf_is_set( $value );
			}
			foreach ( $value as $item ) {
				if ( $this->attachment_leaf_is_set( $item ) ) {
					return true;
				}
			}
			return false;
		}

		if ( in_array( $type, array( 'wysiwyg', 'textarea' ), true ) ) {
			return is_string( $value ) && $this->string_references_embedded_media( $value );
		}

		if ( in_array( $type, array( 'group', 'clone' ), true ) && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			return $this->schema_fields_reference_media( $value, $sub );
		}

		if ( 'repeater' === $type && is_array( $value ) ) {
			$sub = ( isset( $schema['sub_fields'] ) && is_array( $schema['sub_fields'] ) ) ? $schema['sub_fields'] : array();
			foreach ( $value as $row ) {
				if ( is_array( $row ) && $this->schema_fields_reference_media( $row, $sub ) ) {
					return true;
				}
			}
			return false;
		}

		if ( 'flexible_content' === $type && is_array( $value ) ) {
			$layouts = ( isset( $schema['layouts'] ) && is_array( $schema['layouts'] ) ) ? $schema['layouts'] : array();
			foreach ( $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$layout_name = isset( $row['acf_fc_layout'] ) ? (string) $row['acf_fc_layout'] : '';
				$sub         = ( isset( $layouts[ $layout_name ]['sub_fields'] ) && is_array( $layouts[ $layout_name ]['sub_fields'] ) )
					? $layouts[ $layout_name ]['sub_fields']
					: array();
				if ( $this->schema_fields_reference_media( $row, $sub ) ) {
					return true;
				}
			}
			return false;
		}

		return $this->acf_values_may_reference_media( $value );
	}

	/**
	 * Whether an image/file leaf has an ID, uploads URL, or attachment array.
	 *
	 * @param mixed $value Leaf value.
	 */
	private function attachment_leaf_is_set( $value ): bool {
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		if ( is_string( $value ) ) {
			return $this->string_references_embedded_media( $value );
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		$has_id = ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) && (int) $value['ID'] > 0 )
			|| ( isset( $value['id'] ) && is_numeric( $value['id'] ) && (int) $value['id'] > 0 );
		if ( $has_id && ( isset( $value['url'] ) || isset( $value['filename'] ) || isset( $value['mime_type'] ) || isset( $value['sizes'] ) || isset( $value['type'] ) ) ) {
			return true;
		}

		return isset( $value['url'] ) && is_string( $value['url'] ) && $this->string_references_embedded_media( $value['url'] );
	}

	/**
	 * Heuristic for payloads without field schema.
	 *
	 * Bare integers are not treated as media. Uploads URLs, wp-image markup,
	 * gallery shortcodes, and attachment arrays are.
	 *
	 * @param mixed $value Value node.
	 */
	private function acf_values_may_reference_media( $value ): bool {
		if ( is_string( $value ) ) {
			return $this->string_references_embedded_media( $value );
		}

		if ( ! is_array( $value ) ) {
			return false;
		}

		$has_id = ( isset( $value['ID'] ) && is_numeric( $value['ID'] ) )
			|| ( isset( $value['id'] ) && is_numeric( $value['id'] ) );
		if ( $has_id && ( isset( $value['url'] ) || isset( $value['filename'] ) || isset( $value['mime_type'] ) || isset( $value['sizes'] ) || isset( $value['type'] ) ) ) {
			return true;
		}

		foreach ( $value as $child ) {
			if ( $this->acf_values_may_reference_media( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a string contains an uploads URL, wp-image class, or gallery shortcode.
	 *
	 * @param string $value Candidate string.
	 */
	private function string_references_embedded_media( string $value ): bool {
		if ( '' === $value ) {
			return false;
		}

		if ( false !== strpos( $value, '/wp-content/uploads/' ) ) {
			return true;
		}

		return 1 === preg_match( '/wp-image-\d+/', $value ) || false !== strpos( $value, '[gallery' );
	}

	/**
	 * Build one inventory row for an ACF Options Page preflight entry.
	 *
	 * @param array $page Options page payload.
	 * @return array{title:string,menu_slug:string,post_id:string,archive_post_id:string,local_post_id:string,action:string,post_id_mismatch:bool}|array{}
	 */
	private function build_options_page_row( array $page ): array {
		$menu_slug = sanitize_key( (string) ( $page['menu_slug'] ?? '' ) );
		if ( '' === $menu_slug ) {
			return array();
		}

		$title = isset( $page['page_title'] ) ? sanitize_text_field( (string) $page['page_title'] ) : '';
		if ( '' === $title && isset( $page['menu_title'] ) ) {
			$title = sanitize_text_field( (string) $page['menu_title'] );
		}
		if ( '' === $title ) {
			$title = $menu_slug;
		}

		$archive_post_id = sanitize_text_field( (string) ( $page['post_id'] ?? '' ) );
		$local           = $this->options_helper->find_options_page_by_slug( $menu_slug );
		$local_post_id   = '';
		$action          = 'missing';

		if ( is_array( $local ) && isset( $local['post_id'] ) && '' !== (string) $local['post_id'] ) {
			$local_post_id = sanitize_text_field( (string) $local['post_id'] );
			$action        = 'update';
		}

		$post_id_mismatch = (
			'update' === $action
			&& '' !== $archive_post_id
			&& '' !== $local_post_id
			&& $archive_post_id !== $local_post_id
		);

		return array(
			'title'            => $title,
			'menu_slug'        => $menu_slug,
			'post_id'          => '' !== $local_post_id ? $local_post_id : $archive_post_id,
			'archive_post_id'  => $archive_post_id,
			'local_post_id'    => $local_post_id,
			'action'           => $action,
			'post_id_mismatch' => $post_id_mismatch,
		);
	}

	/**
	 * Build one inventory row for selected-content preflight (without DB lookup).
	 *
	 * @param array  $item          Payload item or single-item payload.
	 * @param string $default_type  Fallback post type when item has none.
	 * @return array{title:string,slug:string,post_type:string,action:string,existing_post_id:int}|array{}
	 */
	private function build_selected_content_row( array $item, string $default_type = 'page' ): array {
		$ptype = sanitize_key( $item['type'] ?? $default_type );
		if ( '' === $ptype ) {
			$ptype = 'page';
		}
		$slug = isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '';
		if ( '' === $slug ) {
			return array();
		}

		$title = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';

		return array(
			'title'            => $title,
			'slug'             => $slug,
			'post_type'        => $ptype,
			'action'           => 'create',
			'existing_post_id' => 0,
		);
	}

	/**
	 * Resolve existing post IDs in batches and set action=update where found.
	 *
	 * @param array<int, array{title:string,slug:string,post_type:string,action:string,existing_post_id:int}> $items Rows by reference.
	 * @return void
	 */
	private function attach_existing_post_ids( array &$items ): void {
		if ( empty( $items ) ) {
			return;
		}

		/** @var array<string, array<string, int[]>> $by_type slug => list of item indexes */
		$by_type = array();
		foreach ( $items as $index => $row ) {
			$ptype = $row['post_type'];
			$slug  = $row['slug'];
			if ( ! isset( $by_type[ $ptype ] ) ) {
				$by_type[ $ptype ] = array();
			}
			if ( ! isset( $by_type[ $ptype ][ $slug ] ) ) {
				$by_type[ $ptype ][ $slug ] = array();
			}
			$by_type[ $ptype ][ $slug ][] = $index;
		}

		foreach ( $by_type as $post_type => $slug_indexes ) {
			$slugs  = array_keys( $slug_indexes );
			$chunks = array_chunk( $slugs, self::SLUG_LOOKUP_CHUNK );

			foreach ( $chunks as $chunk ) {
				$query = new WP_Query(
					array(
						'post_type'              => $post_type,
						'post_name__in'          => $chunk,
						'post_status'            => 'any',
						'posts_per_page'         => count( $chunk ),
						'no_found_rows'          => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					)
				);

				foreach ( $query->posts as $post ) {
					if ( ! $post instanceof \WP_Post ) {
						continue;
					}
					$slug = $post->post_name;
					if ( ! isset( $slug_indexes[ $slug ] ) ) {
						continue;
					}
					foreach ( $slug_indexes[ $slug ] as $index ) {
						$items[ $index ]['existing_post_id'] = (int) $post->ID;
						$items[ $index ]['action']           = 'update';
					}
				}
			}
		}
	}

	/**
	 * Preflight for full-site archive.
	 *
	 * @param array $file_info File info.
	 * @return array
	 */
	private function analyze_full( array $file_info ): array {
		$path = $file_info['path'];
		$diff = ( new UserDiffBuilder() )->build( $path );

		if ( is_wp_error( $diff ) ) {
			return $this->failure_report(
				'full',
				$file_info,
				array( $diff->get_error_message() )
			);
		}

		$warnings = array();
		$incoming = isset( $diff['counts']['incoming'] ) ? (int) $diff['counts']['incoming'] : 0;
		$conflicts = isset( $diff['counts']['conflicts'] ) ? (int) $diff['counts']['conflicts'] : 0;

		if ( $incoming > 0 ) {
			$warnings[] = __( 'Archive contains WordPress users; you may see a merge step during real import.', 'mksddn-migrate-content' );
		}
		if ( $conflicts > 0 ) {
			$warnings[] = __( 'Some user emails may conflict with existing accounts.', 'mksddn-migrate-content' );
		}

		$warnings[] = __( 'Full import will replace database content and files from the archive.', 'mksddn-migrate-content' );

		$status = ! empty( $warnings ) ? 'warning' : 'ok';

		return array(
			'status'            => $status,
			'import_type'       => 'full',
			'source'            => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'           => array(
				'file_name'       => $file_info['name'] ?? basename( $path ),
				'file_size'       => $this->file_size( $path ),
				'users_in_archive'=> $incoming,
				'user_conflicts'  => $conflicts,
			),
			'warnings'          => $warnings,
			'errors'            => array(),
			'estimated_changes' => array(
				'incoming_users' => $incoming,
				'user_conflicts' => $conflicts,
			),
			'next_step'         => __( 'Use “Start import” below to run the real import with the same file (no upload needed).', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * Preflight for theme archives.
	 *
	 * @param array $file_info File info.
	 * @return array
	 */
	private function analyze_themes( array $file_info ): array {
		$path = $file_info['path'];
		$diff = $this->build_theme_file_diff( $path );

		if ( is_wp_error( $diff ) ) {
			return $this->failure_report(
				'themes',
				$file_info,
				array( $diff->get_error_message() )
			);
		}

		$slugs    = $diff['slugs'];
		$themes   = $diff['themes'];
		$existing = array();
		foreach ( $themes as $theme_row ) {
			if ( ! empty( $theme_row['exists'] ) ) {
				$existing[] = $theme_row['slug'];
			}
		}

		$warnings = array();
		if ( ! empty( $existing ) ) {
			$warnings[] = __( 'Some themes already exist on this site. Merge overwrites matching files; Replace removes the whole theme directory first.', 'mksddn-migrate-content' );
		}
		if ( $diff['files_overwrite'] > 0 ) {
			$warnings[] = __( 'File counts below assume Merge mode (add new files, overwrite existing paths). Replace would wipe existing theme folders before writing.', 'mksddn-migrate-content' );
		}

		$status = ! empty( $warnings ) ? 'warning' : 'ok';

		return array(
			'status'            => $status,
			'import_type'       => 'themes',
			'source'            => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'           => array(
				'file_name'       => $file_info['name'] ?? basename( $path ),
				'file_size'       => $this->file_size( $path ),
				'theme_count'     => count( $slugs ),
				'themes'          => $slugs,
				'existing_slugs'  => $existing,
				'files_total'     => $diff['files_total'],
				'files_added'     => $diff['files_added'],
				'files_overwrite' => $diff['files_overwrite'],
			),
			'warnings'          => $warnings,
			'errors'            => array(),
			'estimated_changes' => array(
				'theme_slugs' => $slugs,
				'theme_files' => $themes,
			),
			'next_step'         => __( 'Use “Start import” below; theme import will show its confirmation step.', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * Build per-theme file inventory: added vs overwrite (merge semantics).
	 *
	 * @param string $path Archive path.
	 * @return array{slugs:string[],themes:array<int,array>,files_total:int,files_added:int,files_overwrite:int}|WP_Error
	 */
	private function build_theme_file_diff( string $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'mksddn_mc_zip_open', __( 'Unable to open archive.', 'mksddn-migrate-content' ) );
		}

		$from_manifest = $this->read_theme_slugs_from_manifest( $zip );
		$theme_root    = trailingslashit( get_theme_root() );
		$prefix        = ThemeArchivePathHelper::ARCHIVE_PREFIX;
		$cap           = self::THEME_FILE_SAMPLE_CAP;

		/** @var array<string, array{added:int,overwrite:int,sample_added:string[],sample_overwrite:string[],truncated_added:bool,truncated_overwrite:bool}> $buckets */
		$buckets = array();

		/** @var array<string, bool> $theme_dir_exists */
		$theme_dir_exists = array();

		foreach ( $from_manifest as $manifest_slug ) {
			if ( ! isset( $buckets[ $manifest_slug ] ) ) {
				$buckets[ $manifest_slug ] = array(
					'added'               => 0,
					'overwrite'           => 0,
					'sample_added'        => array(),
					'sample_overwrite'    => array(),
					'truncated_added'     => false,
					'truncated_overwrite' => false,
				);
			}
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}

			$normalized = ThemeArchivePathHelper::normalize( (string) $stat['name'] );
			if ( null === $normalized ) {
				continue;
			}

			if ( 0 !== strpos( $normalized, $prefix ) ) {
				continue;
			}

			$relative = substr( $normalized, strlen( $prefix ) );
			if ( '' === $relative ) {
				continue;
			}

			$parts = explode( '/', $relative, 2 );
			$slug  = $parts[0] ?? '';
			if ( '' === $slug || false !== strpos( $slug, '..' ) ) {
				continue;
			}

			$slug = sanitize_file_name( $slug );
			if ( '' === $slug ) {
				continue;
			}

			if ( ! isset( $buckets[ $slug ] ) ) {
				$buckets[ $slug ] = array(
					'added'               => 0,
					'overwrite'           => 0,
					'sample_added'        => array(),
					'sample_overwrite'    => array(),
					'truncated_added'     => false,
					'truncated_overwrite' => false,
				);
			}

			$is_directory = '/' === substr( $normalized, -1 );
			if ( $is_directory ) {
				continue;
			}

			$rel_in_theme = isset( $parts[1] ) ? $parts[1] : '';
			if ( '' === $rel_in_theme ) {
				continue;
			}

			if ( ! isset( $theme_dir_exists[ $slug ] ) ) {
				$theme_dir_exists[ $slug ] = is_dir( $theme_root . $slug );
			}

			// New theme folder: all archive files are adds (skip per-file is_file).
			if ( ! $theme_dir_exists[ $slug ] ) {
				$action = 'added';
			} else {
				$dest   = $theme_root . $slug . '/' . $rel_in_theme;
				$action = is_file( $dest ) ? 'overwrite' : 'added';
			}

			++$buckets[ $slug ][ $action ];

			$sample_key    = 'overwrite' === $action ? 'sample_overwrite' : 'sample_added';
			$truncated_key = 'overwrite' === $action ? 'truncated_overwrite' : 'truncated_added';
			if ( count( $buckets[ $slug ][ $sample_key ] ) < $cap ) {
				$buckets[ $slug ][ $sample_key ][] = $rel_in_theme;
			} else {
				$buckets[ $slug ][ $truncated_key ] = true;
			}
		}
		$zip->close();

		if ( empty( $buckets ) ) {
			return new WP_Error( 'mksddn_mc_no_themes_in_archive', __( 'No themes found in archive.', 'mksddn-migrate-content' ) );
		}

		$themes          = array();
		$files_total     = 0;
		$files_added     = 0;
		$files_overwrite = 0;

		ksort( $buckets, SORT_STRING );
		foreach ( $buckets as $slug => $bucket ) {
			$theme_exists = is_dir( $theme_root . $slug ) || wp_get_theme( $slug )->exists();
			$file_count   = (int) $bucket['added'] + (int) $bucket['overwrite'];
			$files_total += $file_count;
			$files_added += (int) $bucket['added'];
			$files_overwrite += (int) $bucket['overwrite'];

			$themes[] = array(
				'slug'                  => $slug,
				'exists'                => $theme_exists,
				'file_count'            => $file_count,
				'added_count'           => (int) $bucket['added'],
				'overwrite_count'       => (int) $bucket['overwrite'],
				'sample_added'          => $bucket['sample_added'],
				'sample_overwrite'      => $bucket['sample_overwrite'],
				'samples_truncated_added'=> (bool) $bucket['truncated_added'],
				'samples_truncated_overwrite' => (bool) $bucket['truncated_overwrite'],
			);
		}

		return array(
			'slugs'           => array_keys( $buckets ),
			'themes'          => $themes,
			'files_total'     => $files_total,
			'files_added'     => $files_added,
			'files_overwrite' => $files_overwrite,
		);
	}

	/**
	 * Read theme slugs from archive manifest.json when present.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return string[]
	 */
	private function read_theme_slugs_from_manifest( ZipArchive $zip ): array {
		$from_manifest = array();
		$raw_manifest  = $zip->getFromName( 'manifest.json' );
		if ( false === $raw_manifest || '' === $raw_manifest ) {
			return $from_manifest;
		}

		$manifest = json_decode( $raw_manifest, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) || ! isset( $manifest['themes'] ) || ! is_array( $manifest['themes'] ) ) {
			return $from_manifest;
		}

		foreach ( $manifest['themes'] as $t ) {
			if ( is_string( $t ) && '' !== $t ) {
				$from_manifest[] = sanitize_file_name( $t );
			} elseif ( is_array( $t ) && isset( $t['slug'] ) ) {
				$from_manifest[] = sanitize_file_name( (string) $t['slug'] );
			}
		}

		return array_values( array_filter( $from_manifest ) );
	}

	/**
	 * Build error-shaped report.
	 *
	 * @param string $import_type Type key.
	 * @param array  $file_info   File info.
	 * @param array  $errors      Error messages.
	 * @return array
	 */
	private function failure_report( string $import_type, array $file_info, array $errors ): array {
		return array(
			'status'            => 'error',
			'import_type'       => $import_type,
			'source'            => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'           => array(
				'file_name' => $file_info['name'] ?? '',
				'file_size' => $this->file_size( $file_info['path'] ?? '' ),
			),
			'warnings'          => array(),
			'errors'            => $errors,
			'estimated_changes' => array(),
			'next_step'         => __( 'Fix the issues above, then try again.', 'mksddn-migrate-content' ),
		);
	}
}
