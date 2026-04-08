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

		$warnings = array();
		$errors   = array();
		$slug_conflicts = array();

		if ( 'bundle' === $type ) {
			$items = isset( $payload['items'] ) && is_array( $payload['items'] ) ? $payload['items'] : array();
			foreach ( $items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$ptype = sanitize_key( $item['type'] ?? 'page' );
				$slug  = isset( $item['slug'] ) ? sanitize_title( (string) $item['slug'] ) : '';
				if ( ! $slug ) {
					continue;
				}
				$existing = $this->find_existing_post_id( $slug, $ptype );
				if ( $existing ) {
					$slug_conflicts[] = array(
						'slug'     => $slug,
						'post_type'=> $ptype,
						'post_id'  => $existing,
					);
				}
			}
		} else {
			$ptype = sanitize_key( $payload['type'] ?? $type );
			$slug  = isset( $payload['slug'] ) ? sanitize_title( (string) $payload['slug'] ) : '';
			if ( $slug ) {
				$existing = $this->find_existing_post_id( $slug, $ptype );
				if ( $existing ) {
					$slug_conflicts[] = array(
						'slug'      => $slug,
						'post_type' => $ptype,
						'post_id'   => $existing,
					);
				}
			} else {
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
				'file_name'   => $file_info['name'] ?? basename( $path ),
				'file_size'   => $this->file_size( $path ),
				'payload_type'=> $type,
				'media_files' => $media_count,
				'slug_conflicts_count' => count( $slug_conflicts ),
			),
			'warnings'            => $warnings,
			'errors'              => $errors,
			'estimated_changes'   => array(
				'slug_conflicts' => $slug_conflicts,
			),
			'next_step'           => __( 'Uncheck "Preflight only" and submit again with the same file to run the real import.', 'mksddn-migrate-content' ),
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
			'next_step'         => __( 'Uncheck "Preflight only" and submit again with the same file to run the real import.', 'mksddn-migrate-content' ),
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
		$slugs = $this->list_theme_slugs_from_archive( $path );

		if ( is_wp_error( $slugs ) ) {
			return $this->failure_report(
				'themes',
				$file_info,
				array( $slugs->get_error_message() )
			);
		}

		$existing = array();
		foreach ( $slugs as $slug ) {
			$t = wp_get_theme( $slug );
			if ( $t->exists() ) {
				$existing[] = $slug;
			}
		}

		$warnings = array();
		if ( ! empty( $existing ) ) {
			$warnings[] = __( 'These theme directories already exist; replace mode would remove them before import.', 'mksddn-migrate-content' );
		}

		$status = ! empty( $warnings ) ? 'warning' : 'ok';

		return array(
			'status'            => $status,
			'import_type'       => 'themes',
			'source'            => $this->normalize_source( $file_info['source'] ?? 'upload' ),
			'summary'           => array(
				'file_name'     => $file_info['name'] ?? basename( $path ),
				'file_size'     => $this->file_size( $path ),
				'theme_count'   => count( $slugs ),
				'themes'        => $slugs,
				'existing_slugs'=> $existing,
			),
			'warnings'          => $warnings,
			'errors'            => array(),
			'estimated_changes' => array(
				'theme_slugs' => $slugs,
			),
			'next_step'         => __( 'Uncheck "Preflight only" and submit again; theme import will show its confirmation step.', 'mksddn-migrate-content' ),
		);
	}

	/**
	 * List theme slugs from a theme .wpbkp archive (read-only zip scan).
	 *
	 * @param string $path Archive path.
	 * @return array|WP_Error List of slugs.
	 */
	private function list_theme_slugs_from_archive( string $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'mksddn_mc_zip_open', __( 'Unable to open archive.', 'mksddn-migrate-content' ) );
		}

		$from_manifest = array();
		$raw_manifest  = $zip->getFromName( 'manifest.json' );
		if ( false !== $raw_manifest && '' !== $raw_manifest ) {
			$manifest = json_decode( $raw_manifest, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $manifest ) && isset( $manifest['themes'] ) && is_array( $manifest['themes'] ) ) {
				foreach ( $manifest['themes'] as $t ) {
					if ( is_string( $t ) && $t !== '' ) {
						$from_manifest[] = sanitize_file_name( $t );
					} elseif ( is_array( $t ) && isset( $t['slug'] ) ) {
						$from_manifest[] = sanitize_file_name( (string) $t['slug'] );
					}
				}
			}
		}

		$from_paths = array();
		$prefix     = self::THEME_ARCHIVE_PREFIX;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! $stat || empty( $stat['name'] ) ) {
				continue;
			}
			$name = $stat['name'];
			if ( 0 === strpos( $name, 'manifest' ) || 0 === strpos( $name, 'payload/' ) ) {
				continue;
			}
			$name = str_replace( '\\', '/', $name );
			if ( 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$relative = substr( $name, strlen( $prefix ) );
			$parts    = explode( '/', $relative, 2 );
			$slug     = isset( $parts[0] ) ? $parts[0] : '';
			if ( '' !== $slug && false === strpos( $slug, '..' ) ) {
				$from_paths[] = $slug;
			}
		}
		$zip->close();

		$merged = array_unique( array_merge( $from_manifest, $from_paths ) );
		$merged = array_values( array_filter( $merged ) );

		if ( empty( $merged ) ) {
			return new WP_Error( 'mksddn_mc_no_themes_in_archive', __( 'No themes found in archive.', 'mksddn-migrate-content' ) );
		}

		return $merged;
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
