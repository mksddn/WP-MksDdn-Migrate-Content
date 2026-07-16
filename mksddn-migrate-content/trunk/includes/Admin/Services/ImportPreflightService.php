<?php
/**
 * @file: ImportPreflightService.php
 * @description: Read-only preflight analysis for unified import (dry-run)
 * @dependencies: ImportPayloadPreparer, Users\UserDiffBuilder, Support\MimeTypeHelper
 * @created: 2026-04-08
 */

namespace MksDdn\MigrateContent\Admin\Services;

use MksDdn\MigrateContent\Support\MimeTypeHelper;
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

	private const THEME_ARCHIVE_PREFIX = 'wp-content/themes/';

	/**
	 * Max sample paths stored per action (add/overwrite) per theme.
	 */
	private const THEME_FILE_SAMPLE_CAP = 40;

	/**
	 * Payload preparer.
	 *
	 * @var ImportPayloadPreparer
	 */
	private ImportPayloadPreparer $payload_preparer;

	/**
	 * Constructor.
	 *
	 * @param ImportPayloadPreparer|null $payload_preparer Payload preparer.
	 */
	public function __construct( ?ImportPayloadPreparer $payload_preparer = null ) {
		$this->payload_preparer = $payload_preparer ?? new ImportPayloadPreparer();
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

		$warnings       = array();
		$errors         = array();
		$slug_conflicts = array();
		$content_items  = array();

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
				if ( ! empty( $row['existing_post_id'] ) ) {
					$slug_conflicts[] = array(
						'slug'      => $row['slug'],
						'post_type' => $row['post_type'],
						'post_id'   => $row['existing_post_id'],
					);
				}
			}
		} else {
			$row = $this->build_selected_content_row( $payload, $type );
			if ( ! empty( $row ) ) {
				$content_items[] = $row;
				if ( ! empty( $row['existing_post_id'] ) ) {
					$slug_conflicts[] = array(
						'slug'      => $row['slug'],
						'post_type' => $row['post_type'],
						'post_id'   => $row['existing_post_id'],
					);
				}
			} elseif ( empty( $payload['slug'] ) ) {
				$warnings[] = __( 'Payload has no slug; the importer may generate one at run time.', 'mksddn-migrate-content' );
			}
		}

		if ( ! empty( $slug_conflicts ) ) {
			$warnings[] = __( 'Some slugs already exist on this site; existing posts may be updated.', 'mksddn-migrate-content' );
		}

		$media_count = count( $media );
		if ( 'archive' === ( $prepared['media_source'] ?? '' ) && $media_count > 0 ) {
			$warnings[] = __( 'Archive includes media files; real import will write uploads.', 'mksddn-migrate-content' );
		}

		$status = ! empty( $errors ) ? 'error' : ( ! empty( $warnings ) ? 'warning' : 'ok' );

		return array(
			'status'              => $status,
			'import_type'         => 'selected',
			'source'              => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'             => array(
				'file_name'            => $file_info['name'] ?? basename( $path ),
				'file_size'            => $this->file_size( $path ),
				'payload_type'         => $type,
				'item_count'           => count( $content_items ),
				'media_files'          => $media_count,
				'slug_conflicts_count' => count( $slug_conflicts ),
			),
			'warnings'            => $warnings,
			'errors'              => $errors,
			'estimated_changes'   => array(
				'items'          => $content_items,
				'slug_conflicts' => $slug_conflicts,
			),
			'next_step'           => __( 'Use “Start import” below to run the real import with the same file (no upload needed).', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * Build one inventory row for selected-content preflight.
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

		$title    = isset( $item['title'] ) ? sanitize_text_field( (string) $item['title'] ) : '';
		$existing = $this->find_existing_post_id( $slug, $ptype );

		return array(
			'title'            => $title,
			'slug'             => $slug,
			'post_type'        => $ptype,
			'action'           => $existing > 0 ? 'update' : 'create',
			'existing_post_id' => $existing,
		);
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
		$prefix        = self::THEME_ARCHIVE_PREFIX;
		$cap           = self::THEME_FILE_SAMPLE_CAP;

		/** @var array<string, array{added:int,overwrite:int,sample_added:string[],sample_overwrite:string[],truncated_added:bool,truncated_overwrite:bool}> $buckets */
		$buckets = array();

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

			$normalized = $this->normalize_theme_archive_path( (string) $stat['name'] );
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

			$dest   = $theme_root . $slug . '/' . $rel_in_theme;
			$action = is_file( $dest ) ? 'overwrite' : 'added';

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
	 * Normalize theme archive entry path (mirrors ThemeImporter rules).
	 *
	 * @param string $path Raw zip entry name.
	 * @return string|null Normalized path or null if skipped/invalid.
	 */
	private function normalize_theme_archive_path( string $path ): ?string {
		if ( '' === $path ) {
			return null;
		}

		$path = str_replace( '\\', '/', $path );
		if ( false !== strpos( $path, "\0" ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'manifest' ) || 0 === strpos( $path, 'payload/' ) ) {
			return null;
		}

		if ( 0 === strpos( $path, 'files/' ) ) {
			$path = substr( $path, 6 );
		}

		$path = ltrim( $path, '/' );
		if ( '' === $path ) {
			return null;
		}

		$parts = explode( '/', $path );
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				return null;
			}
		}

		return $path;
	}

	/**
	 * Find existing post id by slug and type.
	 *
	 * @param string $slug      Post slug.
	 * @param string $post_type Post type.
	 * @return int 0 if none.
	 */
	private function find_existing_post_id( string $slug, string $post_type ): int {
		if ( '' === $slug ) {
			return 0;
		}
		$query = new WP_Query(
			array(
				'name'           => $slug,
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		if ( $query->have_posts() ) {
			$ids = $query->posts;
			return isset( $ids[0] ) ? (int) $ids[0] : 0;
		}
		return 0;
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
