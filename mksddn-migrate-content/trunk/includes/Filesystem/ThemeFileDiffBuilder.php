<?php
/**
 * @file: ThemeFileDiffBuilder.php
 * @description: Builds theme archive file inventory with add/overwrite/identical/unverified and replace-delete samples
 * @dependencies: Support\ThemeArchivePathHelper
 * @created: 2026-10-05
 */

namespace MksDdn\MigrateContent\Filesystem;

use MksDdn\MigrateContent\Support\ThemeArchivePathHelper;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use ZipArchive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares theme files in a .wpbkp archive against installed themes.
 *
 * @since 2.8.0
 */
class ThemeFileDiffBuilder {

	/**
	 * Max sample paths stored per action bucket per theme.
	 */
	private const SAMPLE_CAP = 40;

	/**
	 * Max file size eligible for md5 comparison (bytes).
	 */
	private const HASH_FILE_MAX = 262144; // 256 KiB.

	/**
	 * Max files hashed per archive.
	 */
	private const HASH_FILE_BUDGET = 200;

	/**
	 * Max total bytes read for hashing per archive.
	 */
	private const HASH_BYTE_BUDGET = 33554432; // 32 MiB.

	/**
	 * Max local files walked when estimating Replace deletes per theme.
	 *
	 * Caps filesystem work on oversized theme trees (e.g. accidental node_modules).
	 */
	private const REPLACE_DELETE_SCAN_CAP = 5000;

	/**
	 * Hash budget counters for the current build.
	 *
	 * @var int
	 */
	private int $hash_files_used = 0;

	/**
	 * @var int
	 */
	private int $hash_bytes_used = 0;

	/**
	 * Whether hash budget was exhausted.
	 *
	 * @var bool
	 */
	private bool $hash_budget_exhausted = false;

	/**
	 * Build per-theme file inventory.
	 *
	 * @param string $path Absolute archive path.
	 * @return array{slugs:string[],themes:array<int,array>,files_total:int,files_added:int,files_overwrite:int,files_identical:int,files_unverified:int,hash_budget_exhausted:bool,manifest:array}|WP_Error
	 */
	public function build( string $path ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'mksddn_mc_zip_open', __( 'Unable to open archive.', 'mksddn-migrate-content' ) );
		}

		$this->hash_files_used       = 0;
		$this->hash_bytes_used       = 0;
		$this->hash_budget_exhausted = false;

		$manifest      = $this->decode_manifest_from_zip( $zip );
		$from_manifest = $this->theme_slugs_from_manifest( $manifest );
		$theme_root    = trailingslashit( get_theme_root() );
		$prefix        = ThemeArchivePathHelper::ARCHIVE_PREFIX;
		$cap           = self::SAMPLE_CAP;

		/** @var array<string, array<string, mixed>> $buckets */
		$buckets = array();

		/** @var array<string, bool> $theme_dir_exists */
		$theme_dir_exists = array();

		/** @var array<string, array<string, bool>> $archive_paths_by_slug */
		$archive_paths_by_slug = array();

		foreach ( $from_manifest as $manifest_slug ) {
			$buckets[ $manifest_slug ] = $this->empty_bucket();
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
				$buckets[ $slug ] = $this->empty_bucket();
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

			if ( ! isset( $archive_paths_by_slug[ $slug ] ) ) {
				$archive_paths_by_slug[ $slug ] = array();
			}
			$archive_paths_by_slug[ $slug ][ $rel_in_theme ] = true;

			// New theme folder: all archive files are adds.
			if ( ! $theme_dir_exists[ $slug ] ) {
				$this->bump_sample( $buckets[ $slug ], 'added', $rel_in_theme, $cap );
				continue;
			}

			$dest = $theme_root . $slug . '/' . $rel_in_theme;
			if ( ! is_file( $dest ) ) {
				$this->bump_sample( $buckets[ $slug ], 'added', $rel_in_theme, $cap );
				continue;
			}

			$archive_size = isset( $stat['size'] ) ? (int) $stat['size'] : 0;
			$classification = $this->classify_overwrite(
				$zip,
				(int) $i,
				$dest,
				$rel_in_theme,
				$archive_size
			);

			if ( 'identical' === $classification ) {
				$this->bump_sample( $buckets[ $slug ], 'identical', $rel_in_theme, $cap );
				continue;
			}

			if ( 'size_match_unverified' === $classification ) {
				$this->bump_sample( $buckets[ $slug ], 'unverified', $rel_in_theme, $cap );
				continue;
			}

			// content_changed: size or md5 differs.
			$this->bump_sample( $buckets[ $slug ], 'overwrite', $rel_in_theme, $cap );
		}
		$zip->close();

		if ( empty( $buckets ) ) {
			return new WP_Error( 'mksddn_mc_no_themes_in_archive', __( 'No themes found in archive.', 'mksddn-migrate-content' ) );
		}

		$themes           = array();
		$files_total      = 0;
		$files_added      = 0;
		$files_overwrite  = 0;
		$files_identical  = 0;
		$files_unverified = 0;

		ksort( $buckets, SORT_STRING );
		foreach ( $buckets as $slug => $bucket ) {
			$theme_exists = is_dir( $theme_root . $slug ) || wp_get_theme( $slug )->exists();
			$file_count   = (int) $bucket['added'] + (int) $bucket['overwrite'] + (int) $bucket['identical'] + (int) $bucket['unverified'];
			$files_total += $file_count;
			$files_added += (int) $bucket['added'];
			$files_overwrite += (int) $bucket['overwrite'];
			$files_identical += (int) $bucket['identical'];
			$files_unverified += (int) $bucket['unverified'];

			$delete_info = array(
				'count'     => 0,
				'sample'    => array(),
				'truncated' => false,
			);
			if ( $theme_exists && is_dir( $theme_root . $slug ) ) {
				$delete_info = $this->collect_replace_deletes(
					$theme_root . $slug,
					$archive_paths_by_slug[ $slug ] ?? array(),
					$cap
				);
			}

			$themes[] = array(
				'slug'                            => $slug,
				'exists'                          => $theme_exists,
				'file_count'                      => $file_count,
				'added_count'                     => (int) $bucket['added'],
				'overwrite_count'                 => (int) $bucket['overwrite'],
				'identical_count'                 => (int) $bucket['identical'],
				'unverified_count'                => (int) $bucket['unverified'],
				'sample_added'                    => $bucket['sample_added'],
				'sample_overwrite'                => $bucket['sample_overwrite'],
				'sample_identical'                => $bucket['sample_identical'],
				'sample_unverified'               => $bucket['sample_unverified'],
				'sample_will_delete_on_replace'   => $delete_info['sample'],
				'will_delete_on_replace_count'    => $delete_info['count'],
				'samples_truncated_added'         => (bool) $bucket['truncated_added'],
				'samples_truncated_overwrite'     => (bool) $bucket['truncated_overwrite'],
				'samples_truncated_identical'     => (bool) $bucket['truncated_identical'],
				'samples_truncated_unverified'    => (bool) $bucket['truncated_unverified'],
				'samples_truncated_will_delete'   => $delete_info['truncated'],
			);
		}

		return array(
			'slugs'                  => array_keys( $buckets ),
			'themes'                 => $themes,
			'files_total'            => $files_total,
			'files_added'            => $files_added,
			'files_overwrite'        => $files_overwrite,
			'files_identical'        => $files_identical,
			'files_unverified'       => $files_unverified,
			'hash_budget_exhausted'  => $this->hash_budget_exhausted,
			'manifest'               => $manifest,
		);
	}

	/**
	 * Drop path samples so the inventory fits in a theme-preview transient.
	 *
	 * Counts and flags remain; the preflight report keeps full samples.
	 *
	 * @param array<int, array> $themes Theme inventory rows.
	 * @return array<int, array>
	 */
	public static function slim_for_preview_store( array $themes ): array {
		$out = array();
		foreach ( $themes as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'slug'                          => (string) ( $row['slug'] ?? '' ),
				'exists'                        => ! empty( $row['exists'] ),
				'file_count'                    => (int) ( $row['file_count'] ?? 0 ),
				'added_count'                   => (int) ( $row['added_count'] ?? 0 ),
				'overwrite_count'               => (int) ( $row['overwrite_count'] ?? 0 ),
				'identical_count'               => (int) ( $row['identical_count'] ?? 0 ),
				'unverified_count'              => (int) ( $row['unverified_count'] ?? 0 ),
				'will_delete_on_replace_count'  => (int) ( $row['will_delete_on_replace_count'] ?? 0 ),
				'sample_added'                  => array(),
				'sample_overwrite'              => array(),
				'sample_identical'              => array(),
				'sample_unverified'             => array(),
				'sample_will_delete_on_replace' => array(),
				'samples_truncated_added'       => ! empty( $row['samples_truncated_added'] ),
				'samples_truncated_overwrite'   => ! empty( $row['samples_truncated_overwrite'] ),
				'samples_truncated_identical'   => ! empty( $row['samples_truncated_identical'] ),
				'samples_truncated_unverified'  => ! empty( $row['samples_truncated_unverified'] ),
				'samples_truncated_will_delete' => ! empty( $row['samples_truncated_will_delete'] ),
				'counts_only'                   => true,
			);
		}
		return $out;
	}

	/**
	 * Empty per-theme counters/samples.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_bucket(): array {
		return array(
			'added'                 => 0,
			'overwrite'             => 0,
			'identical'             => 0,
			'unverified'            => 0,
			'sample_added'          => array(),
			'sample_overwrite'      => array(),
			'sample_identical'      => array(),
			'sample_unverified'     => array(),
			'truncated_added'       => false,
			'truncated_overwrite'   => false,
			'truncated_identical'   => false,
			'truncated_unverified'  => false,
		);
	}

	/**
	 * Increment counter and optionally store a sample path.
	 *
	 * @param array  $bucket Bucket by reference.
	 * @param string $action added|overwrite|identical|unverified.
	 * @param string $path   Relative path.
	 * @param int    $cap    Sample cap.
	 * @return void
	 */
	private function bump_sample( array &$bucket, string $action, string $path, int $cap ): void {
		++$bucket[ $action ];
		$sample_key    = 'sample_' . $action;
		$truncated_key = 'truncated_' . $action;
		if ( count( $bucket[ $sample_key ] ) < $cap ) {
			$bucket[ $sample_key ][] = $path;
		} else {
			$bucket[ $truncated_key ] = true;
		}
	}

	/**
	 * Classify an overwrite path: identical, content_changed, or size_match_unverified.
	 *
	 * @param ZipArchive $zip          Open archive.
	 * @param int        $index        Zip entry index.
	 * @param string     $local_path   Absolute local file path.
	 * @param string     $rel_in_theme Relative path inside theme.
	 * @param int        $archive_size Archive entry size.
	 * @return string
	 */
	private function classify_overwrite( ZipArchive $zip, int $index, string $local_path, string $rel_in_theme, int $archive_size ): string {
		if ( $this->is_acf_json_path( $rel_in_theme ) ) {
			$acf = $this->compare_acf_json( $zip, $index, $local_path, $archive_size );
			if ( null !== $acf ) {
				return $acf;
			}
			// Fall through to size/hash if JSON invalid / too large / budget exhausted.
		}

		$local_size = filesize( $local_path );
		$local_size = false !== $local_size ? (int) $local_size : -1;

		if ( $local_size < 0 || $archive_size !== $local_size ) {
			return 'content_changed';
		}

		if ( $archive_size > self::HASH_FILE_MAX ) {
			return 'size_match_unverified';
		}

		if ( $this->hash_budget_exhausted
			|| $this->hash_files_used >= self::HASH_FILE_BUDGET
			|| ( $this->hash_bytes_used + $archive_size ) > self::HASH_BYTE_BUDGET ) {
			$this->hash_budget_exhausted = true;
			return 'size_match_unverified';
		}

		$archive_contents = $zip->getFromIndex( $index );
		if ( false === $archive_contents ) {
			return 'size_match_unverified';
		}

		++$this->hash_files_used;
		$this->hash_bytes_used += strlen( $archive_contents );

		$archive_hash = md5( $archive_contents );
		$local_hash   = md5_file( $local_path );
		if ( ! is_string( $local_hash ) ) {
			return 'size_match_unverified';
		}

		return hash_equals( $archive_hash, $local_hash ) ? 'identical' : 'content_changed';
	}

	/**
	 * Whether path is an ACF JSON field-group file.
	 *
	 * @param string $rel_in_theme Relative path.
	 * @return bool
	 */
	private function is_acf_json_path( string $rel_in_theme ): bool {
		$normalized = str_replace( '\\', '/', $rel_in_theme );
		if ( 0 !== strpos( $normalized, 'acf-json/' ) ) {
			return false;
		}
		return (bool) preg_match( '/\.json$/i', $normalized );
	}

	/**
	 * Compare ACF JSON ignoring the volatile `modified` key.
	 *
	 * Large files and hash-budget exhaustion return null so the caller can
	 * fall through to size/hash classification without loading unbounded JSON.
	 *
	 * @param ZipArchive $zip          Open archive.
	 * @param int        $index        Entry index.
	 * @param string     $local_path   Local file.
	 * @param int        $archive_size Archive entry size in bytes.
	 * @return string|null identical|content_changed, or null if not comparable as JSON.
	 */
	private function compare_acf_json( ZipArchive $zip, int $index, string $local_path, int $archive_size ) {
		$local_size = filesize( $local_path );
		$local_size = false !== $local_size ? (int) $local_size : -1;

		if ( $archive_size > self::HASH_FILE_MAX || $local_size > self::HASH_FILE_MAX || $local_size < 0 ) {
			return null;
		}

		$bytes_needed = max( $archive_size, $local_size );
		if ( $this->hash_budget_exhausted
			|| $this->hash_files_used >= self::HASH_FILE_BUDGET
			|| ( $this->hash_bytes_used + $bytes_needed ) > self::HASH_BYTE_BUDGET ) {
			$this->hash_budget_exhausted = true;
			return null;
		}

		$archive_raw = $zip->getFromIndex( $index );
		$local_raw   = @file_get_contents( $local_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $archive_raw || false === $local_raw ) {
			return null;
		}

		++$this->hash_files_used;
		$this->hash_bytes_used += strlen( $archive_raw ) + strlen( $local_raw );

		$archive_data = json_decode( $archive_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $archive_data ) ) {
			return null;
		}

		$local_data = json_decode( $local_raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $local_data ) ) {
			return null;
		}

		unset( $archive_data['modified'], $local_data['modified'] );

		$archive_canon = wp_json_encode( $this->canonicalize( $archive_data ) );
		$local_canon   = wp_json_encode( $this->canonicalize( $local_data ) );
		if ( ! is_string( $archive_canon ) || ! is_string( $local_canon ) ) {
			return null;
		}

		return hash_equals( $archive_canon, $local_canon ) ? 'identical' : 'content_changed';
	}

	/**
	 * Collect local-only files that Replace mode would delete.
	 *
	 * @param string              $theme_dir Absolute theme directory.
	 * @param array<string,bool>  $archive_paths Relative paths present in archive.
	 * @param int                 $cap Sample cap.
	 * @return array{count:int,sample:string[],truncated:bool}
	 */
	private function collect_replace_deletes( string $theme_dir, array $archive_paths, int $cap ): array {
		$sample    = array();
		$count     = 0;
		$truncated = false;
		$scanned   = 0;
		$theme_dir = trailingslashit( $theme_dir );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $theme_dir, RecursiveDirectoryIterator::SKIP_DOTS )
			);
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return array(
				'count'     => 0,
				'sample'    => array(),
				'truncated' => false,
			);
		}

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			++$scanned;
			if ( $scanned > self::REPLACE_DELETE_SCAN_CAP ) {
				$truncated = true;
				break;
			}

			$absolute = $file_info->getPathname();
			$relative = ltrim( str_replace( '\\', '/', substr( $absolute, strlen( $theme_dir ) ) ), '/' );
			if ( '' === $relative ) {
				continue;
			}
			if ( isset( $archive_paths[ $relative ] ) ) {
				continue;
			}
			++$count;
			if ( count( $sample ) < $cap ) {
				$sample[] = $relative;
			} else {
				$truncated = true;
			}
		}

		return array(
			'count'     => $count,
			'sample'    => $sample,
			'truncated' => $truncated,
		);
	}

	/**
	 * Sort keys recursively for stable JSON compare.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	private function canonicalize( array $data ): array {
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->canonicalize( $v );
			}
		}
		ksort( $data );
		return $data;
	}

	/**
	 * Decode manifest.json from an open ZipArchive.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return array
	 */
	private function decode_manifest_from_zip( ZipArchive $zip ): array {
		$raw_manifest = $zip->getFromName( 'manifest.json' );
		if ( false === $raw_manifest || '' === $raw_manifest ) {
			return array();
		}

		$manifest = json_decode( $raw_manifest, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $manifest ) ) {
			return array();
		}

		return $manifest;
	}

	/**
	 * Extract theme slugs from a decoded manifest.
	 *
	 * @param array $manifest Decoded manifest.
	 * @return string[]
	 */
	private function theme_slugs_from_manifest( array $manifest ): array {
		$from_manifest = array();
		if ( ! isset( $manifest['themes'] ) || ! is_array( $manifest['themes'] ) ) {
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
}
